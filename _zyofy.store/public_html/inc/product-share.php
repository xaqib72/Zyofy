<?php
require_once __DIR__ . '/site.php';

/**
 * Create or reuse a contributor-specific public product post.
 * Both the website generator and the WhatsApp bot call this function so product
 * refresh, assigned-tag checks, and share-token behavior remain identical.
 */
function dd_create_product_share(PDO $pdo, $userId, $userEmail, $asin, $marketplace, array $tag, $requireAmazonApiProduct = false)
{
    require_once __DIR__ . '/amazon-products.php';
    $userId = (int) $userId;
    $userEmail = trim((string) $userEmail);
    $asin = strtoupper(trim((string) $asin));
    $marketplace = trim((string) $marketplace);
    $trackingId = trim((string) ($tag['tracking_id'] ?? ''));
    if ($userId < 1 || $userEmail === '' || !preg_match('/^[A-Z0-9]{10}$/', $asin) || $marketplace === '' || $trackingId === '') {
        throw new InvalidArgumentException('The product, marketplace, account, or assigned tracking ID is incomplete.');
    }
    if (strcasecmp(trim((string) ($tag['marketplace'] ?? '')), $marketplace) !== 0) {
        throw new InvalidArgumentException('The assigned tracking ID does not belong to this marketplace.');
    }

    $ownedTag = $pdo->prepare('SELECT ut.id FROM user_tags ut JOIN marketplace_settings ms ON LOWER(TRIM(ms.marketplace)) = LOWER(TRIM(ut.marketplace)) WHERE ut.tracking_id = ? AND LOWER(TRIM(ut.user_email)) = LOWER(TRIM(?)) AND LOWER(TRIM(ut.marketplace)) = LOWER(TRIM(?)) AND ms.is_active = 1 LIMIT 1');
    $ownedTag->execute([$trackingId, $userEmail, $marketplace]);
    if (!$ownedTag->fetchColumn()) throw new RuntimeException('Your account has no active tracking ID for this Amazon marketplace. Ask the administrator to assign one.');

    $columns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('content_refreshed_at', $columns, true) || !in_array('price_refreshed_at', $columns, true)) {
        throw new RuntimeException('Products table is missing freshness columns. Apply database migrations 009 and 010 first.');
    }
    $productStatement = $pdo->prepare('SELECT id, title, image, description, content_refreshed_at, price_refreshed_at FROM products WHERE asin = ? AND marketplace = ? ORDER BY id DESC LIMIT 1');
    $productStatement->execute([$asin, $marketplace]);
    $product = $productStatement->fetch(PDO::FETCH_ASSOC) ?: null;
    $fresh = $product && !empty($product['content_refreshed_at']) && !empty($product['price_refreshed_at'])
        && strtotime($product['content_refreshed_at']) > time() - 86400
        && strtotime($product['price_refreshed_at']) > time() - 3600;
    $productDataUnavailable = false;
    $fetchedProduct = null;
    if (!$fresh) {
        $fetchedProduct = dd_creator_get_product($pdo, $asin, $marketplace);
        if (!$fetchedProduct) {
            if ($requireAmazonApiProduct) {
                throw new RuntimeException('The connected Amazon product API did not return this ASIN for the detected marketplace. Check the product country and API connection, then try its full country-specific Amazon URL.');
            }
            $productDataUnavailable = true;
            $fetchedProduct = [
                'asin' => $asin,
                'title' => trim((string) ($product['title'] ?? '')) ?: 'Amazon listing ' . $asin,
                'image' => (string) ($product['image'] ?? ''),
                'price' => '',
                'description' => trim((string) ($product['description'] ?? '')) ?: 'Product details could not be retrieved from Amazon’s product API. Visit Amazon for current listing information.',
                'marketplace' => $marketplace,
                'affiliate_link' => dd_amazon_link($marketplace, $asin, $trackingId),
            ];
        }
    }

    // The selected product refresh and its share record form one unit. Discovery
    // candidates never reach here, and failed post creation must not leave a new
    // product row behind. Use a savepoint when called inside an outer transaction.
    $ownsTransaction = !$pdo->inTransaction();
    $savepoint = 'dd_product_share_create';
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    } else {
        $pdo->exec('SAVEPOINT ' . $savepoint);
    }
    try {
        if (!$fresh) {
            dd_creator_cache_products($pdo, [$fetchedProduct]);
            $productStatement->execute([$asin, $marketplace]);
            $product = $productStatement->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $productId = (int) ($product['id'] ?? 0);
        if ($productId < 1) throw new RuntimeException('The product could not be saved to the catalog.');

        $sharesTable = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_shares'")->fetchColumn() > 0;
        if (!$sharesTable) throw new RuntimeException('The product post feature needs database migration 011 (product_shares).');
        $shareStatement = $pdo->prepare('SELECT share_token FROM product_shares WHERE product_id = ? AND user_id = ? AND tracking_id = ? LIMIT 1');
        $shareStatement->execute([$productId, $userId, $trackingId]);
        $shareToken = (string) ($shareStatement->fetchColumn() ?: '');
        if ($shareToken === '') {
            $shareToken = dd_share_token();
            $insertShare = $pdo->prepare('INSERT INTO product_shares (product_id, user_id, user_email, tracking_id, marketplace, share_token) VALUES (?, ?, ?, ?, ?, ?)');
            try {
                $insertShare->execute([$productId, $userId, $userEmail, $trackingId, $marketplace, $shareToken]);
            } catch (PDOException $error) {
                // Concurrent requests for the same contributor/tag should resolve to
                // the share row that won the unique-key race, not create a second post.
                $shareStatement->execute([$productId, $userId, $trackingId]);
                $shareToken = (string) ($shareStatement->fetchColumn() ?: '');
                if ($shareToken === '') throw $error;
            }
        }
        $postUrl = dd_product_share_url($asin, $shareToken);
        if ($ownsTransaction) {
            $pdo->commit();
        } else {
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        }
        return [
            'success' => true,
            'asin' => $asin,
            'marketplace' => $marketplace,
            'tracking_id' => $trackingId,
            'post_url' => $postUrl,
            'saved' => true,
            'link_type' => 'amazon_and_zyofy_post',
            'product_data_unavailable' => $productDataUnavailable,
        ];
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        } elseif (!$ownsTransaction) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        }
        throw $error;
    }
}
