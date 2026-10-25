<?php
/**
 * Imports one Amazon Associates report: one month, one country, one file.
 *
 * Two rules this endpoint exists to enforce:
 *
 *  1. REPLACE, NEVER ADD. Re-importing a month and country deletes what was there
 *     before and rebuilds it. The old uploader added to the existing figures, so
 *     uploading the same file twice silently doubled a month.
 *
 *  2. FREEZE AT IMPORT. The rate and the share used are written onto every row.
 *     Nothing downstream ever reads them from the settings tables again, so
 *     changing a percentage later cannot restate a month that has been paid.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

ini_set('display_errors', '0');
ob_start();

$GLOBALS['dd_import_replied'] = false;

function importRespond($success, $message, $status = 200, $extra = array())
{
    $GLOBALS['dd_import_replied'] = true;
    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode(array_merge(array('success' => $success, 'message' => $message), $extra), JSON_UNESCAPED_SLASHES);
    exit;
}

// A fatal error must still produce JSON, otherwise the screen shows a blank failure.
register_shutdown_function(function () {
    if (!empty($GLOBALS['dd_import_replied'])) {
        return;
    }
    $error = error_get_last();
    if ($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        error_log('import_report.php fatal: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
        if (ob_get_level() > 0) {
            ob_clean();
        }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo '{"success":false,"message":"A server error stopped the import. Nothing was changed. Check the PHP error log."}';
    }
});

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    importRespond(false, 'Your admin session has expired. Please log in again.', 401);
}

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../lib/report_reader.php';
require_once __DIR__ . '/../lib/countries.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    importRespond(false, 'Only POST requests are accepted.', 405);
}

$token = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
if (!isset($_SESSION['settings_csrf_token']) || !hash_equals($_SESSION['settings_csrf_token'], $token)) {
    importRespond(false, 'Security token did not match. Reload the page and try again.', 403);
}

/* ---------------- what the form told us ---------------- */

$reportMonth = isset($_POST['report_month']) ? trim((string) $_POST['report_month']) : '';
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $reportMonth)) {
    importRespond(false, 'Choose which month this report is for.', 422);
}

/* The month we are in is fine — reports are pulled mid-month and a file downloaded on
   the 4th holds the 1st to the 3rd. A month that has not begun is not, and filing a
   report under one would hide it from the sheet until that month came around. */
if ($reportMonth > date('Y-m')) {
    importRespond(false,
        $reportMonth . ' has not started yet, so there is no report for it. '
        . 'The latest month you can upload is ' . date('F Y') . '.', 422);
}

$marketplace = isset($_POST['marketplace']) ? trim((string) $_POST['marketplace']) : '';
$country = dd_country($pdo, $marketplace);
if (!$country) {
    importRespond(false, 'Choose which country this report is from.', 422);
}
if ((int) $country['is_active'] !== 1) {
    importRespond(false, $country['country_label'] . ' is switched off on the Countries & rates screen. Switch it on before importing.', 422);
}

/* The currency is chosen on the upload screen. It defaults to whatever the country
   normally pays in, but a report can legitimately arrive in something else, so the
   form wins — provided the currency is one we hold a rate for. Picking one the
   country does not use is recorded rather than blocked; the screen warns first. */
$currency = isset($_POST['currency']) ? strtoupper(trim((string) $_POST['currency'])) : '';

if ($currency === '') {
    $currency = $country['currency'];
} else {
    $known = $pdo->prepare('SELECT COUNT(*) FROM exchange_rates WHERE currency = :c');
    $known->execute(array(':c' => $currency));
    if ((int) $known->fetchColumn() === 0) {
        importRespond(false,
            'There is no exchange rate stored for ' . $currency
            . '. Add it on the Countries & rates screen before importing in that currency.', 422);
    }
}

$currencyOverridden = $currency !== $country['currency'];

/* How far into the month this file reaches. A report pulled on the 4th holds the 1st
   to the 3rd, and saying so is what stops "July: $1,322" being read as a whole month
   when it is only five days of one. */
