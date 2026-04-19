-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Jan 16, 2026 at 10:14 PM
-- Server version: 11.8.3-MariaDB-log
-- PHP Version: 7.2.34

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `u141085058_sm_sys_license`
--

-- --------------------------------------------------------

--
-- Table structure for table `client_licenses`
--

CREATE TABLE `client_licenses` (
  `id` bigint(20) NOT NULL,
  `school_code` varchar(20) DEFAULT NULL,
  `system_type` enum('es','gs','ids','') DEFAULT '',
  `system_version` varchar(20) DEFAULT NULL,
  `system_mode` enum('server','client','','') NOT NULL DEFAULT '',
  `client_mode_id` varchar(20) DEFAULT NULL,
  `license_status` enum('activated','demo','suspended','') NOT NULL DEFAULT '',
  `license_activation_date` date DEFAULT NULL,
  `license_reactivation_count` int(4) DEFAULT NULL,
  `demo_expiry_date` date DEFAULT NULL,
  `hwid_number` varchar(20) DEFAULT NULL,
  `hwid_string` varchar(300) DEFAULT NULL,
  `last_successful_use` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `client_licenses`
--

INSERT INTO `client_licenses` (`id`, `school_code`, `system_type`, `system_version`, `system_mode`, `client_mode_id`, `license_status`, `license_activation_date`, `license_reactivation_count`, `demo_expiry_date`, `hwid_number`, `hwid_string`, `last_successful_use`) VALUES
(1, 'CSI', 'gs', NULL, 'server', NULL, 'activated', NULL, NULL, NULL, NULL, NULL, NULL),
(2, 'CSI', 'gs', NULL, 'client', '1', 'activated', NULL, NULL, NULL, NULL, NULL, NULL),
(3, 'FPA', 'es', NULL, 'server', NULL, 'activated', NULL, NULL, NULL, NULL, NULL, NULL),
(4, 'GVA', 'es', NULL, 'server', NULL, 'activated', NULL, NULL, NULL, NULL, NULL, NULL),
(5, 'MOREH', 'es', NULL, 'server', NULL, 'activated', NULL, NULL, NULL, NULL, NULL, NULL),
(6, 'MOREH', 'es', NULL, 'client', '1', 'activated', NULL, NULL, NULL, NULL, NULL, NULL),
(7, 'MOREH', 'es', NULL, 'client', '2', 'activated', NULL, NULL, NULL, NULL, NULL, NULL),
(8, 'MOREH', 'es', NULL, 'client', '3', 'activated', NULL, NULL, NULL, NULL, NULL, NULL),
(9, 'SCA', 'es', NULL, 'server', NULL, 'activated', NULL, NULL, NULL, NULL, NULL, NULL),
(10, 'SCA', 'es', NULL, 'client', '1', 'activated', NULL, NULL, NULL, NULL, NULL, NULL),
(11, 'SSHS', 'es', NULL, 'client', '1', 'activated', NULL, NULL, NULL, NULL, NULL, NULL),
(12, 'SSHS', 'es', NULL, 'client', '2', 'activated', NULL, NULL, NULL, NULL, NULL, NULL),
(13, 'SSHS', 'es', NULL, 'client', '3', 'activated', NULL, NULL, NULL, NULL, NULL, NULL),
(14, 'SSHS', 'es', NULL, 'client', '4', 'activated', NULL, NULL, NULL, NULL, NULL, NULL),
(15, 'SSHS', 'es', NULL, 'client', '5', 'activated', NULL, NULL, NULL, NULL, NULL, NULL),
(16, 'SSHS', 'es', NULL, 'client', '6', 'activated', NULL, NULL, NULL, NULL, NULL, NULL),
(17, 'SSHS', 'es', NULL, 'client', '7', 'activated', NULL, NULL, NULL, NULL, NULL, NULL),
(18, 'SSHS', 'es', NULL, 'client', '8', 'activated', NULL, NULL, NULL, NULL, NULL, NULL),
(19, 'SVA', 'es', NULL, 'server', NULL, 'activated', NULL, NULL, NULL, NULL, NULL, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `client_licenses`
--
ALTER TABLE `client_licenses`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `client_licenses`
--
ALTER TABLE `client_licenses`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
