<?php
require_once __DIR__ . '/guard.php';
$data = dd_guard_admin_json();
require_once __DIR__ . '/../../db.php';

header('Content-Type: application/json; charset=utf-8');
$id = (int) ($data['id'] ?? 0);
if ($id < 1) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Choose a valid manual product.']);
    exit;
}
try {
    $delete = $pdo->prepare('DELETE FROM manual_product_posts WHERE id = ?');
    $delete->execute([$id]);
    if ($delete->rowCount() !== 1) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Manual product not found. Refresh the dashboard and try again.']);
        exit;
    }
    echo json_encode(['success' => true, 'message' => 'Manual product deleted.']);
} catch (Throwable $error) {
    error_log('admin delete manual product failed: ' . get_class($error));
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not delete this product.']);
}
