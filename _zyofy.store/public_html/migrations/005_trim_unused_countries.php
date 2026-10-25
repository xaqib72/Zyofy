<?php
/**
 * Migration 005 — remove the countries and currencies this business does not use.
 *
 * The settings table was seeded with all eighteen Amazon stores so a new one could be
 * switched on without touching code. In practice only six are used, and the other
 * twelve just make the settings screen and the upload dropdowns longer than they need
 * to be.
 *
 * Nothing is removed blindly. A country is only dropped if no contributor holds a tag
 * on it, no report row mentions it, and no upload was ever filed under it. A currency
 * is only dropped once no remaining country uses it — and USD never goes, because it
 * is the base everything converts into.
 *
 *   php migrations/005_trim_unused_countries.php          (shows what it would do)
 *   php migrations/005_trim_unused_countries.php --apply
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This migration can only be run from the command line.\n");
}

require_once __DIR__ . '/../db.php';

$apply = in_array('--apply', $argv, true);

echo "\nMigration 005 — trim unused countries and currencies\n";
echo str_repeat('=', 62) . "\n";
echo "  mode: " . ($apply ? "APPLY (will write)" : "DRY RUN (writes nothing)") . "\n\n";

/* ---------------- what is actually in use ---------------- */
$inUse = array();

foreach ($pdo->query(
    "SELECT DISTINCT marketplace FROM user_tags WHERE marketplace <> ''"
) as $row) {
    $inUse[$row['marketplace']]['tags'] = true;
}

foreach ($pdo->query(
    "SELECT DISTINCT marketplace FROM tracking_report_monthly WHERE marketplace <> ''"
) as $row) {
    $inUse[$row['marketplace']]['rows'] = true;
}

foreach ($pdo->query(
    "SELECT DISTINCT marketplace FROM report_uploads WHERE marketplace <> ''"
) as $row) {
    $inUse[$row['marketplace']]['uploads'] = true;
}

$countries = $pdo->query(
    'SELECT marketplace, country_label, currency, is_active FROM marketplace_settings ORDER BY country_label'
)->fetchAll(PDO::FETCH_ASSOC);

$keep = array();
$drop = array();

foreach ($countries as $country) {
    $marketplace = $country['marketplace'];
    if (isset($inUse[$marketplace])) {
        $country['because'] = implode(', ', array_keys($inUse[$marketplace]));
        $keep[] = $country;
    } else {
        $drop[] = $country;
    }
}

echo "  KEEPING — these are in use:\n";
foreach ($keep as $country) {
    printf("    %-16s %-18s %s   (%s)\n",
        $country['country_label'], $country['marketplace'], $country['currency'], $country['because']);
}

if (empty($drop)) {
    exit("\n  Nothing to remove — every country is in use.\n\n");
}

echo "\n  REMOVING — nothing points at these:\n";
foreach ($drop as $country) {
    printf("    %-16s %-18s %s\n", $country['country_label'], $country['marketplace'], $country['currency']);
}

/* ---------------- currencies that go with them ---------------- */
$currenciesKept = array('USD' => true);   // the base always stays
foreach ($keep as $country) {
    $currenciesKept[$country['currency']] = true;
}

$currencyDrop = array();
foreach ($pdo->query('SELECT currency FROM exchange_rates ORDER BY currency') as $row) {
    $code = $row['currency'];
    if (isset($currenciesKept[$code])) {
        continue;
    }
    // Refuse to drop a currency any report row was actually imported in.
    $used = $pdo->prepare('SELECT COUNT(*) FROM tracking_report_monthly WHERE currency = :c');
    $used->execute(array(':c' => $code));
    if ((int) $used->fetchColumn() > 0) {
        printf("    · %s kept — report rows exist in it\n", $code);
        continue;
    }
    $currencyDrop[] = $code;
}

echo "\n  CURRENCIES KEPT: " . implode(', ', array_keys($currenciesKept)) . "\n";
echo "  CURRENCIES REMOVED: " . (empty($currencyDrop) ? '(none)' : implode(', ', $currencyDrop)) . "\n";

if (!$apply) {
    echo "\n  DRY RUN — nothing was changed. Re-run with --apply to remove them.\n\n";
    exit(0);
}

/* ---------------- apply ---------------- */
try {
    $pdo->beginTransaction();

    $removeCountry = $pdo->prepare('DELETE FROM marketplace_settings WHERE marketplace = :m');
    foreach ($drop as $country) {
        $removeCountry->execute(array(':m' => $country['marketplace']));
    }

    if (!empty($currencyDrop)) {
        $placeholders = implode(',', array_fill(0, count($currencyDrop), '?'));
        $pdo->prepare("DELETE FROM exchange_rates WHERE currency IN ($placeholders)")->execute($currencyDrop);
    }

    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    exit("\n  FAILED — nothing was changed: " . $error->getMessage() . "\n\n");
}

printf("\n  ✓ removed %d countries and %d currencies\n", count($drop), count($currencyDrop));

echo "\n  What is left:\n";
foreach ($pdo->query('SELECT country_label, marketplace, currency, share_percent, is_active
                      FROM marketplace_settings ORDER BY is_active DESC, country_label') as $row) {
    printf("    %-16s %-18s %s  %s%%  %s\n", $row['country_label'], $row['marketplace'], $row['currency'],
        rtrim(rtrim(number_format((float) $row['share_percent'], 2, '.', ''), '0'), '.'),
        (int) $row['is_active'] === 1 ? 'active' : 'inactive');
}
echo "\n    rates: ";
$rates = array();
foreach ($pdo->query('SELECT currency, rate_to_usd FROM exchange_rates ORDER BY currency') as $row) {
    $rates[] = $row['currency'] . ' ' . rtrim(rtrim(number_format((float) $row['rate_to_usd'], 6, '.', ''), '0'), '.');
}
echo implode(' · ', $rates) . "\n\n";
