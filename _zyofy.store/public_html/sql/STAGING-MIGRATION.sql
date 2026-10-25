-- DealDigger staging migration
-- Run once on: u588173387_dd_stage
-- Safe scope: products uniqueness, product share links, marketplace activation only.

START TRANSACTION;

-- Keep all country/API records, but make USA the only active marketplace for now.
UPDATE `marketplace_settings`
SET `is_active` = CASE WHEN `marketplace` = 'www.amazon.com' THEN 1 ELSE 0 END,
    `updated_by` = 'staging-migration',
    `updated_at` = NOW();

-- Each marketplace+ASIN may exist only once. Existing staging data was already cleaned.
ALTER TABLE `products`
  ADD UNIQUE KEY `uq_products_marketplace_asin` (`marketplace`, `asin`);

-- A product is stored once. This table stores the contributor-specific share identity.
CREATE TABLE IF NOT EXISTS `product_shares` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `tracking_id` varchar(100) NOT NULL,
  `marketplace` varchar(100) NOT NULL,
  `share_token` varchar(64) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_user_tracking` (`product_id`,`user_id`,`tracking_id`),
  UNIQUE KEY `uq_share_token` (`share_token`),
  KEY `idx_product_shares_product` (`product_id`),
  KEY `idx_product_shares_user` (`user_id`),
  KEY `idx_product_shares_marketplace` (`marketplace`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
