<?php
require_once __DIR__ . '/inc/site.php';
require_once __DIR__ . '/inc/amazon-products.php';
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    dd_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}
$query = trim((string) ($_GET['q'] ?? ''));
$marketplace = trim((string) ($_GET['marketplace'] ?? 'www.amazon.com'));
$page = max(1, min(10, (int) ($_GET['page'] ?? 1)));
try {
    $products = dd_creator_search_products($pdo, $query, $marketplace, $page);
    dd_json(['success' => true, 'items' => $products, 'count' => count($products)]);
} catch (InvalidArgumentException $exception) {
    dd_json(['success' => false, 'message' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    error_log('get-products.php: ' . $exception->getMessage());
    dd_json(['success' => false, 'message' => 'Product search is temporarily unavailable. Check the Amazon Creators API connection in Admin.'], 503);
}
