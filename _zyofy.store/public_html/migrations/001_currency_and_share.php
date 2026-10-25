<?php
/**
 * Migration 001 — currency, frozen rate, and the per-country share.
 *
 * Safe to run more than once: every change checks whether it is already there.
 * CLI only. Running it from a browser would let anyone on the internet alter the
 * schema, so it refuses to start over HTTP.
 *
 *   php migrations/001_currency_and_share.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This migration can only be run from the command line.\n");
}

require_once __DIR__ . '/../db.php';

function step($message)   { echo "  " . $message . "\n"; }
function skip($message)   { echo "  · " . $message . " (already done)\n"; }
function done($message)   { echo "  ✓ " . $message . "\n"; }

function hasTable($pdo, $table)
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
    );
    $statement->execute(array(':t' => $table));
    return (int) $statement->fetchColumn() > 0;
}

function hasColumn($pdo, $table, $column)
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $statement->execute(array(':t' => $table, ':c' => $column));
    return (int) $statement->fetchColumn() > 0;
}

function hasIndex($pdo, $table, $index)
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i'
    );
    $statement->execute(array(':t' => $table, ':i' => $index));
    return (int) $statement->fetchColumn() > 0;
}

function addColumn($pdo, $table, $column, $definition)
{
    if (hasColumn($pdo, $table, $column)) {
        skip("$table.$column");
        return;
    }
    $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    done("$table.$column added");
}

echo "\nMigration 001 — currency, frozen rate, per-country share\n";
echo str_repeat('=', 58) . "\n";

/* ------------------------------------------------------------------ *
 * 1. Report data carries its own marketplace, currency, rate and share.
 *    All four are frozen at import time and never recalculated.
 * ------------------------------------------------------------------ */
echo "\n1. tracking_report_monthly\n";
addColumn($pdo, 'tracking_report_monthly', 'marketplace',   "VARCHAR(64) NOT NULL DEFAULT '' AFTER tracking_id");
addColumn($pdo, 'tracking_report_monthly', 'currency',      "CHAR(3) NOT NULL DEFAULT '' AFTER marketplace");
addColumn($pdo, 'tracking_report_monthly', 'rate_to_usd',   "DECIMAL(18,6) NOT NULL DEFAULT 1.000000 AFTER currency");
addColumn($pdo, 'tracking_report_monthly', 'share_percent', "DECIMAL(5,2) NOT NULL DEFAULT 100.00 AFTER rate_to_usd");

if (hasIndex($pdo, 'tracking_report_monthly', 'uq_tag_month_market')) {
    skip('tracking_report_monthly unique key');
} else {
    // The old key was (tracking_id, report_month). Widening it to include the
    // marketplace is what stops a USD row and a EUR row from colliding and being
    // summed together by the uploader's "x = x + VALUES(x)" upsert.
    $duplicates = $pdo->query(
        'SELECT COUNT(*) FROM (
            SELECT tracking_id, report_month, marketplace
            FROM tracking_report_monthly
            GROUP BY tracking_id, report_month, marketplace
            HAVING COUNT(*) > 1
         ) AS d'
    )->fetchColumn();

    if ((int) $duplicates > 0) {
        exit("\n  ABORTED: $duplicates duplicate (tracking_id, report_month, marketplace) groups.\n"
           . "  Resolve those rows before adding the unique key.\n");
    }

    if (hasIndex($pdo, 'tracking_report_monthly', 'tracking_month')) {
        $pdo->exec('ALTER TABLE tracking_report_monthly DROP INDEX tracking_month');
        done('dropped old key tracking_month (tracking_id, report_month)');
    }
    $pdo->exec('ALTER TABLE tracking_report_monthly
                ADD UNIQUE KEY uq_tag_month_market (tracking_id, report_month, marketplace)');
    done('added uq_tag_month_market (tracking_id, report_month, marketplace)');
}

/* ------------------------------------------------------------------ *
 * 2. The reversible upload items need the same four values, otherwise
 *    deleting an upload cannot put the month back exactly as it was.
 * ------------------------------------------------------------------ */
