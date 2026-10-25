<?php
/**
 * Records that a contributor has been paid for a month — or takes that back.
 *
 * This is what makes "paid" mean anything. Until a row lands in payout_months the
 * import and delete screens have nothing to warn about, and a month that has been
 * transferred looks exactly like one that has not.
 *
 * The figure that was actually transferred is stored, not recalculated later. If the
 * month is re-imported afterwards the payout sheet compares the two and says so,
 * rather than quietly showing a number nobody was paid.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function paidRespond($success, $message, $status = 200, $extra = array())
{
    http_response_code($status);
    echo json_encode(array_merge(array('success' => $success, 'message' => $message), $extra), JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    paidRespond(false, 'Your admin session has expired. Please log in again.', 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paidRespond(false, 'Only POST requests are accepted.', 405);
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    paidRespond(false, 'Malformed request.', 400);
}

$token = isset($payload['csrf_token']) ? (string) $payload['csrf_token'] : '';
if (!isset($_SESSION['settings_csrf_token']) || !hash_equals($_SESSION['settings_csrf_token'], $token)) {
    paidRespond(false, 'Security token did not match. Reload the page and try again.', 403);
}

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../lib/payout.php';

$reportMonth = isset($payload['month']) ? trim((string) $payload['month']) : '';
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $reportMonth)) {
    paidRespond(false, 'Pick a single month before marking anything paid. "All months" cannot be paid as one.', 422);
}

$action = isset($payload['action']) && $payload['action'] === 'unpay' ? 'unpay' : 'pay';

// Either one person, or everybody who is owed something this month.
$targets = array();
if (isset($payload['emails']) && is_array($payload['emails'])) {
    foreach ($payload['emails'] as $candidate) {
        $candidate = strtolower(trim((string) $candidate));
        if ($candidate !== '') {
            $targets[$candidate] = true;
        }
    }
}
$payEveryone = empty($targets);

$paidOn = isset($payload['paid_on']) ? trim((string) $payload['paid_on']) : '';
if ($paidOn !== '') {
    $date = DateTime::createFromFormat('Y-m-d', $paidOn);
    if (!$date || $date->format('Y-m-d') !== $paidOn) {
        paidRespond(false, 'The payment date is not a valid date.', 422);
    }
} else {
    $paidOn = date('Y-m-d');
}

try {
    $payout = dd_payout_month($pdo, $reportMonth);
} catch (Throwable $error) {
    error_log('mark_paid.php: ' . $error->getMessage());
    paidRespond(false, 'The payout figures could not be rebuilt, so nothing was recorded.', 500);
}

$contributors = $payout['contributors'];

/* ---------------- take a payment back ---------------- */
if ($action === 'unpay') {
    $removed = 0;

    try {
        $pdo->beginTransaction();
        if ($payEveryone) {
            $statement = $pdo->prepare('DELETE FROM payout_months WHERE report_month = :m');
            $statement->execute(array(':m' => $reportMonth));
            $removed = $statement->rowCount();
        } else {
            $statement = $pdo->prepare(
                'DELETE FROM payout_months WHERE report_month = :m AND LOWER(user_email) = :e'
            );
            foreach (array_keys($targets) as $email) {
                $statement->execute(array(':m' => $reportMonth, ':e' => $email));
                $removed += $statement->rowCount();
            }
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('mark_paid.php unpay: ' . $error->getMessage());
        paidRespond(false, 'Could not undo the payment. Nothing was changed.', 500);
    }

    paidRespond(true, $removed === 0
        ? 'Nothing was marked paid for that month, so nothing changed.'
        : $removed . ' payment record' . ($removed === 1 ? '' : 's') . ' removed. Those contributors show as unpaid again.',
        200, array('removed' => $removed));
}

/* ---------------- record a payment ---------------- */
$toRecord = array();
foreach ($contributors as $email => $contributor) {
    if (!$payEveryone && !isset($targets[$email])) {
        continue;
    }
    // Somebody owed nothing does not need a payment record.
    if ($contributor['payable_usd'] <= 0) {
        continue;
    }
    $toRecord[$email] = $contributor;
}

if (empty($toRecord)) {
    paidRespond(false, $payEveryone
        ? 'Nobody is owed anything for ' . $reportMonth . ', so there is nothing to mark paid.'
        : 'That contributor is not owed anything for ' . $reportMonth . '.', 422);
}

try {
    $pdo->beginTransaction();

    // One row per person per month. Re-marking updates the stored figure rather
    // than creating a second record.
    $statement = $pdo->prepare(
        'INSERT INTO payout_months
            (report_month, user_id, user_email, gross_usd, payable_usd, status, paid_on, frozen_at)
         VALUES (:m, :uid, :email, :gross, :payable, "paid", :paid_on, NOW())
         ON DUPLICATE KEY UPDATE
            user_email  = VALUES(user_email),
            gross_usd   = VALUES(gross_usd),
            payable_usd = VALUES(payable_usd),
            status      = "paid",
            paid_on     = VALUES(paid_on),
            frozen_at   = NOW()'
    );

    $recorded = 0;
    $amount = 0.0;
    foreach ($toRecord as $contributor) {
        $statement->execute(array(
            ':m'       => $reportMonth,
            ':uid'     => (int) $contributor['id'],
            ':email'   => $contributor['email'],
            ':gross'   => $contributor['gross_usd'],
            ':payable' => $contributor['payable_usd'],
            ':paid_on' => $paidOn,
        ));
        $recorded++;
        $amount += $contributor['payable_usd'];
    }

    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('mark_paid.php pay: ' . $error->getMessage());
    paidRespond(false, 'Could not record the payment. Nothing was changed.', 500);
}

paidRespond(true,
    $recorded . ' contributor' . ($recorded === 1 ? '' : 's') . ' marked paid for ' . $reportMonth
    . ', totalling ' . dd_usd($amount) . '. Those figures are now fixed — if the month is re-imported later, '
    . 'the sheet will show that what was paid no longer matches.',
    200, array('recorded' => $recorded, 'amount' => round($amount, 2), 'paid_on' => $paidOn));
