<?php
// admin/actions/delete_user.php
require_once __DIR__ . '/guard.php';

// Removing a contributor takes every tag they hold with them.
$input = dd_guard_admin_json();
require_once '../../db.php';

header('Content-Type: application/json');
$userId = $input['id'] ?? 0;

if (!$userId) {
    echo json_encode(['success' => false, 'message' => 'User ID required']);
    exit;
}

$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit;
}

if ($user['email'] === $_SESSION['admin_email']) {
    echo json_encode(['success' => false, 'message' => 'You cannot delete your own admin account']);
    exit;
}

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("DELETE FROM user_tags WHERE user_email = ?");
    $stmt->execute([$user['email']]);
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'User deleted successfully']);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>