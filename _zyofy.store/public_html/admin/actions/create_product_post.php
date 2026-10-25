<?php
require_once __DIR__ . '/guard.php';
$data = dd_guard_admin_json();
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../inc/site.php';

header('Content-Type: application/json; charset=utf-8');
$productId = isset($data['product_id']) ? (int) $data['product_id'] : 0;
$trackingId = trim((string) ($data['tracking_id'] ?? ''));
if ($productId < 1 || $trackingId === '' || strlen($trackingId) > 100) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Select a catalog product and an assigned tracking ID.']);
    exit;
}

try {
    $stage = 'share_table_check';
    $hasSharesTable = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_shares'")->fetchColumn() > 0;
    if (!$hasSharesTable) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Product posts need database migration 011 (product_shares).']);
        exit;
    }

    $pdo->beginTransaction();
    $stage = 'product_lookup';
    $find = $pdo->prepare('SELECT id, asin, title, marketplace FROM products WHERE id = ? LIMIT 1 FOR UPDATE');
    $find->execute([$productId]);
    $product = $find->fetch(PDO::FETCH_ASSOC);
    if (!$product || !preg_match('/^[A-Z0-9]{10}$/i', (string) $product['asin'])) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'That catalog product is no longer available. Search again.']);
        exit;
    }
    $marketplace = trim((string) $product['marketplace']);
    $stage = 'marketplace_check';
    $active = $pdo->prepare('SELECT marketplace FROM marketplace_settings WHERE marketplace = ? AND is_active = 1 LIMIT 1');
    $active->execute([$marketplace]);
    if (!$active->fetchColumn()) {
        $pdo->rollBack();
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'This product marketplace is not active.']);
        exit;
    }
    $stage = 'tracking_id_check';
    // Existing installations may have different collations on users and
    // user_tags (for example, utf8mb4_uca1400_ai_ci vs utf8mb4_unicode_ci).
    // Normalize every string comparison here so MySQL does not reject the JOIN
    // with error 1267 while validating an otherwise valid assigned tracking ID.
    $tag = $pdo->prepare("SELECT u.id, u.email
        FROM user_tags ut
        INNER JOIN users u
            ON LOWER(TRIM(u.email)) COLLATE utf8mb4_unicode_ci = LOWER(TRIM(ut.user_email)) COLLATE utf8mb4_unicode_ci
        WHERE LOWER(TRIM(ut.tracking_id)) COLLATE utf8mb4_unicode_ci = LOWER(TRIM(?)) COLLATE utf8mb4_unicode_ci
          AND LOWER(TRIM(ut.marketplace)) COLLATE utf8mb4_unicode_ci = LOWER(TRIM(?)) COLLATE utf8mb4_unicode_ci
        ORDER BY u.id ASC LIMIT 1");
    $tag->execute([$trackingId, $marketplace]);
    $tagOwner = $tag->fetch(PDO::FETCH_ASSOC);
    if (!$tagOwner) {
        $pdo->rollBack();
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'The selected tracking ID is not assigned for this product marketplace.']);
        exit;
    }
    $asin = strtoupper((string) $product['asin']);
    $userId = (int) $tagOwner['id'];
    $userEmail = (string) $tagOwner['email'];
    // Product data is shared in the catalog. The public affiliate post is scoped
    // to the selected marketplace-specific tracking ID, so another tag gets its
    // own share even when this product already has posts for other tags.
    $stage = 'share_lookup';
    $existingShare = $pdo->prepare('SELECT share_token FROM product_shares WHERE product_id = ? AND marketplace = ? AND tracking_id = ? ORDER BY id DESC LIMIT 1');
    $existingShare->execute([$productId, $marketplace, $trackingId]);
    $shareToken = (string) ($existingShare->fetchColumn() ?: '');
    if ($shareToken === '') {
        $stage = 'share_insert';
        $shareToken = dd_share_token();
        $share = $pdo->prepare('INSERT INTO product_shares (product_id, user_id, user_email, tracking_id, marketplace, share_token) VALUES (?, ?, ?, ?, ?, ?)');
        try {
            $share->execute([$productId, $userId, $userEmail, $trackingId, $marketplace, $shareToken]);
        } catch (PDOException $error) {
            // If another request created this same product/tag share concurrently,
            // reuse its token rather than failing the admin action.
            $existingShare->execute([$productId, $marketplace, $trackingId]);
            $shareToken = (string) ($existingShare->fetchColumn() ?: '');
            if ($shareToken === '') throw $error;
        }
    }
    $stage = 'commit';
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Affiliate link generated and product post is ready.',
        'post_url' => dd_product_share_url($asin, $shareToken),
        'affiliate_url' => dd_amazon_link($marketplace, $asin, $trackingId),
        'title' => (string) $product['title'],
        'asin' => $asin,
        'marketplace' => $marketplace,
    ]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($error instanceof PDOException) {
        $sqlState = preg_match('/^[0-9A-Z]{5}$/i', (string) $error->getCode()) ? (string) $error->getCode() : 'unknown';
        $driverCode = isset($error->errorInfo[1]) && is_numeric($error->errorInfo[1]) ? (string) (int) $error->errorInfo[1] : 'unknown';
        error_log('admin create product post failed: stage=' . $stage . ' database_error sqlstate=' . $sqlState . ' driver_code=' . $driverCode);
        $message = 'Could not generate the product post. Database error ' . $sqlState . '/' . $driverCode . ' at ' . $stage . '.';
    } else {
        error_log('admin create product post failed: stage=' . $stage . ' exception=' . get_class($error));
        $message = 'Could not generate the product post. Please retry or contact the administrator with the time of the error.';
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $message]);
}