$monthStart = $reportMonth . '-01';
$monthEnd   = date('Y-m-t', strtotime($monthStart));
$coversTo   = isset($_POST['covers_to']) ? trim((string) $_POST['covers_to']) : '';

if ($coversTo !== '') {
    $date = DateTime::createFromFormat('Y-m-d', $coversTo);
    if (!$date || $date->format('Y-m-d') !== $coversTo) {
        importRespond(false, 'The "data goes up to" date is not a valid date.', 422);
    }
    if ($coversTo < $monthStart || $coversTo > $monthEnd) {
        importRespond(false,
            'The "data goes up to" date must fall inside ' . $reportMonth
            . ' — between ' . $monthStart . ' and ' . $monthEnd . '.', 422);
    }
    // A running month cannot be claimed past today, or a few days of figures get read
    // as a whole month and the sheet stops flagging it as partial.
    if ($coversTo > date('Y-m-d')) {
        importRespond(false,
            'The "data goes up to" date is in the future. ' . $reportMonth
            . ' is still running — it can only reach ' . date('Y-m-d') . ' so far.', 422);
    }
} else {
    // Not given: assume the whole month if it has ended, otherwise yesterday.
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $coversTo = ($yesterday < $monthEnd) ? max($monthStart, $yesterday) : $monthEnd;
}

$isPartial = $coversTo < $monthEnd;

$rateRaw = isset($_POST['rate_to_usd']) ? trim((string) $_POST['rate_to_usd']) : '';
if ($rateRaw === '' || !is_numeric($rateRaw)) {
    importRespond(false, 'The rate to USD must be a number.', 422);
}
$rate = round((float) $rateRaw, 6);
if ($rate <= 0 || $rate > 1000) {
    importRespond(false, 'The rate to USD (' . $rate . ') looks wrong. Enter how much one ' . $currency . ' is worth in USD.', 422);
}

/* The share is no longer asked for on the upload screen. Commission belongs to the
   person now, so what somebody is paid comes from users.share_percent and not from
   anything frozen here. The country's figure is still written onto the rows as the
   record of what that country was set to at the time — it just no longer decides money.
   A posted value is still honoured, so an older client or a script does not break. */
$shareRaw = isset($_POST['share_percent']) ? trim((string) $_POST['share_percent']) : '';
if ($shareRaw === '') {
    $share = round((float) $country['share_percent'], 2);
} else {
    if (!is_numeric($shareRaw)) {
        importRespond(false, 'The share % must be a number.', 422);
    }
    $share = round((float) $shareRaw, 2);
    if ($share < 0 || $share > 100) {
        importRespond(false, 'The share % must be between 0 and 100.', 422);
    }
}

$rateSource = ($currencyOverridden
               || abs($rate - (float) dd_default_rate($pdo, $currency)['rate_to_usd']) > 0.0000005)
            ? 'overridden' : 'default';

/* Re-uploading the same month/country is the normal daily refresh workflow.
   It is intentionally idempotent: the latest month-to-date export replaces the
   previous snapshot instead of being added on top. A paid month is the only case
   that still needs an explicit warning because its live figures may drift from what
   contributors were already paid. */
$confirmPaid = !empty($_POST['confirm_paid']);

/* ---------------- the file ---------------- */

if (!isset($_FILES['report_file']) || (int) $_FILES['report_file']['error'] !== UPLOAD_ERR_OK) {
    importRespond(false, 'No report file was received.', 422);
}

$upload = $_FILES['report_file'];
$originalName = basename((string) $upload['name']);
$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

if (!in_array($extension, array('csv', 'xlsx'), true)) {
    importRespond(false, 'Unsupported file type. Upload a .csv or .xlsx file.', 422);
}
if ((int) $upload['size'] > 20 * 1024 * 1024) {
    importRespond(false, 'The report is larger than the supported 20 MB limit.', 422);
}

