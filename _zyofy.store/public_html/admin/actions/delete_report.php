<?php
/**
 * Removes one report upload and everything it contributed.
 *
 * Two paths, because there are two kinds of upload in the system:
 *
 *  NEW STYLE — the upload knows its country. One upload is exactly one
 *  (month, country), and the importer already wipes that combination before
 *  writing, so there can only ever be one upload behind those rows. Deleting is
 *  therefore a plain DELETE of that month and country. No arithmetic, nothing to
 *  get wrong.
 *
 *  LEGACY — uploaded before the rebuild, with no country recorded. Those rows are
 *  still unpicked by subtracting each stored item, which is all that can be done
 *  when several files were added on top of one another.
 *
 * The old version subtracted with "WHERE tracking_id = ? AND report_month = ?".
 * Monthly rows are now unique on (tracking_id, report_month, marketplace), so a
 * tracking ID appearing in two countries would have had the same figures taken off
 * BOTH rows. The marketplace is now part of every match.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

function deleteReportJson($success, $message, $statusCode, $extra)
{
    if (ob_get_level() > 0) {
        ob_clean();
    }

    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $payload = array_merge(array(
        'success' => (bool) $success,
        'message' => (string) $message,
    ), is_array($extra) ? $extra : array());

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    echo $json === false
        ? '{"success":false,"message":"The server could not encode its response."}'
        : $json;
    exit;
}

/** Drops rows the reversal emptied out, matched on all three key columns. */
function cleanupReversedRows($pdo, $affectedRows)
{
    $deleteEmptyMonth = $pdo->prepare("
        DELETE FROM tracking_report_monthly
        WHERE tracking_id = :tracking_id
          AND report_month = :report_month
          AND marketplace = :marketplace
          AND clicks = 0
          AND items_ordered = 0
          AND ordered_revenue = 0
          AND items_shipped = 0
          AND items_returned = 0
          AND items_shipped_revenue = 0
          AND items_returned_revenue = 0
          AND total_earnings = 0
          AND bonus = 0
          AND items_shipped_earnings = 0
          AND items_returned_earnings = 0
    ");

    foreach ($affectedRows as $row) {
        $deleteEmptyMonth->execute(array(
            ':tracking_id'  => $row['tracking_id'],
            ':report_month' => $row['report_month'],
            ':marketplace'  => isset($row['marketplace']) ? $row['marketplace'] : '',
        ));
    }
}

try {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db.php';

    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        deleteReportJson(false, 'Your admin session has expired. Please log in again.', 401, array());
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        deleteReportJson(false, 'Only POST requests are accepted.', 405, array());
    }

    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $uploadId = isset($input['upload_id']) ? (int) $input['upload_id'] : 0;
    $csrfToken = isset($input['csrf_token']) ? (string) $input['csrf_token'] : '';
    $confirmPaid = !empty($input['confirm_paid']);

    if ($uploadId <= 0) {
        deleteReportJson(false, 'A valid upload ID is required.', 422, array());
    }

    if (!isset($_SESSION['report_csrf_token'])
        || !hash_equals($_SESSION['report_csrf_token'], $csrfToken)) {
        deleteReportJson(false, 'The security token is invalid. Refresh the page and try again.', 403, array());
    }

    $pdo->beginTransaction();

    $uploadStatement = $pdo->prepare("
        SELECT id, filename, report_month, marketplace, currency, rows_processed
        FROM report_uploads
        WHERE id = :id
        FOR UPDATE
    ");
    $uploadStatement->execute(array(':id' => $uploadId));
    $upload = $uploadStatement->fetch(PDO::FETCH_ASSOC);

    if (!$upload) {
        $pdo->rollBack();
        deleteReportJson(false, 'This upload no longer exists. It may already have been deleted.', 404, array());
    }

    $reportMonth = (string) $upload['report_month'];
    $marketplace = isset($upload['marketplace']) ? (string) $upload['marketplace'] : '';

    // Deleting a month somebody has been paid for changes what their report says
    // after the fact, so it needs saying out loud first.
    if ($reportMonth !== '') {
        $paidStatement = $pdo->prepare(
            'SELECT COUNT(*) FROM payout_months WHERE report_month = :m AND status = "paid"'
        );
        $paidStatement->execute(array(':m' => $reportMonth));
        $paidCount = (int) $paidStatement->fetchColumn();

        if ($paidCount > 0 && !$confirmPaid) {
            $pdo->rollBack();
            deleteReportJson(false,
                $paidCount . ' contributor' . ($paidCount === 1 ? ' has' : 's have')
                . ' already been marked paid for ' . $reportMonth
                . '. Deleting this upload changes what their report says after they were paid. Confirm to continue.',
                409, array('needs_confirm' => 'paid'));
        }
    }

    $rowsRemoved = 0;

    if ($marketplace !== '') {
        /* ---------- new style: one upload IS one month and country ---------- */
        $deleteMode = 'month-country';

        $clear = $pdo->prepare(
            'DELETE FROM tracking_report_monthly
             WHERE report_month = :report_month AND marketplace = :marketplace'
        );
        $clear->execute(array(':report_month' => $reportMonth, ':marketplace' => $marketplace));
        $rowsRemoved = $clear->rowCount();
    } else {
        /* ---------- legacy: unpick it item by item ---------- */
        $itemStatement = $pdo->prepare("
            SELECT tracking_id, report_month, marketplace,
                   clicks, items_ordered, ordered_revenue,
                   items_shipped, items_returned,
                   items_shipped_revenue, items_returned_revenue,
                   total_earnings, bonus,
                   items_shipped_earnings, items_returned_earnings
            FROM report_upload_items
            WHERE upload_id = :upload_id
            FOR UPDATE
        ");
        $itemStatement->execute(array(':upload_id' => $uploadId));
        $items = $itemStatement->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($items)) {
            $deleteMode = 'exact';

            $reverseMonthly = $pdo->prepare("
                UPDATE tracking_report_monthly
                SET clicks = clicks - :clicks,
                    items_ordered = items_ordered - :items_ordered,
                    ordered_revenue = ordered_revenue - :ordered_revenue,
                    items_shipped = items_shipped - :items_shipped,
                    items_returned = items_returned - :items_returned,
                    items_shipped_revenue = items_shipped_revenue - :items_shipped_revenue,
                    items_returned_revenue = items_returned_revenue - :items_returned_revenue,
                    total_earnings = total_earnings - :total_earnings,
                    bonus = bonus - :bonus,
                    items_shipped_earnings = items_shipped_earnings - :items_shipped_earnings,
                    items_returned_earnings = items_returned_earnings - :items_returned_earnings,
                    last_updated = NOW()
                WHERE tracking_id = :tracking_id
                  AND report_month = :report_month
                  AND marketplace = :marketplace
            ");

            foreach ($items as $item) {
                $reverseMonthly->execute(array(
                    ':clicks' => $item['clicks'],
                    ':items_ordered' => $item['items_ordered'],
                    ':ordered_revenue' => $item['ordered_revenue'],
                    ':items_shipped' => $item['items_shipped'],
                    ':items_returned' => $item['items_returned'],
                    ':items_shipped_revenue' => $item['items_shipped_revenue'],
                    ':items_returned_revenue' => $item['items_returned_revenue'],
                    ':total_earnings' => $item['total_earnings'],
                    ':bonus' => $item['bonus'],
                    ':items_shipped_earnings' => $item['items_shipped_earnings'],
                    ':items_returned_earnings' => $item['items_returned_earnings'],
                    ':tracking_id' => $item['tracking_id'],
                    ':report_month' => $item['report_month'],
                    ':marketplace' => isset($item['marketplace']) ? $item['marketplace'] : '',
                ));
                $rowsRemoved += $reverseMonthly->rowCount();
            }

            cleanupReversedRows($pdo, $items);
        } else {
            // No stored items at all. The only safe reversal is wiping the month,
            // and only when this upload is the sole one logged against it.
            if ($reportMonth === '') {
                $pdo->rollBack();
                deleteReportJson(false, 'This is a legacy upload without month details, so it cannot be reversed safely.', 409, array());
            }

            $monthCountStatement = $pdo->prepare(
                'SELECT COUNT(*) FROM report_uploads WHERE report_month = :report_month'
            );
            $monthCountStatement->execute(array(':report_month' => $reportMonth));
            if ((int) $monthCountStatement->fetchColumn() !== 1) {
                $pdo->rollBack();
                deleteReportJson(false, 'This legacy upload shares its month with other uploads and cannot be reversed safely.', 409, array());
            }

            $deleteLegacyMonth = $pdo->prepare(
                'DELETE FROM tracking_report_monthly WHERE report_month = :report_month'
            );
            $deleteLegacyMonth->execute(array(':report_month' => $reportMonth));
            $rowsRemoved = $deleteLegacyMonth->rowCount();
            $deleteMode = 'legacy-single-month';
        }
    }

    $pdo->prepare('DELETE FROM report_upload_items WHERE upload_id = :upload_id')
        ->execute(array(':upload_id' => $uploadId));
    $pdo->prepare('DELETE FROM report_uploads WHERE id = :upload_id')
        ->execute(array(':upload_id' => $uploadId));

    $pdo->commit();

    $summary = $marketplace !== ''
        ? 'Deleted "' . $upload['filename'] . '". ' . number_format($rowsRemoved)
          . ' row' . ($rowsRemoved === 1 ? '' : 's') . ' for ' . $reportMonth . ' · ' . $marketplace
          . ' were removed. No other country or month was touched.'
        : 'Deleted "' . $upload['filename'] . '" and reversed what it had contributed.';

    deleteReportJson(true, $summary, 200, array(
        'upload_id'     => $uploadId,
        'filename'      => $upload['filename'],
        'rows_reversed' => (int) $upload['rows_processed'],
        'rows_removed'  => $rowsRemoved,
        'report_month'  => $reportMonth,
        'marketplace'   => $marketplace,
        'delete_mode'   => $deleteMode,
    ));
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('delete_report.php error: ' . $error->getMessage());
    deleteReportJson(false, 'The report could not be deleted. Check the server error log for details.', 500, array());
}
