<?php
/**
 * PHP-BCN IPN
 *
 * Provides a way for PHP developers to send
 * commands to a Bytecoin wallet RPC server.
 *
 *
 */



/**
 * PHP-Bytecoin API
 *
 * API object which allows interface with a
 * Bytecoin RPC server.  Configuration may be set
 * in the class itself or as arguments to the
 * constructor.
 *
 */

class PHP_Bytecoin
{

    /** @var string Human readable time until payment expires. Must be compatible with strtotime() */
    private $expire_payments    = '+1 day';

    /** @var string IP:Port of the wallet daemon.  Usually 127.0.0.1:18082 */
    private $rpc_address        = '45.79.1.38:8050';

    /** @var string Full address of the Bytecoin wallet. */
    public $wallet_address     =  '232QDk52yCPEaZSQZ6zhKXVChLNTuixPmfCMEHriBtNid8xegrQeCMtEV8M6Veci4kHZATsaVX99CSH9NyJCLqVoCRvA9mM'; //'/4([0-9]|[A-B])(.){93}/';    // wallet full address

    /** @var string Bytecoin wallet OpenAlias address */
    private $open_alias         = "bytecoin.org";

    /** @var object Instance of MySQLi class. */
    private $mysqli;

    /** @var string Username for Database */
    private $database_username         = "root";

    /** @var string Password for Database */
    private $database_password         = "";

    /** @var string Host for Database */
    private $database_host         = "localhost";

    /** @var string Name for Database */
    private $database_name         = "bcn_payments";

    /**
     * @param object $mysqli An instance of the MySQLi class
     * @param string $expire strtotime() compatible string describing expiration date of payment
     * @param string $rpc_address IP:Port of the wallet daemon
     * @param string $wallet_address Full address of the Bytecoin wallet
     * @param string $wallet_alias OpenAlias of the Bytecoin wallet
     * @return bool|self Returns false if configuration error, self if success
     * @throws \Exception
     * @uses \PHP-Bytecoin\Bytecoin::validate_address()
     */

    public function __construct() {

        // Make database available to other methods
        $this->mysqli = new mysqli($this->database_host, $this->database_username, $this->database_password, $this->database_name);

        // Validate configuration
        if (!strtotime($this->expire_payments)) {
            die('Payment expiration configuration value is invalid.');
        }
        if (!preg_match (
            '/((0|1[0-9]{0,2}|2[0-9]?|2[0-4][0-9]|25[0-5]|[3-9][0-9]?)\.){3}(0|1[0-9]{0,2}|2[0-9]?|2[0-4][0-9]|25[0-5]|[3-9][0-9]?):([0-9]{1,5})/',
            $this->rpc_address
            )) {
            die('RPC address configuration value is invalid.');
        }
        if (!$this->validate_address($this->wallet_address)) {
            die('Wallet address configuration value is invalid.');
        }

        return true;
    }

    /**
     * Create a new Bytecoin payment ID
     *
     * Generates a new 32 character Bytecoin payment ID and then
     * queries the database to make sure it is unique.  Uses
     * recursion until a unique ID has ben generated.
     *
     * @return bool|string Returns false if failure or a 32 character string if successful
     * @throws \Exception
     */
    public function create_payment_id()
    {
        // Generate an ID
        $payment_id = bin2hex(openssl_random_pseudo_bytes(32));

        // Check the table for duplicates
        if ($check = $this->mysqli->prepare('SELECT `id` FROM `bcn_payments` WHERE `payment_id` = ?')) {
            $check->bind_param('s', $payment_id);
            $check->execute();
            $check->store_result();

            // Recursion if not unique
            if ($check->num_rows > 0) {
                $check->free_result();
                $this->create_payment_id();
            } else {
                $check->free_result();
                return $payment_id;
            }

            // Unknown error, this code should never be executed
            die('Unknown error in PHP_Bytecoin::create_payment_id()');

        } else {
            // Database failure
            die('Could not query the BCN payments table.');
        }

        // Unknown error, this code should never be executed
        die('Unknown error in PHP_Bytecoin::create_payment_id()');
    }