echo "\n2. report_upload_items\n";
addColumn($pdo, 'report_upload_items', 'marketplace',   "VARCHAR(64) NOT NULL DEFAULT '' AFTER tracking_id");
addColumn($pdo, 'report_upload_items', 'currency',      "CHAR(3) NOT NULL DEFAULT '' AFTER marketplace");
addColumn($pdo, 'report_upload_items', 'rate_to_usd',   "DECIMAL(18,6) NOT NULL DEFAULT 1.000000 AFTER currency");
addColumn($pdo, 'report_upload_items', 'share_percent', "DECIMAL(5,2) NOT NULL DEFAULT 100.00 AFTER rate_to_usd");

/* ------------------------------------------------------------------ *
 * 3. The upload log records what it was told and what it actually used.
 * ------------------------------------------------------------------ */
echo "\n3. report_uploads\n";
addColumn($pdo, 'report_uploads', 'marketplace',   "VARCHAR(64) NOT NULL DEFAULT '' AFTER report_month");
addColumn($pdo, 'report_uploads', 'currency',      "CHAR(3) NOT NULL DEFAULT '' AFTER marketplace");
addColumn($pdo, 'report_uploads', 'rate_to_usd',   "DECIMAL(18,6) NOT NULL DEFAULT 1.000000 AFTER currency");
addColumn($pdo, 'report_uploads', 'share_percent', "DECIMAL(5,2) NOT NULL DEFAULT 100.00 AFTER rate_to_usd");
addColumn($pdo, 'report_uploads', 'rate_source',   "VARCHAR(20) NOT NULL DEFAULT 'default' AFTER share_percent");
addColumn($pdo, 'report_uploads', 'replaced_upload_id', "BIGINT UNSIGNED DEFAULT NULL AFTER rate_source");

/* ------------------------------------------------------------------ *
 * 4. One contributor could only ever hold one tag per marketplace,
 *    because the unique key was (user_email, marketplace). Widen it.
 * ------------------------------------------------------------------ */
