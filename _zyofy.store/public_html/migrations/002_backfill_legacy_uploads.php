<?php
/**
 * Migration 002 — give the six pre-rebuild uploads their country and currency back.
 *
 * The original files are not to hand, but they do not need to be. Every one of the
 * 37 monthly rows was verified to sum exactly from the per-upload items still stored
 * in report_upload_items, and each item belongs to exactly one upload — which the
 * filename identifies by country. So the month can be rebuilt precisely:
 *
 *     report_upload_items  ->  one country each  ->  rebuilt monthly rows
 *
 * The one row that looks merged, "others", is Amazon's catch-all. It is stored as six
 * separate items, one per file, so it splits cleanly too.
 *
 * Usage — dry run first, it writes nothing without --apply:
 *
 *   php migrations/002_backfill_legacy_uploads.php --month=2026-07
 *   php migrations/002_backfill_legacy_uploads.php --month=2026-07 --apply
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This migration can only be run from the command line.\n");
}

require_once __DIR__ . '/../db.php';

/* ---------------- arguments ---------------- */
$options = getopt('', array('month:', 'apply', 'from::'));

$targetMonth = isset($options['month']) ? trim((string) $options['month']) : '';
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $targetMonth)) {
    exit("\nWhich month is this report actually for?\n\n"
       . "  php migrations/002_backfill_legacy_uploads.php --month=2026-07\n\n"
       . "The six uploads are all stamped 2026-08 because the old uploader used the date\n"
       . "of the upload rather than the month of the report. Passing the real month is the\n"
       . "one thing this script cannot work out on its own.\n\n");
}

$sourceMonth = isset($options['from']) && $options['from'] !== ''
    ? (string) $options['from']
    : '2026-08';
$apply = isset($options['apply']);

/* Filename -> country. Deliberately explicit rather than clever: a guess here would
   file real money under the wrong currency. */
$filenameToMarketplace = array(
    'USA 3.csv' => 'www.amazon.com',
    'uk 3.csv'  => 'www.amazon.co.uk',
    'de 3.csv'  => 'www.amazon.de',
    'fr3.csv'   => 'www.amazon.fr',
    'ca 3.csv'  => 'www.amazon.ca',
    'es 3.csv'  => 'www.amazon.es',
);

echo "\nMigration 002 — backfill country and currency onto the legacy uploads\n";
echo str_repeat('=', 70) . "\n";
echo "  source month : $sourceMonth\n";
echo "  target month : $targetMonth\n";
echo "  mode         : " . ($apply ? "APPLY (will write)" : "DRY RUN (writes nothing)") . "\n\n";

/* ---------------- gather ---------------- */
$uploads = $pdo->query(
    "SELECT id, filename, report_month, marketplace, rows_processed
     FROM report_uploads ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);

if (empty($uploads)) {
    exit("No uploads found. Nothing to do.\n\n");
}

$countries = array();
foreach ($pdo->query('SELECT marketplace, country_label, currency, share_percent FROM marketplace_settings') as $row) {
    $countries[$row['marketplace']] = $row;
}

$rates = array();
foreach ($pdo->query('SELECT currency, rate_to_usd, is_confirmed FROM exchange_rates') as $row) {
    $rates[$row['currency']] = $row;
}

$plan = array();
$blocked = array();
$unconfirmed = array();

foreach ($uploads as $upload) {
    if ($upload['marketplace'] !== '') {
        echo "  · #{$upload['id']} {$upload['filename']} already has a country ({$upload['marketplace']}) — skipped\n";
        continue;
    }

    $filename = (string) $upload['filename'];
    if (!isset($filenameToMarketplace[$filename])) {
        $blocked[] = $filename;
        continue;
    }

    $marketplace = $filenameToMarketplace[$filename];
    if (!isset($countries[$marketplace])) {
        $blocked[] = $filename . ' (country ' . $marketplace . ' is not in marketplace_settings)';
        continue;
    }

    $country  = $countries[$marketplace];
    $currency = $country['currency'];

    if (!isset($rates[$currency])) {
        $blocked[] = $filename . ' (no rate stored for ' . $currency . ')';
        continue;
    }
    if ((int) $rates[$currency]['is_confirmed'] !== 1) {
        $unconfirmed[$currency] = $rates[$currency]['rate_to_usd'];
    }

    $itemCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM report_upload_items WHERE upload_id = ' . (int) $upload['id']
    )->fetchColumn();

    $plan[] = array(
        'upload'      => $upload,
        'marketplace' => $marketplace,
        'label'       => $country['country_label'],
        'currency'    => $currency,
        'rate'        => (float) $rates[$currency]['rate_to_usd'],
        'share'       => (float) $country['share_percent'],
        'items'       => $itemCount,
    );
}

if (!empty($blocked)) {
    echo "\n  ABORTED — these uploads cannot be identified:\n";
    foreach ($blocked as $name) {
        echo "    · $name\n";
    }
    exit("\n  Add them to \$filenameToMarketplace in this script, or import them by hand.\n\n");
}

if (empty($plan)) {
    exit("  Nothing left to backfill.\n\n");
}

/* ---------------- show the plan ---------------- */
echo "\n  What will be written:\n\n";
printf("    %-4s %-14s %-16s %-4s %-9s %-7s %-6s %s\n",
    'ID', 'FILE', 'COUNTRY', 'CUR', 'RATE', 'SHARE', 'ITEMS', 'PAYABLE (USD)');
echo '    ' . str_repeat('-', 84) . "\n";