try {
    $rows = dd_read_report_rows($upload['tmp_name'], $extension);
} catch (Throwable $error) {
    importRespond(false, $error->getMessage(), 422);
}

if (empty($rows)) {
    importRespond(false, 'The report file is empty.', 422);
}

$header = array_shift($rows);
$normalizedHeader = array();
foreach ($header as $heading) {
    $normalizedHeader[] = dd_normalize_header($heading);
}

$columnMap = dd_map_columns($normalizedHeader);
if ($columnMap['tracking_id'] === false || $columnMap['total_earnings'] === false) {
    importRespond(false,
        'The report must contain a "Tracking Id" column and a "Total Earnings" column.',
        422, array('detected_headers' => $normalizedHeader));
}

/* The guard the client asked for: when the file's language names exactly one Amazon
   store and the admin has picked a different one, refuse rather than file the money
   under the wrong country. */
$detection = dd_detect_from_header($normalizedHeader);
if ($detection['certain'] && $detection['marketplace'] !== $marketplace) {
    $detected = dd_country($pdo, $detection['marketplace']);
    importRespond(false,
        'This file is written in ' . $detection['language'] . ', which only '
        . ($detected ? $detected['country_label'] : $detection['marketplace'])
        . ' produces, but ' . $country['country_label'] . ' is selected. '
        . 'The import was refused rather than filing this money under the wrong country.',
        422);
}

/* ---------------- what this import is about to overwrite ---------------- */

$existing = $pdo->prepare(
    'SELECT id, filename, uploaded_at, rows_processed
     FROM report_uploads WHERE report_month = :m AND marketplace = :k ORDER BY id DESC'
);
$existing->execute(array(':m' => $reportMonth, ':k' => $marketplace));
$previousUploads = $existing->fetchAll(PDO::FETCH_ASSOC);

/* Do NOT stop for an existing snapshot. Daily/month-to-date reports are expected
   to be re-uploaded repeatedly. The transaction below clears only this month + this
   country and rebuilds it from the newest file, so the same data can never double. */

$paidStatement = $pdo->prepare(
    'SELECT COUNT(*) FROM payout_months WHERE report_month = :m AND status = "paid"'
);
$paidStatement->execute(array(':m' => $reportMonth));
$paidCount = (int) $paidStatement->fetchColumn();

if ($paidCount > 0 && !$confirmPaid) {
    importRespond(false,
        $paidCount . ' contributor' . ($paidCount === 1 ? ' has' : 's have')
        . ' already been marked paid for ' . $reportMonth
        . '. This daily refresh will replace the current ' . $country['country_label']
        . ' snapshot and may change the live figures for a month that has already been paid. '
        . 'Paid amounts stay recorded, but any difference will be flagged. Confirm to continue.',
        409, array('needs_confirm' => 'paid'));
}

/* ---------------- import ---------------- */

$uploadedBy = isset($_SESSION['admin_name']) && $_SESSION['admin_name'] !== ''
    ? $_SESSION['admin_name'] : 'Admin';

$replacedId = !empty($previousUploads) ? (int) $previousUploads[0]['id'] : null;
$removedRows = 0;

