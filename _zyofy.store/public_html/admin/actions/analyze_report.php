<?php
/**
 * Looks at an uploaded report and reports what it is — without writing anything.
 *
 * The upload screen calls this the moment a file is chosen, so the admin sees the
 * country, the currency, the rate and the share that are about to be used, and is
 * warned if that month and country have already been imported.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function analyzeRespond($success, $message, $status = 200, $extra = array())
{
    http_response_code($status);
    echo json_encode(array_merge(array('success' => $success, 'message' => $message), $extra), JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    analyzeRespond(false, 'Your admin session has expired. Please log in again.', 401);
}

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../lib/report_reader.php';
require_once __DIR__ . '/../lib/countries.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    analyzeRespond(false, 'Only POST requests are accepted.', 405);
}

$reportMonth = isset($_POST['report_month']) ? trim((string) $_POST['report_month']) : '';
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $reportMonth)) {
    $reportMonth = '';
}

if (!isset($_FILES['report_file']) || !is_array($_FILES['report_file'])) {
    analyzeRespond(false, 'No file was received.', 422);
}

$upload = $_FILES['report_file'];
if ((int) $upload['error'] !== UPLOAD_ERR_OK) {
    analyzeRespond(false, 'The file did not upload correctly (error code ' . (int) $upload['error'] . ').', 422);
}

$originalName = basename((string) $upload['name']);
$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
if (!in_array($extension, array('csv', 'xlsx'), true)) {
    analyzeRespond(false, 'Unsupported file type. Upload a .csv or .xlsx file (an old .xls will not work).', 422);
}

if ((int) $upload['size'] > 20 * 1024 * 1024) {
    analyzeRespond(false, 'The report is larger than the supported 20 MB limit.', 422);
}

try {
    $rows = dd_read_report_rows($upload['tmp_name'], $extension);
} catch (Throwable $error) {
    analyzeRespond(false, $error->getMessage(), 422);
}

if (empty($rows)) {
    analyzeRespond(false, 'The report file is empty.', 422);
}

$header = array_shift($rows);
$normalizedHeader = array();
foreach ($header as $heading) {
    $normalizedHeader[] = dd_normalize_header($heading);
}

$columnMap = dd_map_columns($normalizedHeader);
if ($columnMap['tracking_id'] === false || $columnMap['total_earnings'] === false) {
    analyzeRespond(false,
        'This does not look like an Amazon Associates earnings report — it needs a "Tracking Id" column and a "Total Earnings" column.',
        422,
        array('detected_headers' => $normalizedHeader)
    );
}

// How many rows actually carry a tracking ID, so the admin can sanity-check the
// file size before importing.
$dataRows = 0;
$trackingIndex = $columnMap['tracking_id'];
foreach ($rows as $row) {
    if (isset($row[$trackingIndex]) && trim((string) $row[$trackingIndex]) !== '') {
        $dataRows++;
    }
}

// Headings and file name together — Canada, the UK, Australia and India all write
// English, so the name is often the only thing that tells them apart.
$detection = dd_detect_country($pdo, $normalizedHeader, $originalName);
$countries = dd_all_countries($pdo);

$marketplace = $detection['marketplace'];
if ($marketplace === null || !isset($countries[$marketplace])) {
    $marketplace = null;
}

/* If the screen already has a country selected, the "already imported" warning must
   be about THAT country, not about whatever was detected. Otherwise correcting the
   dropdown leaves a warning on screen describing a different country entirely. */
$selected = isset($_POST['marketplace']) ? trim((string) $_POST['marketplace']) : '';
$lookupMarketplace = ($selected !== '' && isset($countries[$selected])) ? $selected : $marketplace;

$suggestion = array(
    'marketplace'  => $marketplace,
    'country'      => $marketplace ? $countries[$marketplace]['country_label'] : null,
    'currency'     => $marketplace ? $countries[$marketplace]['currency'] : null,
    'share_percent'=> $marketplace ? (float) $countries[$marketplace]['share_percent'] : null,
    'certain'      => (bool) $detection['certain'],
    'language'     => $detection['language'],
    'from_filename'=> $detection['filename_word'],
    'conflict'     => $detection['conflict'],
);

$rate = null;
$rateConfirmed = false;
if ($suggestion['currency'] !== null) {
    $rateRow = dd_default_rate($pdo, $suggestion['currency']);
    $rate = $rateRow['rate_to_usd'] !== null ? (float) $rateRow['rate_to_usd'] : null;
    $rateConfirmed = (int) $rateRow['is_confirmed'] === 1;
}

$reason = $marketplace
    ? dd_detection_reason($detection, $suggestion['country'], $suggestion['currency'])
    : $detection['reason'];

/* Has this month + country already been imported, and has it been paid? Both
   change what the Import button is about to do. */
$existing = null;
$paidFor  = array();

if ($reportMonth !== '' && $lookupMarketplace !== null) {
    $statement = $pdo->prepare(
        'SELECT id, filename, uploaded_at, rows_processed, rate_to_usd, share_percent
         FROM report_uploads
         WHERE report_month = :m AND marketplace = :k
         ORDER BY id DESC LIMIT 1'
    );
    $statement->execute(array(':m' => $reportMonth, ':k' => $lookupMarketplace));
    $existing = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
}

/* Which countries of this month are already in, so the screen can show progress
   across a month that takes six separate files. */
$monthProgress = array();
if ($reportMonth !== '') {
    $statement = $pdo->prepare(
        'SELECT marketplace, currency, rate_to_usd, share_percent, rows_processed, uploaded_at, filename, covers_to
         FROM report_uploads WHERE report_month = :m ORDER BY uploaded_at'
    );
    $statement->execute(array(':m' => $reportMonth));
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $monthProgress[$row['marketplace']] = $row;
    }
}

if ($reportMonth !== '') {
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM payout_months WHERE report_month = :m AND status = "paid"'
    );
    $statement->execute(array(':m' => $reportMonth));
    $paidFor = (int) $statement->fetchColumn();
}

analyzeRespond(true, 'File read successfully.', 200, array(
    'filename'        => $originalName,
    'size_bytes'      => (int) $upload['size'],
    'data_rows'       => $dataRows,
    'headers'         => $normalizedHeader,
    'suggestion'      => $suggestion,
    'reason'          => $reason,
    'rate_to_usd'     => $rate,
    'rate_confirmed'  => $rateConfirmed,
    'already_imported'=> $existing,
    'month_paid_count'=> $paidFor,
    'month_progress'  => $monthProgress,
));
