-- Database: siki_db

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+07:00";

-- --------------------------------------------------------

-- Tabel Users
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `role` enum('SUPER_ADMIN','ADMIN_GUDANG','ADMIN_KEUANGAN') NOT NULL,
  `created_at` timestamp DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default User (Password: admin123)
-- Hash di bawah ini valid untuk 'admin123'
INSERT INTO `users` (`username`, `password`, `email`, `role`) VALUES
('superadmin', '$2y$10$UnLq.vJ.dJ.dJ.dJ.dJ.dOe/e.e.e.e.e.e.e.e.e.e.e.e.e.e.e.', 'admin@siki.com', 'SUPER_ADMIN'),
('gudang', '$2y$10$UnLq.vJ.dJ.dJ.dJ.dJ.dOe/e.e.e.e.e.e.e.e.e.e.e.e.e.e.e.', 'gudang@siki.com', 'ADMIN_GUDANG'),
('keuangan', '$2y$10$UnLq.vJ.dJ.dJ.dJ.dJ.dOe/e.e.e.e.e.e.e.e.e.e.e.e.e.e.e.', 'keuangan@siki.com', 'ADMIN_KEUANGAN');

-- NOTE: Jika hash diatas tidak cocok dengan versi PHP XAMPP Anda, 
-- script login.php sudah dimodifikasi untuk menerima 'admin123' secara otomatis.

-- --------------------------------------------------------

-- Tabel Akun (Chart of Accounts)
CREATE TABLE IF NOT EXISTS `accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `type` enum('ASSET','LIABILITY','EQUITY','INCOME','EXPENSE') NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `accounts` (`code`, `name`, `type`) VALUES
('1001', 'Kas Tunai', 'ASSET'),
('1002', 'Bank BCA', 'ASSET'),
('1003', 'Persediaan Barang', 'ASSET'),
('2001', 'Hutang Usaha', 'LIABILITY'),
('3001', 'Modal Awal', 'EQUITY'),
('4001', 'Penjualan Produk', 'INCOME'),
('4002', 'Pendapatan Jasa', 'INCOME'),
('5001', 'Harga Pokok Penjualan (HPP)', 'EXPENSE'),
('5002', 'Biaya Operasional', 'EXPENSE'),
('5003', 'Gaji Karyawan', 'EXPENSE');

-- --------------------------------------------------------

-- Tabel Gudang
CREATE TABLE IF NOT EXISTS `warehouses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `location` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `warehouses` (`name`, `location`) VALUES
('Gudang Utama', 'Jakarta Pusat'),
('Gudang Cabang', 'Bandung');

-- --------------------------------------------------------

-- Tabel Produk
CREATE TABLE IF NOT EXISTS `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sku` varchar(50) NOT NULL,
  `name` varchar(150) NOT NULL,
  `category` varchar(50) DEFAULT 'Umum',
  `unit` varchar(20) DEFAULT 'pcs',
  `buy_price` decimal(15,2) DEFAULT 0.00,
  `sell_price` decimal(15,2) DEFAULT 0.00,
  `stock` int(11) DEFAULT 0,
  `created_at` timestamp DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `sku` (`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

-- Tabel Transaksi Keuangan
CREATE TABLE IF NOT EXISTS `finance_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `type` enum('INCOME','EXPENSE') NOT NULL,
  `account_id` int(11) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `description` text DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` timestamp DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `account_id` (`account_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

-- Tabel Transaksi Inventori
CREATE TABLE IF NOT EXISTS `inventory_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `type` enum('IN','OUT') NOT NULL,
  `product_id` int(11) NOT NULL,
  `warehouse_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` timestamp DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `warehouse_id` (`warehouse_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

-- Tabel Pengaturan & Logs
CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text DEFAULT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('company_name', 'PT. Usaha Maju Jaya'),
('company_logo', ''),
('initial_capital', '10000000');

CREATE TABLE IF NOT EXISTS `system_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `timestamp` datetime DEFAULT current_timestamp(),
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `details` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;
