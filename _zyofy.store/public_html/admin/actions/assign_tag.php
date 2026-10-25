<?php
// admin/actions/assign_tag.php
require_once __DIR__ . '/guard.php';

// A new assignment puts somebody on a commission, so it is guarded like the rest.
$data = dd_guard_admin_json();
require_once '../../db.php';
$email = trim($data['email'] ?? '');
$tag = trim($data['tag'] ?? '');
$marketplace = trim($data['marketplace'] ?? '');

if (!$email || !$tag || !$marketplace) {
    echo json_encode(['success' => false, 'message' => 'All fields required']);
    exit;
}

// Check if user exists
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$email]);
if (!$stmt->fetch()) {
    echo json_encode(['success' => false, 'message' => 'User email not found']);
    exit;
}

/* Commission belongs to the assignment. Left out, the column default decides — so a
   caller that does not send one still gets the figure the admin set for new tags. */
$hasShare = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_tags'
      AND COLUMN_NAME = 'share_percent'")->fetchColumn() > 0;

$shareRaw = isset($data['share']) ? trim((string) $data['share']) : '';
$share = null;
if ($hasShare && $shareRaw !== '') {
    if (!is_numeric($shareRaw)) {
        echo json_encode(['success' => false, 'message' => 'The commission must be a number.']);
        exit;
    }
    $share = round((float) $shareRaw, 2);
    if ($share < 0 || $share > 100) {
        echo json_encode(['success' => false, 'message' => 'The commission must be between 0 and 100 percent.']);
        exit;
    }
}

// The same person holding the same tag on the same marketplace twice is not a second
// assignment, it is a duplicate — and it would show two different rates for one thing.
$duplicate = $pdo->prepare(
    'SELECT COUNT(*) FROM user_tags WHERE user_email = ? AND tracking_id = ? AND marketplace = ?'
);
$duplicate->execute([$email, $tag, $marketplace]);
if ((int) $duplicate->fetchColumn() > 0) {
    echo json_encode(['success' => false,
        'message' => $tag . ' is already assigned to ' . $email . ' on ' . $marketplace . '.']);
    exit;
}

try {
    if ($share !== null) {
        $stmt = $pdo->prepare(
            'INSERT INTO user_tags (user_email, tracking_id, marketplace, share_percent) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$email, $tag, $marketplace, $share]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO user_tags (user_email, tracking_id, marketplace) VALUES (?, ?, ?)');
        $stmt->execute([$email, $tag, $marketplace]);
    }

    $said = $share !== null
        ? ' at ' . rtrim(rtrim(number_format($share, 2, '.', ''), '0'), '.') . '%'
        : '';
    echo json_encode(['success' => true, 'message' => $tag . ' assigned to ' . $email . $said . '.']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
