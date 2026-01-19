-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 19, 2026 at 03:44 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `asetreg3_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `menus`
--

CREATE TABLE `menus` (
  `id_menu` int(11) NOT NULL,
  `nama_menu` varchar(100) NOT NULL,
  `menu` varchar(100) NOT NULL,
  `urutan_menu` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `menus`
--

INSERT INTO `menus` (`id_menu`, `nama_menu`, `menu`, `urutan_menu`) VALUES
(3, 'Usulan Penghapusan', 'usulan_penghapusan_aset', 2),
(4, 'Approval SubReg', 'approval_subreg', 3),
(5, 'Approval Regional ', 'approval_regional', 4),
(6, 'Persetujuan Penghapusan', 'persetujuan_penghapusan', 5),
(7, 'Pelaksanaan Penghapusan', 'pelaksanaan_penghapusan', 6),
(8, 'Manajemen Menu', 'manajemen_menu', 25),
(9, 'Dasboard', 'dasbor', 1);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `NIPP` varchar(50) NOT NULL,
  `Nama` varchar(100) NOT NULL,
  `Email` varchar(100) NOT NULL,
  `Jabatan` varchar(100) DEFAULT NULL,
  `Password` varchar(255) NOT NULL,
  `Status` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`NIPP`, `Nama`, `Email`, `Jabatan`, `Password`, `Status`) VALUES
('123456', 'pandu', 'pandu@gmail.com', 'staff', '123456', 1),
('1234567890', 'administrator', 'admin@admin.com', 'admin', 'passadmin', 1);

-- --------------------------------------------------------

--
-- Table structure for table `user_access`
--

CREATE TABLE `user_access` (
  `id_access` int(11) NOT NULL,
  `NIPP` varchar(50) DEFAULT NULL,
  `id_menu` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_access`
--

INSERT INTO `user_access` (`id_access`, `NIPP`, `id_menu`) VALUES
(1, '1234567890', 3),
(2, '1234567890', 4),
(3, '1234567890', 5),
(4, '1234567890', 6),
(5, '1234567890', 7),
(6, '123456', 4),
(7, '123456', 5),
(9, '1234567890', 8),
(10, '1234567890', 9);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `menus`
--
ALTER TABLE `menus`
  ADD PRIMARY KEY (`id_menu`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`NIPP`);

--
-- Indexes for table `user_access`
--
ALTER TABLE `user_access`
  ADD PRIMARY KEY (`id_access`),
  ADD KEY `NIPP` (`NIPP`),
  ADD KEY `menu_id` (`id_menu`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `menus`
--
ALTER TABLE `menus`
  MODIFY `id_menu` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `user_access`
--
ALTER TABLE `user_access`
  MODIFY `id_access` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `user_access`
--
ALTER TABLE `user_access`
  ADD CONSTRAINT `user_access_ibfk_1` FOREIGN KEY (`NIPP`) REFERENCES `users` (`NIPP`),
  ADD CONSTRAINT `user_access_ibfk_2` FOREIGN KEY (`id_menu`) REFERENCES `menus` (`id_menu`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
