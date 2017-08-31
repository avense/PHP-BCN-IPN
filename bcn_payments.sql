-- phpMyAdmin SQL Dump
-- version 4.7.0
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 01, 2017 at 01:03 AM
-- Server version: 10.1.25-MariaDB
-- PHP Version: 5.6.31

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `bcn_payments`
--

-- --------------------------------------------------------

--
-- Table structure for table `bcn_payments`
--

CREATE TABLE `bcn_payments` (
  `id` int(9) NOT NULL,
  `type` enum('receive','transfer') NOT NULL,
  `address` char(95) DEFAULT NULL,
  `payment_id` varchar(64) NOT NULL,
  `amount` bigint(20) NOT NULL,
  `added` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expire` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `status` enum('pending','complete') NOT NULL,
  `block_height` bigint(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Dumping data for table `bcn_payments`
--

INSERT INTO `bcn_payments` (`id`, `type`, `address`, `payment_id`, `amount`, `added`, `expire`, `status`, `block_height`) VALUES
(45, 'receive', NULL, '27ab89c1e75634c39f62a39967313dbfc962e37c6c6435f1812e2aebca0cc340', 0, '2017-08-31 22:59:57', '2017-09-01 23:59:57', 'pending', 0),
(46, 'transfer', '232QDk52yCPEaZSQZ6zhKXVChLNTuixPmfCMEHriBtNid8xegrQeCMtEV8M6Veci4kHZATsaVX99CSH9NyJCLqVoCRvA9mM', '', 1, '2017-08-31 22:59:57', '0000-00-00 00:00:00', 'pending', 0);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `bcn_payments`
--
ALTER TABLE `bcn_payments`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `bcn_payments`
--
ALTER TABLE `bcn_payments`
  MODIFY `id` int(9) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=47;COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