try {
    $pdo->beginTransaction();

    // Clear this month + country completely. Because one file is exactly one
    // (month, country), a delete-then-insert is all that is needed — none of the
    // old add-and-subtract reversal arithmetic applies any more.
    if (!empty($previousUploads)) {
        $ids = array_map('intval', array_column($previousUploads, 'id'));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $pdo->prepare("DELETE FROM report_upload_items WHERE upload_id IN ($placeholders)")->execute($ids);
        $pdo->prepare("DELETE FROM report_uploads WHERE id IN ($placeholders)")->execute($ids);
    }

    $clear = $pdo->prepare(
        'DELETE FROM tracking_report_monthly WHERE report_month = :m AND marketplace = :k'
    );
    $clear->execute(array(':m' => $reportMonth, ':k' => $marketplace));
    $removedRows = $clear->rowCount();

    $logStatement = $pdo->prepare(
        'INSERT INTO report_uploads
            (filename, report_month, covers_to, marketplace, currency, rate_to_usd, share_percent,
             rate_source, replaced_upload_id, rows_processed, uploaded_by, uploaded_at)
         VALUES (:f, :m, :cov, :k, :c, :r, :s, :src, :rep, 0, :by, NOW())'
    );
    $logStatement->execute(array(
        ':f' => $originalName, ':m' => $reportMonth, ':cov' => $coversTo,
        ':k' => $marketplace, ':c' => $currency,
        ':r' => $rate, ':s' => $share, ':src' => $rateSource, ':rep' => $replacedId,
        ':by' => $uploadedBy,
    ));
    $uploadId = (int) $pdo->lastInsertId();

    // A tracking ID can legitimately appear twice in one export, so within a single
    // file the figures are summed. Across files it can never happen: the slate for
    // this month and country was wiped above.
    $monthly = $pdo->prepare(
        'INSERT INTO tracking_report_monthly
            (tracking_id, marketplace, currency, rate_to_usd, share_percent, report_month,
             clicks, items_ordered, ordered_revenue, items_shipped, items_returned,
             items_shipped_revenue, items_returned_revenue, total_earnings, bonus,
             items_shipped_earnings, items_returned_earnings, last_updated)
         VALUES (:tag, :mk, :cur, :rate, :share, :month,
             :clicks, :ordered, :ord_rev, :shipped, :returned,
             :shp_rev, :ret_rev, :total, :bonus, :shp_earn, :ret_earn, NOW())
         ON DUPLICATE KEY UPDATE
             clicks = clicks + VALUES(clicks),
             items_ordered = items_ordered + VALUES(items_ordered),
             ordered_revenue = ordered_revenue + VALUES(ordered_revenue),
             items_shipped = items_shipped + VALUES(items_shipped),
             items_returned = items_returned + VALUES(items_returned),
             items_shipped_revenue = items_shipped_revenue + VALUES(items_shipped_revenue),
             items_returned_revenue = items_returned_revenue + VALUES(items_returned_revenue),
             total_earnings = total_earnings + VALUES(total_earnings),
             bonus = bonus + VALUES(bonus),
             items_shipped_earnings = items_shipped_earnings + VALUES(items_shipped_earnings),
             items_returned_earnings = items_returned_earnings + VALUES(items_returned_earnings),
             last_updated = NOW()'
    );

    $item = $pdo->prepare(
        'INSERT INTO report_upload_items
            (upload_id, tracking_id, marketplace, currency, rate_to_usd, share_percent, report_month,
             clicks, items_ordered, ordered_revenue, items_shipped, items_returned,
             items_shipped_revenue, items_returned_revenue, total_earnings, bonus,
             items_shipped_earnings, items_returned_earnings)
         VALUES (:upload, :tag, :mk, :cur, :rate, :share, :month,
             :clicks, :ordered, :ord_rev, :shipped, :returned,
             :shp_rev, :ret_rev, :total, :bonus, :shp_earn, :ret_earn)'
    );

    $trackingIndex = $columnMap['tracking_id'];
    $processed = 0;
    $earningsTotal = 0.0;

    foreach ($rows as $row) {
        $trackingId = isset($row[$trackingIndex]) ? trim((string) $row[$trackingIndex]) : '';
        if ($trackingId === '') {
            continue;
        }
        if (strlen($trackingId) > 191) {
            throw new RuntimeException('A tracking ID is longer than 191 characters: ' . substr($trackingId, 0, 60));
        }

        $values = array(
            ':tag' => $trackingId, ':mk' => $marketplace, ':cur' => $currency,
            ':rate' => $rate, ':share' => $share, ':month' => $reportMonth,
            ':clicks'   => (int) dd_numeric_value($row, $columnMap['clicks']),
            ':ordered'  => (int) dd_numeric_value($row, $columnMap['items_ordered']),
            ':ord_rev'  => dd_numeric_value($row, $columnMap['ordered_revenue']),
            ':shipped'  => (int) dd_numeric_value($row, $columnMap['items_shipped']),
            ':returned' => (int) dd_numeric_value($row, $columnMap['items_returned']),
            ':shp_rev'  => dd_numeric_value($row, $columnMap['items_shipped_revenue']),
            ':ret_rev'  => dd_numeric_value($row, $columnMap['items_returned_revenue']),
            ':total'    => dd_numeric_value($row, $columnMap['total_earnings']),
            ':bonus'    => dd_numeric_value($row, $columnMap['bonus']),
            ':shp_earn' => dd_numeric_value($row, $columnMap['items_shipped_earnings']),
            ':ret_earn' => dd_numeric_value($row, $columnMap['items_returned_earnings']),
        );

        $monthly->execute($values);
        $item->execute(array_merge($values, array(':upload' => $uploadId)));

        $earningsTotal += $values[':total'];
        $processed++;
    }

    if ($processed === 0) {
        throw new RuntimeException('No rows with a tracking ID were found in this file.');
    }

    $pdo->prepare('UPDATE report_uploads SET rows_processed = :n WHERE id = :id')
        ->execute(array(':n' => $processed, ':id' => $uploadId));

    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('import_report.php: ' . $error->getMessage());
    importRespond(false, 'The import failed and nothing was changed. ' . $error->getMessage(), 500);
}

