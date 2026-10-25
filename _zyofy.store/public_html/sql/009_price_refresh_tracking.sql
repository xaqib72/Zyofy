-- DealDigger: Add price refresh tracking to products table
-- Safe to run once on a database that does not yet have price_refreshed_at.
-- If this column already exists, DO NOT rerun this migration on that database.

ALTER TABLE `products`
  ADD COLUMN `price_refreshed_at` DATETIME NULL DEFAULT NULL
  AFTER `affiliate_link`;

-- Existing rows deliberately remain NULL/stale until Amazon data is fetched again.
