<?php
// admin/actions/update_api.php
require_once __DIR__ . '/guard.php';
$data = dd_guard_admin_json();
require_once '../../db.php';
$id = (int)($data['id'] ?? 0);
$credential_id = trim($data['credential_id'] ?? '');
$credential_secret = trim($data['credential_secret'] ?? '');
$partner_tag = trim($data['partner_tag'] ?? '');
$marketplace = trim($data['marketplace'] ?? '');
$version = trim($data['version'] ?? '3.1');

if (!$id || !$credential_id || !$credential_secret || !$partner_tag || !$marketplace) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Missing fields']);
    exit;
}

$stmt = $pdo->prepare("UPDATE amazon_api_settings SET credential_id = ?, credential_secret = ?, partner_tag = ?, marketplace = ?, version = ? WHERE id = ?");
$stmt->execute([$credential_id, $credential_secret, $partner_tag, $marketplace, $version, $id]);
echo json_encode(['success' => true]);
?>
