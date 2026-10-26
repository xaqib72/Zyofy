-- Pickwell: restore contributor-specific short product links.
-- Safe to run on an existing database; does not delete or rewrite user data.

CREATE TABLE IF NOT EXISTS `product_shares` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `user_email` VARCHAR(255) NOT NULL,
  `tracking_id` VARCHAR(100) NOT NULL,
  `marketplace` VARCHAR(100) NOT NULL,
  `share_token` VARCHAR(64) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_user_tracking` (`product_id`, `user_id`, `tracking_id`),
  UNIQUE KEY `uq_share_token` (`share_token`),
  KEY `idx_product_shares_product` (`product_id`),
  KEY `idx_product_shares_user` (`user_id`),
  KEY `idx_product_shares_marketplace` (`marketplace`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
