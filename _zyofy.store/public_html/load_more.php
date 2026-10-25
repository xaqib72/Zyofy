<?php
require_once __DIR__ . '/inc/site.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/storefront.php';
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}
$offset = max(0, min(10000, (int) ($_GET['offset'] ?? 0)));
if (!dd_store_has_content_freshness($pdo)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Product catalog freshness migration is required.');
}
$category = trim((string) ($_GET['category'] ?? ''));
$query = trim((string) ($_GET['q'] ?? ''));
$marketplace = trim((string) ($_GET['marketplace'] ?? ''));
$where = ['m.is_active = 1', 'p.content_refreshed_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY'];
$params = [];
if ($marketplace !== '') {
    $where[] = 'p.marketplace = :marketplace';
    $params[':marketplace'] = $marketplace;
}
if ($category !== '') {
    $clause = dd_store_category_clause($category, $params, 'load_cat');
    if ($clause !== '') $where[] = $clause;
}
if ($query !== '') {
    $where[] = '(p.title LIKE :load_q OR p.asin LIKE :load_asin)';
    $params[':load_q'] = '%' . $query . '%';
    $params[':load_asin'] = '%' . $query . '%';
}
$productOrder = dd_store_has_created_at($pdo) ? 'p.created_at DESC, p.id DESC' : 'p.id DESC';
$catalogCountStatement = $pdo->prepare('SELECT COUNT(*) FROM products p JOIN marketplace_settings m ON m.marketplace = p.marketplace WHERE ' . implode(' AND ', $where));
$catalogCountStatement->execute($params);
$catalogTotal = (int) $catalogCountStatement->fetchColumn();
$prefixLimit = min(10025, $offset + 25);
$statement = $pdo->prepare('SELECT p.* FROM products p JOIN marketplace_settings m ON m.marketplace = p.marketplace WHERE ' . implode(' AND ', $where) . " ORDER BY $productOrder LIMIT :prefix_limit OFFSET 0");
foreach ($params as $key => $value) $statement->bindValue($key, $value, PDO::PARAM_STR);
$statement->bindValue(':prefix_limit', $prefixLimit, PDO::PARAM_INT);
$statement->execute();
$rows = $statement->fetchAll(PDO::FETCH_ASSOC);
$manualTotal = 0;
try {
    $manualWhere = ['marketplace = :manual_market'];
    $manualParams = [':manual_market' => $marketplace];
    if ($query !== '') {
        $manualWhere[] = '(title LIKE :manual_q_title OR description LIKE :manual_q_description)';
        $manualParams[':manual_q_title'] = '%' . $query . '%';
        $manualParams[':manual_q_description'] = '%' . $query . '%';
    }
    if ($category !== '') {
        $categoryInfo = dd_store_category($category);
        if ($categoryInfo) {
            $manualCategoryParts = [];
            foreach ($categoryInfo['keywords'] as $i => $keyword) {
                $key = ':manual_cat_' . $i;
                $manualCategoryParts[] = "title LIKE $key";
                $manualParams[$key] = '%' . $keyword . '%';
            }
            if ($manualCategoryParts) $manualWhere[] = '(' . implode(' OR ', $manualCategoryParts) . ')';
        }
    }
    $manualCount = $pdo->prepare('SELECT COUNT(*) FROM manual_product_posts WHERE ' . implode(' AND ', $manualWhere));
    $manualCount->execute($manualParams);
    $manualTotal = (int) $manualCount->fetchColumn();
    $manualRows = $pdo->prepare('SELECT id, slug AS manual_slug, title, image_url AS image, description, affiliate_url AS affiliate_link, marketplace, NULL AS price, NULL AS price_refreshed_at, created_at FROM manual_product_posts WHERE ' . implode(' AND ', $manualWhere) . ' ORDER BY created_at DESC, id DESC LIMIT :prefix_limit OFFSET 0');
    foreach ($manualParams as $key => $value) $manualRows->bindValue($key, $value, PDO::PARAM_STR);
    $manualRows->bindValue(':prefix_limit', $prefixLimit, PDO::PARAM_INT);
    $manualRows->execute();
    $rows = array_merge($rows, $manualRows->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $exception) {
    // Manual product posts are optional until their migration has been installed.
}
usort($rows, static function ($a, $b) {
    $aTime = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
    $bTime = strtotime((string) ($b['created_at'] ?? '')) ?: 0;
    return ($bTime <=> $aTime) ?: ((int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0));
});
$combinedTotal = $catalogTotal + $manualTotal;
$hasMore = ($offset + 24) < $combinedTotal;
$rows = array_slice($rows, $offset, 24);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
foreach ($rows as $product) dd_product_card($product);
header('X-Has-More: ' . ($hasMore ? '1' : '0'));
