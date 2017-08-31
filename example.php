<?php
/**
 * Example code
 *
 * IPN File
 */

require_once 'includes/Bytecoin.php';

$BytecoinIPN = new PHP_Bytecoin();

$payment_address = $BytecoinIPN->create_payment_address();


if ($payment_address)
{
	echo "Payment ID: $payment_address[payment_id]";
	echo '<br />';
}
  else
{
	echo 'Handle the error. <br />';
}

/** This will send a transfer for x amount of BCN to the database for later processing */

if (empty($_GET['amount']))
{
	$amount = "1";
}
  else
{
	$amount = $_GET['amount'];
}

$transfer = $BytecoinIPN->transfer($BytecoinIPN->wallet_address, $amount);

if (!$transfer)
{
	echo 'Didn\'t think so. "Address" is not a valid address :P <br />';
}
else
{
	echo ("Send " . $transfer["amount"]) . " BCN to this $transfer[address] <br />";
}

/* The below methods should be added to your Cron Jobs
$BytecoinIPN->client_receive(); // procceing receipts
$BytecoinIPN->client_transfer();	// processing transfers

*/