echo "\n4. user_tags\n";
if (hasIndex($pdo, 'user_tags', 'uq_email_tag_market')) {
    skip('user_tags unique key');
} else {
    if (hasIndex($pdo, 'user_tags', 'user_email')) {
        $pdo->exec('ALTER TABLE user_tags DROP INDEX user_email');
        done('dropped old key user_email (user_email, marketplace)');
    }
    $pdo->exec('ALTER TABLE user_tags
                ADD UNIQUE KEY uq_email_tag_market (user_email, tracking_id, marketplace)');
    done('added uq_email_tag_market (user_email, tracking_id, marketplace)');
}

/* ------------------------------------------------------------------ *
 * 5. Countries. The only place a marketplace's currency is defined, and
 *    the only place a share % is set. Applied to every contributor.
 * ------------------------------------------------------------------ */
echo "\n5. marketplace_settings\n";
if (hasTable($pdo, 'marketplace_settings')) {
    skip('marketplace_settings table');
} else {
    $pdo->exec("
        CREATE TABLE marketplace_settings (
            marketplace   VARCHAR(64)  NOT NULL,
            country_label VARCHAR(64)  NOT NULL,
            currency      CHAR(3)      NOT NULL,
            share_percent DECIMAL(5,2) NOT NULL DEFAULT 70.00,
            is_active     TINYINT(1)   NOT NULL DEFAULT 0,
            updated_by    VARCHAR(255) NOT NULL DEFAULT '',
            updated_at    DATETIME     NOT NULL,
            PRIMARY KEY (marketplace),
            KEY idx_currency (currency)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    done('marketplace_settings created');
}

// marketplace, label, currency, share, active
$countries = array(
    array('www.amazon.com',    'United States',  'USD', 90.00, 1),
    array('www.amazon.co.uk',  'United Kingdom', 'GBP', 70.00, 1),
    array('www.amazon.de',     'Germany',        'EUR', 70.00, 1),
    array('www.amazon.ca',     'Canada',         'CAD', 70.00, 1),
    array('www.amazon.es',     'Spain',          'EUR', 70.00, 1),
    array('www.amazon.fr',     'France',         'EUR', 70.00, 1),
    array('www.amazon.it',     'Italy',          'EUR', 70.00, 0),
    array('www.amazon.nl',     'Netherlands',    'EUR', 70.00, 0),
    array('www.amazon.se',     'Sweden',         'SEK', 70.00, 0),
    array('www.amazon.pl',     'Poland',         'PLN', 70.00, 0),
    array('www.amazon.com.mx', 'Mexico',         'MXN', 70.00, 0),
    array('www.amazon.co.jp',  'Japan',          'JPY', 70.00, 0),
    array('www.amazon.in',     'India',          'INR', 70.00, 0),
    array('www.amazon.com.au', 'Australia',      'AUD', 70.00, 0),
    array('www.amazon.com.br', 'Brazil',         'BRL', 70.00, 0),
    array('www.amazon.ae',     'UAE',            'AED', 70.00, 0),
    array('www.amazon.sa',     'Saudi Arabia',   'SAR', 70.00, 0),
    array('www.amazon.sg',     'Singapore',      'SGD', 70.00, 0),
);

// INSERT IGNORE so re-running never overwrites a % the admin has since edited.
$seed = $pdo->prepare(
    'INSERT IGNORE INTO marketplace_settings
        (marketplace, country_label, currency, share_percent, is_active, updated_by, updated_at)
     VALUES (:m, :l, :c, :s, :a, "migration", NOW())'
);
$seeded = 0;
foreach ($countries as $row) {
    $seed->execute(array(':m' => $row[0], ':l' => $row[1], ':c' => $row[2], ':s' => $row[3], ':a' => $row[4]));
    $seeded += $seed->rowCount();
}
$seeded > 0 ? done("$seeded countries seeded (US 90%, everywhere else 70%)")
            : skip('country seed');

/* ------------------------------------------------------------------ *
 * 6. Exchange rates. These are only the defaults offered at import;
 *    the value actually used is copied onto the report rows.
 * ------------------------------------------------------------------ */
echo "\n6. exchange_rates\n";
if (hasTable($pdo, 'exchange_rates')) {
    skip('exchange_rates table');
} else {
    $pdo->exec("
        CREATE TABLE exchange_rates (
            currency     CHAR(3)       NOT NULL,
            rate_to_usd  DECIMAL(18,6) NOT NULL,
            quoted_for   DATE          DEFAULT NULL,
            is_confirmed TINYINT(1)    NOT NULL DEFAULT 0,
            updated_by   VARCHAR(255)  NOT NULL DEFAULT '',
            updated_at   DATETIME      NOT NULL,
            PRIMARY KEY (currency)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    done('exchange_rates created');
}

// Indicative starting values so an upload never fails for want of a rate.
// Everything except USD is left unconfirmed until the admin sets it.
$rates = array(
    array('USD', 1.000000, 1), array('EUR', 1.080000, 0), array('GBP', 1.270000, 0),
    array('CAD', 0.730000, 0), array('SEK', 0.095000, 0), array('PLN', 0.250000, 0),
    array('MXN', 0.055000, 0), array('JPY', 0.006400, 0), array('INR', 0.012000, 0),
    array('AUD', 0.660000, 0), array('BRL', 0.180000, 0), array('AED', 0.272000, 0),
    array('SAR', 0.266000, 0), array('SGD', 0.740000, 0),
);
$seedRate = $pdo->prepare(
    'INSERT IGNORE INTO exchange_rates
        (currency, rate_to_usd, quoted_for, is_confirmed, updated_by, updated_at)
     VALUES (:c, :r, NULL, :k, "migration", NOW())'
);
$rateCount = 0;
foreach ($rates as $row) {
    $seedRate->execute(array(':c' => $row[0], ':r' => $row[1], ':k' => $row[2]));
    $rateCount += $seedRate->rowCount();
}
$rateCount > 0 ? done("$rateCount rates seeded (USD confirmed, rest indicative)")
               : skip('rate seed');

/* ------------------------------------------------------------------ *
 * 7. A paid month is frozen. This is what makes "Paid on" honest and
 *    what a re-upload has to warn about.
 * ------------------------------------------------------------------ */
echo "\n7. payout_months\n";
if (hasTable($pdo, 'payout_months')) {
    skip('payout_months table');
} else {
    $pdo->exec("
        CREATE TABLE payout_months (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            report_month  CHAR(7)       NOT NULL,
            user_id       INT           NOT NULL,
            user_email    VARCHAR(255)  NOT NULL,
            gross_usd     DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            payable_usd   DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            status        VARCHAR(10)   NOT NULL DEFAULT 'draft',
            paid_on       DATE          DEFAULT NULL,
            frozen_at     DATETIME      DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_month_user (report_month, user_id),
            KEY idx_month (report_month)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    done('payout_months created');
}

echo "\n" . str_repeat('=', 58) . "\nMigration 001 finished.\n\n";
