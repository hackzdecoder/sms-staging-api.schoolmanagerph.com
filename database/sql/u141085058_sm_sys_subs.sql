-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Jan 16, 2026 at 10:13 PM
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
-- Database: `u141085058_sm_sys_subs`
--

-- --------------------------------------------------------

--
-- Table structure for table `client_subscription`
--

CREATE TABLE `client_subscription` (
  `id` bigint(20) NOT NULL,
  `school_code` varchar(20) DEFAULT NULL,
  `system_type` enum('es','gs','ids','') DEFAULT NULL,
  `license_status` enum('valid','expired','revoked','') DEFAULT '',
  `license_validity_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `client_subscription`
--

INSERT INTO `client_subscription` (`id`, `school_code`, `system_type`, `license_status`, `license_validity_date`) VALUES
(1, 'ATHENEUM', 'ids', 'valid', '2026-05-31'),
(2, 'ATHENEUM', 'es', 'valid', '2026-05-31'),
(3, 'GVA', 'ids', 'valid', '2026-05-31'),
(4, 'GVA', 'es', 'valid', '2026-05-31'),
(5, 'NSCI', 'ids', 'valid', '2026-05-31'),
(6, 'NSCI', 'es', 'valid', '2026-05-31'),
(7, 'SCA', 'ids', 'valid', '2026-05-31'),
(8, 'SCA', 'es', 'valid', '2026-05-31'),
(9, 'SSHS', 'ids', 'valid', '2026-05-31'),
(10, 'SSHS', 'es', 'valid', '2026-05-31'),
(11, 'SVA', 'ids', 'valid', '2026-05-31'),
(12, 'SVA', 'es', 'valid', '2026-05-31'),
(13, 'VISION', 'ids', 'valid', '2026-05-31'),
(14, 'VISION', 'es', 'valid', '2026-05-31'),
(15, 'TRIAL', 'ids', 'valid', '2026-05-31');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `client_subscription`
--
ALTER TABLE `client_subscription`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `client_subscription`
--
ALTER TABLE `client_subscription`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
