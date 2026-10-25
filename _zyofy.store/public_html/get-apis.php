<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Administrator sign-in required.']);
    exit;
}
require_once __DIR__ . '/db.php';
$rows = $pdo->query('SELECT id, credential_id, credential_secret, partner_tag, marketplace, version FROM amazon_api_settings ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['success' => true, 'items' => $rows], JSON_UNESCAPED_SLASHES);
