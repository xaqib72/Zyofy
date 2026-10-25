<?php
require_once __DIR__ . '/guard.php';
$data = dd_guard_admin_json();
require_once __DIR__ . '/../../db.php';

$id = isset($data['id']) ? (int) $data['id'] : 0;
$raw = isset($data['dummy']) ? trim((string) $data['dummy']) : '';
if ($id < 1 || $raw === '' || !is_numeric($raw)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Choose a user and enter a valid displayed commission.']);
    exit;
}

$value = round((float) $raw, 2);
if ($value < 0 || $value > 100) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'The displayed commission must be between 0 and 100 percent.']);
    exit;
}

$hasColumn = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'dummy_commission_percent'")->fetchColumn() > 0;
if (!$hasColumn) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Run sql/013_dummy_commission.sql once before changing displayed commission.']);
    exit;
}

$user = $pdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
$user->execute([$id]);
if (!$user->fetchColumn()) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'User not found. Refresh the dashboard and try again.']);
    exit;
}

$update = $pdo->prepare('UPDATE users SET dummy_commission_percent = ? WHERE id = ?');
$update->execute([$value, $id]);

$display = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
echo json_encode([
    'success' => true,
    'message' => 'Displayed commission set to ' . $display . '%. Actual commission and payout calculations are unchanged.'
]);
