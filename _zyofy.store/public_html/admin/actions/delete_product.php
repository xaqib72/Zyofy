<?php
require_once __DIR__ . '/guard.php';
$data = dd_guard_admin_json();
require_once __DIR__ . '/../../db.php';

$id = isset($data['id']) ? (int) $data['id'] : 0;
if ($id < 1) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Choose a valid product.']);
    exit;
}

try {
    $pdo->beginTransaction();
    $exists = $pdo->prepare('SELECT id FROM products WHERE id = ? LIMIT 1');
    $exists->execute([$id]);
    if (!$exists->fetchColumn()) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Product not found. Refresh the page and try again.']);
        exit;
    }
    // Remove dependent contributor links first so their product reference cannot be orphaned.
    $shares = $pdo->prepare('DELETE FROM product_shares WHERE product_id = ?');
    $shares->execute([$id]);
    $delete = $pdo->prepare('DELETE FROM products WHERE id = ?');
    $delete->execute([$id]);
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Product and its ' . (int) $shares->rowCount() . ' associated tracked link(s) were deleted.']);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('admin delete product: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not delete the product. Check the server error log.']);
}
