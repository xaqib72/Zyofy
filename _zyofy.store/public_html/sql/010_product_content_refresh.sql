-- Zyofy: enforce the Creators API one-day cache window for product content.
-- Run once after 009_price_refresh_tracking.sql on the deployed database.
-- Existing rows stay NULL and are intentionally hidden until refreshed by a live API search.

ALTER TABLE `products`
  ADD COLUMN `content_refreshed_at` DATETIME NULL DEFAULT NULL
  AFTER `price_refreshed_at`;
