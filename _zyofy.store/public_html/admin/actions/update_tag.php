<?php
// admin/actions/update_tag.php
require_once __DIR__ . '/guard.php';

// Retargeting a tag moves earnings from one tracking ID or country to another.
$data = dd_guard_admin_json();
require_once '../../db.php';
$id = (int)($data['id'] ?? 0);
$tracking_id = trim($data['tracking_id'] ?? '');
$marketplace = trim($data['marketplace'] ?? '');

if (!$id || !$tracking_id || !$marketplace) {
    echo json_encode(['success' => false, 'message' => 'Missing fields']);
    exit;
}

$stmt = $pdo->prepare("UPDATE user_tags SET tracking_id = ?, marketplace = ? WHERE id = ?");
$stmt->execute([$tracking_id, $marketplace, $id]);
if ($stmt->rowCount()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'No changes or tag not found']);
}
?>