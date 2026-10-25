<?php
// admin/actions/delete_api.php
require_once __DIR__ . '/guard.php';
$data = dd_guard_admin_json();
require_once '../../db.php';
$id = (int)($data['id'] ?? 0);
if (!$id) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    exit;
}
$stmt = $pdo->prepare("DELETE FROM amazon_api_settings WHERE id = ?");
$stmt->execute([$id]);
echo json_encode(['success' => true]);
?>
