<?php
require_once __DIR__ . '/guard.php';
$data = dd_guard_admin_json();
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../inc/amazon-products.php';

$id = isset($data['id']) ? (int) $data['id'] : 0;
$isEdit = $id > 0;
$asin = strtoupper(trim((string) ($data['asin'] ?? '')));
$marketplace = trim((string) ($data['marketplace'] ?? ''));
$trackingId = trim((string) ($data['tracking_id'] ?? ''));
$title = trim((string) ($data['title'] ?? ''));
$description = trim((string) ($data['description'] ?? ''));

if (!preg_match('/^[A-Z0-9]{10}$/', $asin)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Enter a valid 10-character Amazon ASIN.']);
    exit;
}
if ($marketplace === '' || strlen($marketplace) > 255) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Choose a marketplace.']);
    exit;
}
if ($trackingId === '' || strlen($trackingId) > 100) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Select an assigned affiliate tracking ID.']);
    exit;
}
if (strlen($title) > 500 || strlen($description) > 20000) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'The title or description is too long.']);
    exit;
}

$active = $pdo->prepare('SELECT marketplace FROM marketplace_settings WHERE marketplace = ? AND is_active = 1 LIMIT 1');
$active->execute([$marketplace]);
if (!$active->fetchColumn()) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'That marketplace is not active. Enable it under Markets & rates first.']);
    exit;
}
$tagCheck = $pdo->prepare('SELECT tracking_id FROM user_tags WHERE tracking_id = ? AND LOWER(TRIM(marketplace)) = LOWER(TRIM(?)) LIMIT 1');
$tagCheck->execute([$trackingId, $marketplace]);
if (!$tagCheck->fetchColumn()) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'That tracking ID is not assigned for the selected marketplace.']);
    exit;
}

try {
    $product = dd_creator_get_product($pdo, $asin, $marketplace);
    if (!is_array($product)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Amazon API did not return this product. Check the ASIN, marketplace, and API connection.']);
        exit;
    }

    $columns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('price_refreshed_at', $columns, true) || !in_array('content_refreshed_at', $columns, true)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Product refresh fields are not installed. Apply the product freshness database migrations first.']);
        exit;
    }

    $product['title'] = $title !== '' ? $title : $product['title'];
    $product['description'] = $description !== '' ? $description : $product['description'];
    // The admin selects an assigned Associate tracking ID for this catalog product.
    $product['affiliate_link'] = dd_amazon_link($marketplace, $asin, $trackingId);
    $pdo->beginTransaction();
    if ($id > 0) {
        $existing = $pdo->prepare('SELECT id, marketplace FROM products WHERE id = ? LIMIT 1');
        $existing->execute([$id]);
        $old = $existing->fetch(PDO::FETCH_ASSOC);
        if (!$old) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Product no longer exists. Refresh the page and try again.']);
            exit;
        }
        $duplicate = $pdo->prepare('SELECT id FROM products WHERE asin = ? AND marketplace = ? AND id <> ? LIMIT 1');
        $duplicate->execute([$asin, $marketplace, $id]);
        if ($duplicate->fetchColumn()) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'This ASIN already exists for that marketplace.']);
            exit;
        }
        $price = trim((string) ($product['price'] ?? ''));
        $sql = 'UPDATE products SET title = ?, asin = ?, image = ?, price = ?, marketplace = ?, description = ?, affiliate_link = ?, price_refreshed_at = ?, content_refreshed_at = ? WHERE id = ?';
        $update = $pdo->prepare($sql);
        $update->execute([$product['title'], $asin, $product['image'], $price !== '' ? $price : null, $marketplace, $product['description'], $product['affiliate_link'], $price !== '' ? gmdate('Y-m-d H:i:s') : null, gmdate('Y-m-d H:i:s'), $id]);
        if ($old['marketplace'] !== $marketplace) {
            $shareUpdate = $pdo->prepare('UPDATE product_shares SET marketplace = ? WHERE product_id = ?');
            $shareUpdate->execute([$marketplace, $id]);
        }
    } else {
        $find = $pdo->prepare('SELECT id FROM products WHERE asin = ? AND marketplace = ? LIMIT 1');
        $find->execute([$asin, $marketplace]);
        $existingId = (int) $find->fetchColumn();
        if ($existingId) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'This product is already in the catalog. Edit its existing entry instead.']);
            exit;
        }
        dd_creator_cache_products($pdo, [$product]);
        $find->execute([$asin, $marketplace]);
        $id = (int) $find->fetchColumn();
        if (!$id) {
            throw new RuntimeException('Amazon returned the product, but it could not be saved to the catalog.');
        }
        $update = $pdo->prepare('UPDATE products SET title = ?, description = ? WHERE id = ?');
        $update->execute([$product['title'], $product['description'], $id]);
    }
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => $isEdit ? 'Product updated with current Amazon API data.' : 'Product added using current Amazon API data.']);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('admin save product: ' . $error->getMessage());
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $error instanceof InvalidArgumentException ? $error->getMessage() : 'Could not save the product. Check the Amazon API connection and server error log.']);
}
