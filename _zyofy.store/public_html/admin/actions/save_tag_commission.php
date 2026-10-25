<?php
/**
 * Sets the commission on one tag assignment.
 *
 * Commission belongs to the assignment, so this changes what its holder earns on that
 * tracking ID in that country and nothing else — their other tags keep their own rates.
 *
 * The reply says what the change is worth per month so the effect is visible before
 * anybody goes looking for it on the payout sheet. Months already marked paid are
 * called out separately: the amount transferred stands, and the sheet flags the
 * divergence rather than quietly restating it.
 */

require_once __DIR__ . '/guard.php';

// This endpoint decides what somebody is paid, so it takes the token as well as the
// session. Without it a cross-site post could quietly move anybody's commission.
$payload = dd_guard_admin_json();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function tagCommissionRespond($success, $message, $status = 200, $extra = array())
{
    http_response_code($status);
    echo json_encode(array_merge(array('success' => $success, 'message' => $message), $extra));
    exit;
}

require_once __DIR__ . '/../../db.php';

$hasShare = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_tags'
      AND COLUMN_NAME = 'share_percent'")->fetchColumn() > 0;
if (!$hasShare) {
    tagCommissionRespond(false,
        'Per-tag commission is not set up yet. Run migrations/008_per_tag_commission.php once.', 409);
}

$id = isset($payload['id']) ? (int) $payload['id'] : 0;
if ($id <= 0) {
    tagCommissionRespond(false, 'Which tag is this for?', 422);
}

$raw = isset($payload['share']) ? trim((string) $payload['share']) : '';
if ($raw === '' || !is_numeric($raw)) {
    tagCommissionRespond(false, 'The commission must be a number.', 422);
}
$share = round((float) $raw, 2);
if ($share < 0 || $share > 100) {
    tagCommissionRespond(false, 'The commission must be between 0 and 100 percent.', 422);
}

$statement = $pdo->prepare('SELECT id, user_email, tracking_id, marketplace, share_percent
                            FROM user_tags WHERE id = :id');
$statement->execute(array(':id' => $id));
$assignment = $statement->fetch(PDO::FETCH_ASSOC);
if (!$assignment) {
    tagCommissionRespond(false, 'That tag assignment no longer exists. Reload the page.', 404);
}

$before = (float) $assignment['share_percent'];

/* Trailing zeros go only after a decimal point: trimming them unconditionally reads 70
   as 7, which is the kind of wrong that looks plausible on screen. */
function tagPct($value)
{
    $text = number_format((float) $value, 2, '.', '');
    if (strpos($text, '.') !== false) {
        $text = rtrim(rtrim($text, '0'), '.');
    }
    return $text . '%';
}

$whoStatement = $pdo->prepare('SELECT name FROM users WHERE email = :e LIMIT 1');
$whoStatement->execute(array(':e' => $assignment['user_email']));
$who = (string) $whoStatement->fetchColumn();
if ($who === '') {
    $who = (string) $assignment['user_email'];
}

if (abs($before - $share) < 0.005) {
    tagCommissionRespond(true, $assignment['tracking_id'] . ' is already on ' . tagPct($share) . '. Nothing changed.');
}

/* What this is worth, month by month, before it is written. Only this assignment's own
   rows count — the tag on this marketplace. */
$impact = array();
try {
    $rows = $pdo->prepare(
        'SELECT report_month, SUM(total_earnings * rate_to_usd) AS gross
         FROM tracking_report_monthly
         WHERE tracking_id = :t AND marketplace = :m AND currency <> \'\'
         GROUP BY report_month ORDER BY report_month'
    );
    $rows->execute(array(':t' => $assignment['tracking_id'], ':m' => $assignment['marketplace']));

    $paidMonths = array();
    foreach ($pdo->query('SELECT report_month FROM payout_months WHERE status = "paid"') as $paid) {
        $paidMonths[(string) $paid['report_month']] = true;
    }

    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $gross = (float) $row['gross'];
        if ($gross <= 0) {
            continue;
        }
        $impact[] = array(
            'month' => (string) $row['report_month'],
            'was'   => $gross * $before / 100,
            'now'   => $gross * $share / 100,
            'paid'  => isset($paidMonths[(string) $row['report_month']]),
        );
    }
} catch (Throwable $error) {
    error_log('save_tag_commission.php preview: ' . $error->getMessage());
}

try {
    $update = $pdo->prepare('UPDATE user_tags SET share_percent = :s WHERE id = :id');
    $update->execute(array(':s' => $share, ':id' => $id));
} catch (Throwable $error) {
    error_log('save_tag_commission.php: ' . $error->getMessage());
    tagCommissionRespond(false, 'Could not save. Nothing was changed.', 500);
}

$message = $assignment['tracking_id'] . ' (' . $who . ') moved from '
         . tagPct($before) . ' to ' . tagPct($share) . '.';

$open = array();
$locked = array();
foreach ($impact as $row) {
    $line = $row['month'] . ' $' . number_format($row['was'], 2) . ' to $' . number_format($row['now'], 2);
    if ($row['paid']) {
        $locked[] = $line;
    } else {
        $open[] = $line;
    }
}
if ($open) {
    $message .= ' Unpaid months change: ' . implode(', ', $open) . '.';
}
if ($locked) {
    $message .= ' Already paid, so the amount transferred stands: ' . implode(', ', $locked)
              . ' — the payout sheet flags the difference.';
}
if (!$open && !$locked) {
    $message .= ' This tag has not earned anything yet, so no month changes.';
}

tagCommissionRespond(true, $message, 200, array(
    'id'    => $id,
    'share' => number_format($share, 2, '.', ''),
));
