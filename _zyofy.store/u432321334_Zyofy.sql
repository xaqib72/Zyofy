-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Oct 25, 2026 at 09:55 PM
-- Server version: 11.8.9-MariaDB-log
-- PHP Version: 7.2.34

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `u432321334_Zyofy`
--

-- --------------------------------------------------------

--
-- Table structure for table `amazon_api_settings`
--

CREATE TABLE `amazon_api_settings` (
  `id` int(11) NOT NULL,
  `credential_id` text NOT NULL,
  `credential_secret` text NOT NULL,
  `version` varchar(10) NOT NULL,
  `partner_tag` varchar(100) NOT NULL,
  `marketplace` varchar(100) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `exchange_rates`
--

CREATE TABLE `exchange_rates` (
  `currency` char(3) NOT NULL,
  `rate_to_usd` decimal(18,6) NOT NULL,
  `quoted_for` date DEFAULT NULL,
  `is_confirmed` tinyint(1) NOT NULL DEFAULT 0,
  `updated_by` varchar(255) NOT NULL DEFAULT '',
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `manual_product_posts`
--

CREATE TABLE `manual_product_posts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `slug` varchar(32) NOT NULL,
  `title` varchar(500) NOT NULL,
  `image_url` text NOT NULL,
  `description` text NOT NULL,
  `affiliate_url` text NOT NULL,
  `marketplace` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `marketplace_settings`
--

CREATE TABLE `marketplace_settings` (
  `marketplace` varchar(64) NOT NULL,
  `country_label` varchar(64) NOT NULL,
  `currency` char(3) NOT NULL,
  `share_percent` decimal(5,2) NOT NULL DEFAULT 70.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `updated_by` varchar(255) NOT NULL DEFAULT '',
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `token` varchar(100) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payout_months`
--

CREATE TABLE `payout_months` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `report_month` char(7) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `gross_usd` decimal(18,2) NOT NULL DEFAULT 0.00,
  `payable_usd` decimal(18,2) NOT NULL DEFAULT 0.00,
  `status` varchar(10) NOT NULL DEFAULT 'draft',
  `paid_on` date DEFAULT NULL,
  `frozen_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `asin` varchar(20) DEFAULT NULL,
  `title` text DEFAULT NULL,
  `price` varchar(50) DEFAULT NULL,
  `image` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `affiliate_link` text DEFAULT NULL,
  `price_refreshed_at` datetime DEFAULT NULL,
  `content_refreshed_at` datetime DEFAULT NULL,
  `marketplace` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_discovery_selections`
--

CREATE TABLE `product_discovery_selections` (
  `selection_token` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `channel` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` int(11) NOT NULL,
  `scope_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `candidates_json` longtext NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_shares`
--

CREATE TABLE `product_shares` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `product_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `tracking_id` varchar(100) NOT NULL,
  `marketplace` varchar(100) NOT NULL,
  `share_token` varchar(64) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_used_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `report_uploads`
--

CREATE TABLE `report_uploads` (
  `id` int(11) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `stored_file` varchar(255) NOT NULL DEFAULT '',
  `report_month` char(7) DEFAULT NULL,
  `covers_to` date DEFAULT NULL,
  `marketplace` varchar(64) NOT NULL DEFAULT '',
  `currency` char(3) NOT NULL DEFAULT '',
  `rate_to_usd` decimal(18,6) NOT NULL DEFAULT 1.000000,
  `share_percent` decimal(5,2) NOT NULL DEFAULT 100.00,
  `rate_source` varchar(20) NOT NULL DEFAULT 'default',
  `replaced_upload_id` bigint(20) UNSIGNED DEFAULT NULL,
  `rows_processed` int(11) NOT NULL DEFAULT 0,
  `uploaded_by` varchar(255) DEFAULT NULL,
  `uploaded_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `report_upload_items`
--

CREATE TABLE `report_upload_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `upload_id` bigint(20) UNSIGNED NOT NULL,
  `tracking_id` varchar(191) NOT NULL,
  `marketplace` varchar(64) NOT NULL DEFAULT '',
  `currency` char(3) NOT NULL DEFAULT '',
  `rate_to_usd` decimal(18,6) NOT NULL DEFAULT 1.000000,
  `share_percent` decimal(5,2) NOT NULL DEFAULT 100.00,
  `report_month` char(7) NOT NULL,
  `clicks` int(11) NOT NULL DEFAULT 0,
  `items_ordered` int(11) NOT NULL DEFAULT 0,
  `ordered_revenue` decimal(18,2) NOT NULL DEFAULT 0.00,
  `items_shipped` int(11) NOT NULL DEFAULT 0,
  `items_returned` int(11) NOT NULL DEFAULT 0,
  `items_shipped_revenue` decimal(18,2) NOT NULL DEFAULT 0.00,
  `items_returned_revenue` decimal(18,2) NOT NULL DEFAULT 0.00,
  `total_earnings` decimal(18,2) NOT NULL DEFAULT 0.00,
  `bonus` decimal(18,2) NOT NULL DEFAULT 0.00,
  `items_shipped_earnings` decimal(18,2) NOT NULL DEFAULT 0.00,
  `items_returned_earnings` decimal(18,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tracking_balances`
--

CREATE TABLE `tracking_balances` (
  `id` int(11) NOT NULL,
  `tracking_id` varchar(100) NOT NULL,
  `balance` decimal(10,2) NOT NULL DEFAULT 0.00,
  `last_updated` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tracking_report_data`
--

CREATE TABLE `tracking_report_data` (
  `id` int(11) NOT NULL,
  `tracking_id` varchar(100) NOT NULL,
  `clicks` int(11) NOT NULL DEFAULT 0,
  `items_ordered` int(11) NOT NULL DEFAULT 0,
  `ordered_revenue` decimal(12,2) NOT NULL DEFAULT 0.00,
  `items_shipped` int(11) NOT NULL DEFAULT 0,
  `items_returned` int(11) NOT NULL DEFAULT 0,
  `items_shipped_revenue` decimal(12,2) NOT NULL DEFAULT 0.00,
  `items_returned_revenue` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_earnings` decimal(12,2) NOT NULL DEFAULT 0.00,
  `bonus` decimal(12,2) NOT NULL DEFAULT 0.00,
  `items_shipped_earnings` decimal(12,2) NOT NULL DEFAULT 0.00,
  `items_returned_earnings` decimal(12,2) NOT NULL DEFAULT 0.00,
  `last_updated` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tracking_report_monthly`
--

CREATE TABLE `tracking_report_monthly` (
  `id` int(11) NOT NULL,
  `tracking_id` varchar(100) NOT NULL,
  `marketplace` varchar(64) NOT NULL DEFAULT '',
  `currency` char(3) NOT NULL DEFAULT '',
  `rate_to_usd` decimal(18,6) NOT NULL DEFAULT 1.000000,
  `share_percent` decimal(5,2) NOT NULL DEFAULT 100.00,
  `report_month` char(7) NOT NULL COMMENT 'Format: YYYY-MM, e.g. 2026-07',
  `clicks` int(11) NOT NULL DEFAULT 0,
  `items_ordered` int(11) NOT NULL DEFAULT 0,
  `ordered_revenue` decimal(12,2) NOT NULL DEFAULT 0.00,
  `items_shipped` int(11) NOT NULL DEFAULT 0,
  `items_returned` int(11) NOT NULL DEFAULT 0,
  `items_shipped_revenue` decimal(12,2) NOT NULL DEFAULT 0.00,
  `items_returned_revenue` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_earnings` decimal(12,2) NOT NULL DEFAULT 0.00,
  `bonus` decimal(12,2) NOT NULL DEFAULT 0.00,
  `items_shipped_earnings` decimal(12,2) NOT NULL DEFAULT 0.00,
  `items_returned_earnings` decimal(12,2) NOT NULL DEFAULT 0.00,
  `last_updated` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `share_percent` decimal(5,2) NOT NULL DEFAULT 70.00,
  `dummy_commission_percent` decimal(5,2) NOT NULL DEFAULT 70.00,
  `password` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_admin` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_tags`
--

CREATE TABLE `user_tags` (
  `id` int(11) NOT NULL,
  `user_email` varchar(255) DEFAULT NULL,
  `tracking_id` varchar(100) DEFAULT NULL,
  `marketplace` varchar(100) DEFAULT NULL,
  `share_percent` decimal(5,2) NOT NULL DEFAULT 70.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `whatsapp_link_codes`
--

CREATE TABLE `whatsapp_link_codes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `phone_e164` varchar(16) NOT NULL,
  `code_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `consumed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `whatsapp_messages`
--

CREATE TABLE `whatsapp_messages` (
  `message_id` varchar(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `state` varchar(16) NOT NULL DEFAULT 'pending',
  `attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `outcome` varchar(32) DEFAULT NULL,
  `received_at` datetime NOT NULL,
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `whatsapp_user_phones`
--

CREATE TABLE `whatsapp_user_phones` (
  `user_id` int(11) NOT NULL,
  `phone_e164` varchar(16) NOT NULL,
  `verified_at` datetime NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `amazon_api_settings`
--
ALTER TABLE `amazon_api_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `exchange_rates`
--
ALTER TABLE `exchange_rates`
  ADD PRIMARY KEY (`currency`);

--
-- Indexes for table `manual_product_posts`
--
ALTER TABLE `manual_product_posts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_manual_product_slug` (`slug`),
  ADD KEY `idx_manual_product_created` (`created_at`);

--
-- Indexes for table `marketplace_settings`
--
ALTER TABLE `marketplace_settings`
  ADD PRIMARY KEY (`marketplace`),
  ADD KEY `idx_currency` (`currency`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `email` (`email`),
  ADD KEY `token` (`token`);

--
-- Indexes for table `payout_months`
--
ALTER TABLE `payout_months`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_month_user` (`report_month`,`user_id`),
  ADD KEY `idx_month` (`report_month`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_products_marketplace_asin` (`marketplace`,`asin`);

--
-- Indexes for table `product_discovery_selections`
--
ALTER TABLE `product_discovery_selections`
  ADD PRIMARY KEY (`selection_token`),
  ADD UNIQUE KEY `uq_discovery_owner_scope` (`channel`,`user_id`,`scope_hash`),
  ADD KEY `idx_discovery_expiry` (`expires_at`);

--
-- Indexes for table `product_shares`
--
ALTER TABLE `product_shares`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_product_user_tracking` (`product_id`,`user_id`,`tracking_id`),
  ADD UNIQUE KEY `uq_share_token` (`share_token`),
  ADD KEY `idx_product_shares_product` (`product_id`),
  ADD KEY `idx_product_shares_user` (`user_id`),
  ADD KEY `idx_product_shares_marketplace` (`marketplace`);

--
-- Indexes for table `report_uploads`
--
ALTER TABLE `report_uploads`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `report_upload_items`
--
ALTER TABLE `report_upload_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_upload_id` (`upload_id`),
  ADD KEY `idx_item_tracking_month` (`tracking_id`,`report_month`);

--
-- Indexes for table `tracking_balances`
--
ALTER TABLE `tracking_balances`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `tracking_id` (`tracking_id`);

--
-- Indexes for table `tracking_report_data`
--
ALTER TABLE `tracking_report_data`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `tracking_id` (`tracking_id`);

--
-- Indexes for table `tracking_report_monthly`
--
ALTER TABLE `tracking_report_monthly`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_tag_month_market` (`tracking_id`,`report_month`,`marketplace`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `user_tags`
--
ALTER TABLE `user_tags`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_email_tag_market` (`user_email`,`tracking_id`,`marketplace`);

--
-- Indexes for table `whatsapp_link_codes`
--
ALTER TABLE `whatsapp_link_codes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_wa_link_user_created` (`user_id`,`created_at`),
  ADD KEY `idx_wa_link_phone_created` (`phone_e164`,`created_at`),
  ADD KEY `idx_wa_link_pending` (`phone_e164`,`expires_at`,`consumed_at`);

--
-- Indexes for table `whatsapp_messages`
--
ALTER TABLE `whatsapp_messages`
  ADD PRIMARY KEY (`message_id`),
  ADD KEY `idx_wa_messages_state_received` (`state`,`received_at`);

--
-- Indexes for table `whatsapp_user_phones`
--
ALTER TABLE `whatsapp_user_phones`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `uq_whatsapp_phone_e164` (`phone_e164`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `amazon_api_settings`
--
ALTER TABLE `amazon_api_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `manual_product_posts`
--
ALTER TABLE `manual_product_posts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payout_months`
--
ALTER TABLE `payout_months`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_shares`
--
ALTER TABLE `product_shares`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `report_uploads`
--
ALTER TABLE `report_uploads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `report_upload_items`
--
ALTER TABLE `report_upload_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tracking_balances`
--
ALTER TABLE `tracking_balances`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tracking_report_data`
--
ALTER TABLE `tracking_report_data`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tracking_report_monthly`
--
ALTER TABLE `tracking_report_monthly`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_tags`
--
ALTER TABLE `user_tags`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `whatsapp_link_codes`
--
ALTER TABLE `whatsapp_link_codes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