$grandPayable = 0.0;
foreach ($plan as $entry) {
    $sum = $pdo->query(
        'SELECT COALESCE(SUM(total_earnings), 0) FROM report_upload_items WHERE upload_id = ' . (int) $entry['upload']['id']
    )->fetchColumn();
    $payable = (float) $sum * $entry['rate'] * $entry['share'] / 100;
    $grandPayable += $payable;

    printf("    #%-3d %-14s %-16s %-4s %-9s %-7s %-6d %10s   (%s %s)\n",
        $entry['upload']['id'], $entry['upload']['filename'], $entry['label'], $entry['currency'],
        rtrim(rtrim(number_format($entry['rate'], 6, '.', ''), '0'), '.'),
        rtrim(rtrim(number_format($entry['share'], 2, '.', ''), '0'), '.') . '%',
        $entry['items'], '$' . number_format($payable, 2),
        $entry['currency'], number_format((float) $sum, 2));
}
echo '    ' . str_repeat('-', 84) . "\n";
printf("    %-62s %10s\n", 'total payable once rebuilt', '$' . number_format($grandPayable, 2));

if (!empty($unconfirmed)) {
    echo "\n  WARNING — these rates have not been confirmed on the Countries & rates screen:\n";
    foreach ($unconfirmed as $currency => $rate) {
        echo "    · $currency at $rate (indicative starting value)\n";
    }
    echo "    Whatever is used here is frozen onto the month for good. Set the real rates first\n";
    echo "    unless these are already correct.\n";
}

if (!$apply) {
    echo "\n  DRY RUN — nothing was written. Re-run with --apply to make these changes.\n\n";
    exit(0);
}

/* ---------------- apply ---------------- */
echo "\n  Applying...\n";

try {
    $pdo->beginTransaction();

    $updateUpload = $pdo->prepare(
        'UPDATE report_uploads
         SET report_month = :month, marketplace = :mk, currency = :cur,
             rate_to_usd = :rate, share_percent = :share, rate_source = "backfill"
         WHERE id = :id'
    );
    $updateItems = $pdo->prepare(
        'UPDATE report_upload_items
         SET report_month = :month, marketplace = :mk, currency = :cur,
             rate_to_usd = :rate, share_percent = :share
         WHERE upload_id = :id'
    );

    foreach ($plan as $entry) {
        $args = array(
            ':month' => $targetMonth, ':mk' => $entry['marketplace'], ':cur' => $entry['currency'],
            ':rate' => $entry['rate'], ':share' => $entry['share'], ':id' => (int) $entry['upload']['id'],
        );
        $updateUpload->execute($args);
        $updateItems->execute($args);
        echo "    ✓ #{$entry['upload']['id']} {$entry['upload']['filename']} → {$entry['label']} ({$entry['currency']})\n";
    }

    // Throw away the merged monthly rows and rebuild them from the items, which now
    // each carry their own country.
    $removed = $pdo->prepare('DELETE FROM tracking_report_monthly WHERE report_month = :m');
    $removed->execute(array(':m' => $sourceMonth));
    echo "    ✓ removed {$removed->rowCount()} merged rows from $sourceMonth\n";

    if ($targetMonth !== $sourceMonth) {
        $alsoTarget = $pdo->prepare('DELETE FROM tracking_report_monthly WHERE report_month = :m');
        $alsoTarget->execute(array(':m' => $targetMonth));
        if ($alsoTarget->rowCount() > 0) {
            echo "    ✓ cleared {$alsoTarget->rowCount()} existing rows in $targetMonth\n";
        }
    }

    $rebuilt = $pdo->prepare(
        'INSERT INTO tracking_report_monthly
            (tracking_id, marketplace, currency, rate_to_usd, share_percent, report_month,
             clicks, items_ordered, ordered_revenue, items_shipped, items_returned,
             items_shipped_revenue, items_returned_revenue, total_earnings, bonus,
             items_shipped_earnings, items_returned_earnings, last_updated)
         SELECT tracking_id, marketplace, currency, rate_to_usd, share_percent, report_month,
                SUM(clicks), SUM(items_ordered), SUM(ordered_revenue),
                SUM(items_shipped), SUM(items_returned),
                SUM(items_shipped_revenue), SUM(items_returned_revenue),
                SUM(total_earnings), SUM(bonus),
                SUM(items_shipped_earnings), SUM(items_returned_earnings), NOW()
         FROM report_upload_items
         WHERE report_month = :month
         GROUP BY tracking_id, marketplace, currency, rate_to_usd, share_percent, report_month'
    );
    $rebuilt->execute(array(':month' => $targetMonth));
    echo "    ✓ rebuilt {$rebuilt->rowCount()} monthly rows, each with its own country\n";

    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    exit("\n  FAILED — nothing was changed: " . $error->getMessage() . "\n\n");
}

/* ---------------- verify ---------------- */
echo "\n  Result:\n";
foreach ($pdo->query(
    "SELECT currency, COUNT(*) rows_count, SUM(total_earnings) earned,
            SUM(total_earnings * rate_to_usd * share_percent / 100) payable
     FROM tracking_report_monthly WHERE report_month = " . $pdo->quote($targetMonth) . "
     GROUP BY currency ORDER BY payable DESC") as $row) {
    printf("    %-5s %2d rows  earned %10s   payable %10s\n",
        $row['currency'] !== '' ? $row['currency'] : '(none)', $row['rows_count'],
        number_format((float) $row['earned'], 2), '$' . number_format((float) $row['payable'], 2));
}

$leftover = (int) $pdo->query(
    "SELECT COUNT(*) FROM tracking_report_monthly WHERE currency = ''"
)->fetchColumn();
echo "\n  rows still without a currency: $leftover\n";
echo "\n" . str_repeat('=', 70) . "\nMigration 002 finished.\n\n";
