<?php
// admin/actions/delete_tag.php
require_once __DIR__ . '/guard.php';

// Deleting an assignment changes who gets paid, so it needs the token as well as the
// session — guard.php explains why a session alone is not enough for these endpoints.
$data = dd_guard_admin_json();
require_once '../../db.php';
$id = (int)($data['id'] ?? 0);
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    exit;
}
// Read it first so the reply can name what went, and so deleting nothing is reported as
// nothing rather than as success — a screen that says "deleted" when the row was already
// gone sends you looking for a change that never happened.
$before = $pdo->prepare('SELECT tracking_id, marketplace, user_email FROM user_tags WHERE id = ?');
$before->execute([$id]);
$assignment = $before->fetch(PDO::FETCH_ASSOC);

if (!$assignment) {
    echo json_encode(['success' => false, 'message' => 'That tag assignment no longer exists. Reload the page.']);
    exit;
}

$stmt = $pdo->prepare('DELETE FROM user_tags WHERE id = ?');
$stmt->execute([$id]);

echo json_encode([
    'success' => true,
    'message' => $assignment['tracking_id'] . ' (' . $assignment['marketplace'] . ') removed from '
               . $assignment['user_email'] . '.',
]);