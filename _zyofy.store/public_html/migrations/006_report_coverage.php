<?php
/**
 * Migration 006 — record how much of the month a report actually covers.
 *
 * Reports are not only pulled after the month ends. A file downloaded on the 4th
 * covers the 1st to the 5th; the same month pulled again on the 12th covers the 1st
 * to the 12th. Re-importing already handles that correctly — each pull replaces the
 * last, so nothing is ever counted twice — but until now nothing recorded WHICH days
 * were in the file.
 *
 * That matters at payout time. "July: $1,322" reads very differently if July only
 * runs to the 5th, and there was no way to tell.
 *
 *   php migrations/006_report_coverage.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This migration can only be run from the command line.\n");
}

require_once __DIR__ . '/../db.php';

function hasColumn006($pdo, $table, $column)
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $statement->execute(array(':t' => $table, ':c' => $column));
    return (int) $statement->fetchColumn() > 0;
}

echo "\nMigration 006 — record what part of the month each report covers\n";
echo str_repeat('=', 64) . "\n";

if (hasColumn006($pdo, 'report_uploads', 'covers_to')) {
    echo "  · report_uploads.covers_to (already done)\n";
} else {
    $pdo->exec("ALTER TABLE report_uploads
                ADD COLUMN covers_to DATE DEFAULT NULL AFTER report_month");
    echo "  ✓ report_uploads.covers_to added\n";
}

/* Fill in what can be worked out for the imports already done. A report uploaded
   after its month had ended almost certainly covers the whole month; one uploaded
   during the month covers up to the day before it was uploaded. */
$filled = 0;
$statement = $pdo->query(
    "SELECT id, report_month, uploaded_at FROM report_uploads
     WHERE covers_to IS NULL AND report_month <> ''"
);
$update = $pdo->prepare('UPDATE report_uploads SET covers_to = :d WHERE id = :id');

foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $monthEnd = date('Y-m-t', strtotime($row['report_month'] . '-01'));
    $uploadDay = date('Y-m-d', strtotime($row['uploaded_at']));

    // Uploaded during its own month -> it can only hold days up to the day before.
    $covers = ($uploadDay <= $monthEnd)
        ? date('Y-m-d', strtotime($uploadDay . ' -1 day'))
        : $monthEnd;

    // Never before the month starts.
    $monthStart = $row['report_month'] . '-01';
    if ($covers < $monthStart) {
        $covers = $monthStart;
    }

    $update->execute(array(':d' => $covers, ':id' => (int) $row['id']));
    $filled++;
}

echo $filled > 0
    ? "  ✓ worked out coverage for $filled existing upload" . ($filled === 1 ? '' : 's') . "\n"
    : "  · no existing uploads needed filling in\n";

echo "\n  Current state:\n";
foreach ($pdo->query(
    "SELECT report_month, marketplace, covers_to, uploaded_at, rows_processed
     FROM report_uploads ORDER BY report_month, marketplace"
) as $row) {
    $monthEnd = date('Y-m-t', strtotime($row['report_month'] . '-01'));
    $complete = $row['covers_to'] !== null && $row['covers_to'] >= $monthEnd;
    printf("    %s  %-18s covers to %-12s %s\n",
        $row['report_month'], $row['marketplace'],
        $row['covers_to'] ?: 'unknown',
        $complete ? 'full month' : 'PARTIAL');
}

echo "\n  Uploads from now on record this themselves.\n\n";
