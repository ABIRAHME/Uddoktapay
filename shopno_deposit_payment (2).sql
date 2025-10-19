-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Oct 11, 2025 at 05:34 PM
-- Server version: 5.7.34
-- PHP Version: 8.2.6

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `shopnocari`
--

-- --------------------------------------------------------

--
-- Table structure for table `shopno_deposit_payment`
--

CREATE TABLE `shopno_deposit_payment` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `deposit_id` int(11) DEFAULT NULL,
  `payment_type` enum('first_deposit','monthly_deposit') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'first_deposit',
  `payment_method` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'bkash',
  `transaction_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bkash_payment_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uddoktapay_invoice_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `individual_deposits` json DEFAULT NULL,
  `payment_status` enum('pending','processing','completed','failed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `payment_date` datetime DEFAULT NULL,
  `bkash_response` json DEFAULT NULL,
  `uddoktapay_response` json DEFAULT NULL,
  `failure_reason` text COLLATE utf8mb4_unicode_ci,
  `month_year` varchar(7) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `shopno_deposit_payment`
--

INSERT INTO `shopno_deposit_payment` (`id`, `user_id`, `deposit_id`, `payment_type`, `payment_method`, `transaction_id`, `bkash_payment_id`, `uddoktapay_invoice_id`, `total_amount`, `individual_deposits`, `payment_status`, `payment_date`, `bkash_response`, `uddoktapay_response`, `failure_reason`, `month_year`, `created_at`, `updated_at`) VALUES
(1, 6, 1, 'first_deposit', 'uddoktapay', NULL, '0', '0', 1000.00, '[{\"amount\": \"1000.00\", \"deposit_id\": 1, \"member_name\": \"Ahmed Tanvir\"}]', 'processing', NULL, '{\"status\": true, \"message\": \"Payment Initiated\", \"payment_url\": \"https://shopnocari.paymently.io/checkout/gDwKIapGmXYHrlt69bwGJJ4aQvNan2WyvuFjRIuq\"}', NULL, NULL, '2025-10', '2025-10-11 15:29:48', '2025-10-11 15:29:48');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `shopno_deposit_payment`
--
ALTER TABLE `shopno_deposit_payment`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_monthly_payment` (`user_id`,`month_year`,`payment_type`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `deposit_id` (`deposit_id`),
  ADD KEY `transaction_id` (`transaction_id`),
  ADD KEY `payment_status` (`payment_status`),
  ADD KEY `payment_type` (`payment_type`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `shopno_deposit_payment`
--
ALTER TABLE `shopno_deposit_payment`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `shopno_deposit_payment`
--
ALTER TABLE `shopno_deposit_payment`
  ADD CONSTRAINT `fk_deposit_payment_deposit` FOREIGN KEY (`deposit_id`) REFERENCES `shopno_deposit` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_deposit_payment_user` FOREIGN KEY (`user_id`) REFERENCES `shopno_users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
