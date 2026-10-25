<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['admin_logged_in'])) {
    http_response_code($_SERVER['REQUEST_METHOD'] === 'POST' ? 403 : 405);
    echo json_encode(['success' => false, 'message' => 'Administrator sign-in required.']);
    exit;
}
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) $data = $_POST;
$sent = (string) ($data['csrf_token'] ?? '');
$held = (string) ($_SESSION['report_csrf_token'] ?? '');
if ($held === '' || !hash_equals($held, $sent)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security token did not match. Reload the dashboard.']);
    exit;
}
$credentialId = trim((string) ($data['credential_id'] ?? ''));
$credentialSecret = trim((string) ($data['credential_secret'] ?? ''));
$partnerTag = trim((string) ($data['partner_tag'] ?? ''));
$marketplace = trim((string) ($data['marketplace'] ?? ''));
$version = trim((string) ($data['version'] ?? '3.1')) ?: '3.1';
if ($credentialId === '' || $credentialSecret === '' || $partnerTag === '' || $marketplace === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'All credential fields are required.']);
    exit;
}
require_once __DIR__ . '/db.php';
$stmt = $pdo->prepare('INSERT INTO amazon_api_settings (credential_id, credential_secret, partner_tag, marketplace, version) VALUES (?, ?, ?, ?, ?)');
$stmt->execute([$credentialId, $credentialSecret, $partnerTag, $marketplace, $version]);
echo json_encode(['success' => true, 'message' => 'API credentials saved.']);
