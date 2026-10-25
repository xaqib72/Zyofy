<?php
require_once __DIR__ . '/guard.php';
$data = dd_guard_admin_json();
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../inc/site.php';

header('Content-Type: application/json; charset=utf-8');
$query = trim((string) ($data['query'] ?? ''));
$marketplace = trim((string) ($data['marketplace'] ?? ''));
if ($query === '' || strlen($query) > 500) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Enter an ASIN, Amazon product link, or keywords and choose a marketplace.']);
    exit;
}
$parsed = dd_extract_product_input($pdo, $query);
if (!empty($parsed['ambiguous'])) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'The link text mentions more than one marketplace. Use a direct product URL or select the correct marketplace.']);
    exit;
}
if (!empty($parsed['marketplace'])) $marketplace = (string) $parsed['marketplace'];
if ($marketplace !== '') {
    $active = $pdo->prepare('SELECT marketplace FROM marketplace_settings WHERE marketplace = ? AND is_active = 1 LIMIT 1');
    $active->execute([$marketplace]);
    if (!$active->fetchColumn()) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'That marketplace is not active.']);
        exit;
    }
}

$asin = strtoupper((string) ($parsed['asin'] ?? ''));

$columns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'")->fetchAll(PDO::FETCH_COLUMN);
foreach (['id', 'asin', 'title', 'image', 'price', 'marketplace', 'description'] as $requiredColumn) {
    if (!in_array($requiredColumn, $columns, true)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'The product catalog database is missing required fields.']);
        exit;
    }
}
$freshnessColumn = in_array('price_refreshed_at', $columns, true) ? 'price_refreshed_at' : 'NULL AS price_refreshed_at';
$productOrder = in_array('created_at', $columns, true) ? 'created_at DESC, id DESC' : 'id DESC';
$select = 'SELECT id, asin, title, image, price, marketplace, ' . $freshnessColumn . ' FROM products WHERE 1 = 1';
$params = [];
if ($marketplace !== '') {
    $select .= ' AND marketplace = ?';
    $params[] = $marketplace;
}
if ($asin !== '') {
    $select .= ' AND UPPER(asin) = ?';
    $params[] = $asin;
    $select .= ' ORDER BY ' . $productOrder . ' LIMIT 50';
} else {
    $terms = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY);
    $terms = array_slice($terms ?: [], 0, 8);
    foreach ($terms as $term) {
        $select .= ' AND (title LIKE ? OR description LIKE ? OR asin LIKE ?)';
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
        array_push($params, $like, $like, $like);
    }
    $select .= ' ORDER BY ' . $productOrder . ' LIMIT 50';
}
try {
    $statement = $pdo->prepare($select);
    $statement->execute($params);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['image'] = (string) ($row['image'] ?? '');
        $row['title'] = (string) ($row['title'] ?? '');
        $row['asin'] = strtoupper((string) ($row['asin'] ?? ''));
    }
    unset($row);
    echo json_encode(['success' => true, 'products' => $rows]);
} catch (Throwable $error) {
    error_log('admin catalog product search: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not search the product catalog.']);
}