    /**
     * Create a new payment address
     *
     * Generate a new payment ID and then insert it into the database
     *
     * @param int $BCN The amount in Bytecoin to be paid
     * @return bool|array False on failure, an array containing payment information on success
     * @throws \Exception
     * @uses \PHP-Bytecoin\Bytecoin::create_payment_id()
     */
    public function create_payment_address($bcn = '0.0')
    {
        $amount =  ($bcn);
        $amount =  (int) $amount;

        // Generate the values
        $payment_id = $this->create_payment_id();
        $expire = date('Y-m-d H:i:s', strtotime($this->expire_payments));

        // Definitely want atomicity here.
        $this->mysqli->begin_transaction();
        if ($stmt = $this->mysqli->prepare('
                                INSERT INTO bcn_payments (type, payment_id, amount, status, expire)
                                VALUES (\'receive\', ?,?,\'pending\', ?)'
                                )
        ) {
            $stmt->bind_param('sis', $payment_id, $amount, $expire);
            $stmt->execute();

            // Commit and return on success
            if ($stmt->affected_rows == 1) {
                $stmt->close();
                $this->mysqli->commit();

                return array (
                    'status'        => 'pending',
                    'payment_id'    => $payment_id,
                    'type'          => 'receive',
                    'added'         => date('Y-m-d H:i:s'),
                    'address'       => $this->wallet_address,
                    'openalias'     => $this->open_alias
                );
            } else {
                // Some kind of insert failure
                // Rollback and throw exception
                $stmt->close();
                $this->mysqli->rollback();
                die('Failed to create a Bytecoin payment address');

            }
        } else {
            // Database error
            $this->mysqli->rollback();
            die('Could not query the BCN payments table.');

        }

        // Unknown error, this code should never execute
        die('Unknown error');
    }


    /**
     * Send a transfer to the database
     *
     * @param string $address The Bytecoin wallet address to tranfer to
     * @param float $BCN Amount of Bytecoin to transfer
     * @return bool|array False on error, array containing transfer information on success
     * @throws \Exception
     * @uses \PHP-Bytecoin\Bytecoin::validate_address()
     */
    public function transfer($address, $bcn = 0.0)
    {
        // Make sure we aren't sending to a bogus address
        if (!$this->validate_address($address)) {
            die('Tried to transfer Bytecoin to an invalid wallet address.');
        }

        // Wallet only supports integers, so we must
        // calculate by $BCN * 10^12 and then round down
        // any remaining decimals to the nearest integer.
        $amount = $bcn;
        $amount = (int) $amount;

        // Make sure we are actually transferring something
        if ($amount == 0) {
            die('Tried to transfer 0 Bytecoin.');
        }

        // Anything involving value should be a transaction
        $this->mysqli->begin_transaction();
        if ($stmt = $this->mysqli->prepare('INSERT INTO `bcn_payments` (`type`, `address`, `amount`, `status`)
                                            VALUES (\'transfer\', ?, ?, \'pending\')')
        ) {
            $stmt->bind_param('si', $address, $amount);
            $stmt->execute();

            // Check result & return
            if ($stmt->affected_rows == 1) {
                // Success, commit, etc
                $stmt->close();
                $this->mysqli->commit();

                return array (
                    'type'      =>  'transfer',
                    'address'   =>  $address,
                    'amount'    =>  $amount,
                    'status'    =>  'pending'
                );
            } else {
                // Insertion error
                $stmt->close();
                $this->mysqli->rollback();
                die('Error commiting Bytecoin transfer to database.');
            }
            // This code should never execute
            die('Unknown PHP-Bytecoin::transfer() error');
        }
        // This code should never execute
        die('Unknown PHP-Bytecoin::transfer() error');
    }


    /**
     * Receive pending payments
     *
     * Queries the database for pending payments and then
     * queries the wallet via RPC to determine which
     * payments have been received, updating the database
     * as it goes.
     *
     * @return bool True on success false on failure
     * @throws \Exception
     */
    public function client_receive()
    {
        // Convert UNIX timestamp to SQL timestamp
        $now = date('Y-m-d H:i:s');

        // Receive all pending payment IDs
        if ($pending = $this->mysqli->prepare('SELECT `payment_id` FROM `bcn_payments`
                                                WHERE `status` = \'pending\' AND `type` = \'receive\' AND `expire` > ?')
        ) {
            $pending->bind_param('s', $now);
            $pending->execute();
            $pending->store_result();

            // Do we even have any pending payments to receive?
            if ($pending->num_rows > 0) {
                // Initalize the cURL request
                $ch = curl_init();
                $data = array(
                            'jsonrpc'       => '2.0',
                            'method'        => 'get_bulk_payments',
                            'id'            => 'phpBytecoin',
                            'params'        => array(
                                                'payment_ids'   => array()
                                               )
                );

                // Loop through
                $pending->bind_result($payment_id);
                while ($pending->fetch()) {
                    array_push($data['params']['payment_ids'], $payment_id);
                }

                // Clean up database request
                $pending->free_result();
                $pending->close();

                // Set up the cURL RPC request
                curl_setopt($ch, CURLOPT_URL, 'http://' . $this->rpc_address . '/json_rpc');
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_ENCODING, 'Content-Type: application/json');
                curl_setopt($ch, CURLOPT_POSTFIELDS,json_encode($data));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                // Get the RPC response
                $server_output = curl_exec ($ch);
                $result = json_decode($server_output,true);

                // Build a usable array
                $payments = array();
                usort($result["result"]["payments"], build_sorter('block_height'));

                /**
                 * Loop through and check/update database
                 * Thank god for prepared statements I guess
                 * @TODO optimize this better
                 */

                // Prepare the SELECT statement or fail
                if (!$check = $this->mysqli->prepare('SELECT `block_height` FROM `bcn_payments` WHERE `payment_id` = ?')) {
                    die('Could not prepare the database to confirm Bytecoin client payment receipts.');

                }

                // Prepare the UPDATE statement or fail
                if (!$update = $this->mysqli->prepare('UPDATE `bcn_payments` SET `amount` = amount + ?, `block_height` = ?
                                                        WHERE `payment_id` = ?')
                ) {
                    die('Could not prepare the database to update Bytecoin payment receipts.');

                }

                // Transaction for atomicity and optimization.
                $this->mysqli->begin_transaction();

                // And lets loop
                foreach ($result["result"]["payments"] as $index => $val) {
                    array_push($payments, array(
                                            'block_height'      =>  $val['block_height'],
                                            'payment_id'        =>  $val['payment_id'],
                                            'unlock_time'       =>  $val['unlock_time'],
                                            'amount'            =>  $val['amount'],
                                            'tx_hash'           =>  $val['tx_hash']
                    ));

                    // Query this ID
                    $check->bind_param('s', $val['payment_id']);
                    $check->execute();
                    $check->store_result();

                    // If we have a result, check it
                    if ($check->num_rows == 1) {
                        $check->bind_result($block_height);
                        $check->fetch();

                        // If database out of sync, sync it
                        if ($block_height < $val['block_height']) {
                            $update->bind_param('dis', $val['amount'], $val['block_height'], $val['payment_id']);
                            $update->execute();

                            // Make sure it succeeded
                            if ($update->affected_rows != 1) {
                                $update->close();
                                $this->mysqli->rollback();
                                die('Could not sync database and client.');

                            } elseif ($update->affected_rows > 1) {
                                $update->close();
                                $this->mysqli->rollback();
                                die('Multiple rows with same payment id.');

                            }

                            // Prepare for next loop
                            $update->reset();
                        }
                    } elseif ($check->num_rows > 1) {
                        $check->close();
                        $this->mysqli->rollback();
                        die('Multiple rows with same payment id.');
                        return $payments;
                    }

                    // Get ready for the next loop
                    $check->free_result();
                    $check->reset();
                }

                // If we got here, everything went fine.  Commit & clean up
                $check->close();
                $update->close();
                $this->mysqli->commit();
                curl_close($ch);
                return true;
            }

            // Nothing to do
            return true;
        }

        // If we get here then we couldn't query the database
        die('Tried to sync Bytecoin client and database but couldn\'t query the database.');

    }

    /**
     * Sync transfers between database and client
     */
    public function client_transfer($mixin=3)
    {
        // Make sure we have a sane mixin
        if ($mixin < 3) {
            die('Protocol requires mixin > 3');

        }

        $return = array();

        if ($pending = $this->mysqli->prepare('SELECT `address`, `amount`, `id`
                                                FROM `bcn_payments`
                                                WHERE `status` = \'pending\' AND `type` = \'transfer\'')
        ) {
            $pending->execute();
            $pending->store_result();

            // Is there anything to do?
            if ($pending->num_rows > 0) {
                $payment_id = $this->create_payment_id();

                $ch = curl_init();
                $data = array (
                            'jsonrpc'           => '2.0',
                            'method'            => 'transfer',
                            'id'                => 'phpBytecoin',
                            'params'            => array('destinations'     => array(),
                            'payment_id'        =>  $payment_id,
                            'mixin'             =>  $mixin,
                            'unlock_time'       =>  0
                            )
                );
                $return["payment_ids"]  = array();

                // Array magic
                $pending->bind_result($address, $amount, $id);
                while ($pending->fetch()) {
                    array_push(
                        $data['params']['destinations'],
                        array(
                            'amount'    =>  0 + $amount,
                            'address'   =>  $address
                            )
                    );
                    $return['payment_ids'][]    =   $id;
                }

                // Send it to the RPC
                curl_setopt($ch, CURLOPT_URL, 'http://' . $this->wallet_address . '/json_rpc');
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_ENCODING, 'Content-Type: application/json');
                curl_setopt($ch, CURLOPT_POSTFIELDS,json_encode($data));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $server_output = curl_exec ($ch);

                if (curl_error($ch)) {
                    die('Error sending Bytecoin transfers to the RPC server.');

                }

                $this->mysqli->begin_transaction();
                if ($update = $this->mysqli->prepare('UPDATE `bcn_payments`
                                                        SET `payment_id` = ?, status = \'complete\'
                                                        WHERE `id` = ?')
                ) {

                    // Sync the database
                    foreach ($return['payment_ids'] as $index => $val) {
                        $update->bind_param('ss', $payment_id, $val);
                        $update->execute();

                        if ($update->affected_rows != 1) {
                            $update->close();
                            $this->mysqli->rollback();
                            die('Error updating database after sending transfers to RPC.');

                        }

                        // Prepare for next loop
                        $update->reset();
                    }

                    // Success
                    $update->close();
                    $pending->free_result();
                    $pending->close();
                    $this->mysqli->commit();
                    $return["payment_id"]   = $payment_id;
                    $return["status"]       = 'complete';
                    return $return;
                }

                // Above code should return, if we are here
                // it is an error
                $this->mysqli->rollback();
                die('Could not prepare query to sync database and RPC.');

            }

            // If we're here it was a db error
            die('Could not query the database to get new transfers.');

        }
    }

    private function build_sorter($key) {
        return function ($a, $b) use ($key) {
            return strnatcmp($a[$key], $b[$key]);
        };
    }

    /**
     * Validates a Bytecoin wallet address
     *
     * Always starts with 4
     * Second character is always between 0-9 or A or B
     * Always 95 characters
     *
     * @param string $address The Bytecoin wallet address to validate
     * @return bool True if a valid address, false if not
     */
    public function validate_address( $address)
    {
        if (
            substr($address, 0) != '4' ||
            !preg_match('/([0-9]|[A-B])/', substr($address, 1)) ||
            strlen($address) != 95
        ) {

        }

        return true;
    }

}
