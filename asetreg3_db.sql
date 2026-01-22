-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 20, 2026 at 04:45 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

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
(9, 'Dasboard', 'dasbor', 1),
(12, 'Import DAT', 'import_dat', 26);

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
(10, '1234567890', 9),
(11, '1234567890', 8),
(12, '1234567890', 12);

-- --------------------------------------------------------

--
-- Table structure for table `import_dat`
--

CREATE TABLE `import_dat` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `nomor_asset_utama` VARCHAR(50),
    `profit_center` VARCHAR(20),
    `profit_center_text` VARCHAR(100),
    `cost_center_baru` VARCHAR(20),
    `deskripsi_cost_center` VARCHAR(200),
    `nama_cabang_kawasan` VARCHAR(100),
    `kode_plant` VARCHAR(20),
    `periode_bulan` VARCHAR(20),
    `tahun_buku` VARCHAR(4),
    `nomor_asset_asal` VARCHAR(50),
    `nomor_asset` VARCHAR(50),
    `sub_number` VARCHAR(20),
    `gl_account` VARCHAR(20),
    `asset_class` VARCHAR(20),
    `asset_class_name` VARCHAR(100),
    `kelompok_aset` VARCHAR(200),
    `status_aset` VARCHAR(100),
    `asset_main_no_text` VARCHAR(100),
    `akuisisi` VARCHAR(20),
    `keterangan_asset` TEXT,
    `tgl_akuisisi` VARCHAR(20),
    `tgl_perolehan` VARCHAR(20),
    `tgl_penyusutan` VARCHAR(20),
    `masa_manfaat` VARCHAR(20),
    `sisa_manfaat` VARCHAR(20),
    `nilai_perolehan_awal` VARCHAR(50),
    `nilai_residu_persen` VARCHAR(20),
    `nilai_residu_rp` VARCHAR(50),
    `nilai_perolehan_sd` VARCHAR(50),
    `adjusment_nilai_perolehan` VARCHAR(50),
    `nilai_buku_awal` VARCHAR(50),
    `nilai_buku_sd` VARCHAR(50),
    `penyusutan_bulan` VARCHAR(50),
    `penyusutan_sd` VARCHAR(50),
    `penyusutan_tahun_lalu` VARCHAR(50),
    `penyusutan_tahun` VARCHAR(50),
    `akm_penyusutan_tahun_lalu` VARCHAR(50),
    `adjusment_akm_penyusutan` VARCHAR(50),
    `penghapusan` VARCHAR(20),
    `asset_shutdown` VARCHAR(20),
    `akumulasi_penyusutan` VARCHAR(200),
    `additional_description` VARCHAR(200),
    `serial_number` VARCHAR(25),
    `alamat` VARCHAR(500),
    `gl_account_exp` VARCHAR(25),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `imported_by` VARCHAR(20),
    UNIQUE KEY `uk_nomor_asset` (`nomor_asset`),
    KEY `idx_profit_center` (`profit_center`),
    KEY `idx_tahun_buku` (`tahun_buku`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_cost_center` (`cost_center_baru`),
    KEY `idx_imported_by` (`imported_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
  MODIFY `id_menu` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `user_access`
--
ALTER TABLE `user_access`
  MODIFY `id_access` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

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