/* Keep the original. The six files uploaded before the rebuild were read and thrown
   away, so when their month turned out to be wrong there was nothing left to check.
   Done after the commit: the figures matter, the archive copy is a convenience. */
$storedFile = dd_archive_source_file($upload['tmp_name'], $uploadId, $reportMonth, $marketplace, $originalName);
if ($storedFile !== '') {
    try {
        $pdo->prepare('UPDATE report_uploads SET stored_file = :f WHERE id = :id')
            ->execute(array(':f' => $storedFile, ':id' => $uploadId));
    } catch (Throwable $error) {
        error_log('import_report.php: could not record stored_file — ' . $error->getMessage());
    }
}

/* The whole file converted to USD. Deliberately not shared out here: with commission
   held per person, no single percentage describes this file any more. Who gets what is
   worked out on the payout sheet, from each contributor's own rate. */
$grossUsd = round($earningsTotal * $rate, 2);

$summary = number_format($processed) . ' tracking ID' . ($processed === 1 ? '' : 's') . ' imported for '
         . $reportMonth . ' · ' . $country['country_label'] . ' in ' . $currency . '.';
if ($isPartial) {
    $summary .= ' This file covers up to ' . date('j M', strtotime($coversTo))
              . ' only, so ' . $country['country_label'] . ' is marked partial for this month'
              . ' — upload it again later to bring it up to date.';
}
if ($currencyOverridden) {
    $summary .= ' Note that ' . $country['country_label'] . ' normally pays in '
              . $country['currency'] . ' — this import used ' . $currency . ' as selected.';
}
if ($removedRows > 0) {
    $summary .= ' Daily refresh complete: ' . number_format($removedRows) . ' earlier row'
              . ($removedRows === 1 ? '' : 's')
              . ' for this month and country were replaced by this latest snapshot, not added to.';
}

importRespond(true, $summary, 200, array(
    'upload_id'      => $uploadId,
    'report_month'   => $reportMonth,
    'marketplace'    => $marketplace,
    'country'        => $country['country_label'],
    'currency'       => $currency,
    'rate_to_usd'    => $rate,
    'share_percent'  => $share,
    'rate_source'    => $rateSource,
    'rows_processed' => $processed,
    'rows_replaced'  => $removedRows,
    'refreshed_existing' => !empty($previousUploads),
    'covers_to'      => $coversTo,
    'partial'        => $isPartial,
    'earnings_total' => round($earningsTotal, 2),
    'gross_usd'      => $grossUsd,
));
