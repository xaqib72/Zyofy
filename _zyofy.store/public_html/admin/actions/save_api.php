<?php
// admin/actions/save_api.php
require_once __DIR__ . '/guard.php';
$data = dd_guard_admin_json();
require_once '../../db.php';
$credential_id = trim($data['credential_id'] ?? '');
$credential_secret = trim($data['credential_secret'] ?? '');
$partner_tag = trim($data['partner_tag'] ?? '');
$marketplace = trim($data['marketplace'] ?? '');
$version = trim($data['version'] ?? '3.1');

if (!$credential_id || !$credential_secret || !$partner_tag || !$marketplace) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'All fields required']);
    exit;
}

// Use existing table amazon_api_settings
$stmt = $pdo->prepare("INSERT INTO amazon_api_settings (credential_id, credential_secret, partner_tag, marketplace, version) VALUES (?, ?, ?, ?, ?)");
$stmt->execute([$credential_id, $credential_secret, $partner_tag, $marketplace, $version]);
echo json_encode(['success' => true, 'message' => 'API saved']);
?>
