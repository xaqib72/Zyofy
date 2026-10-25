<?php
/**
 * Migration 007 — commission moves from the country to the person.
 *
 * Until now the share was a property of the country: everybody earning on Amazon.de
 * got the same 70%. The owner now wants it per contributor, so one person can be on
 * 60% and another on 85% regardless of where they earn.
 *
 * WHAT THIS CHANGES ABOUT "FROZEN" FIGURES
 *
 * A report row still carries the share it was imported with, kept as a record of what
 * the settings said that day. But the payable figure is now worked out from the
 * person's own percentage, and a person's percentage can be edited at any time — so
 * an unpaid month will move if you change somebody's rate. That is the point of the
 * feature.
 *
 * Months that have been PAID are still safe: payout_months stores the amount actually
 * transferred, and the payout sheet compares the two and says so when they no longer
 * agree. Nothing quietly rewrites what somebody was paid.
 *
 * SEEDING
 *
 * Nobody's pay moves on the day this runs. Each person who has earned something is
 * seeded with the rate they are effectively on already — what they are owed divided by
 * what their tags grossed — so somebody working across a 90% country and a 70% one
 * lands on their real blended figure rather than one or the other. Measured across
 * every imported month, the total owed changes by a cent of rounding.
 *
 * People who have not earned yet have no rate to measure, so they start on the share
 * of the country where most of their tags sit.
 *
 * From there the owner moves individuals deliberately, which is the whole point.
 *
 *   php migrations/007_per_user_commission.php          (shows what it would do)
 *   php migrations/007_per_user_commission.php --apply
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This migration can only be run from the command line.\n");
}

require_once __DIR__ . '/../db.php';

$apply = in_array('--apply', $argv, true);
$fallback = 70.00;

/** "70", "82.5" — trailing zeros only ever trimmed after a decimal point. */
function pct007($value)
{
    $text = number_format((float) $value, 2, '.', '');
    if (strpos($text, '.') !== false) {
        $text = rtrim(rtrim($text, '0'), '.');
    }
    return $text . '%';
}

echo "\nMigration 007 — per-user commission\n";
echo str_repeat('=', 62) . "\n";
echo "  mode: " . ($apply ? "APPLY (will write)" : "DRY RUN (writes nothing)") . "\n\n";

function hasColumn007($pdo, $table, $column)
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $statement->execute(array(':t' => $table, ':c' => $column));
    return (int) $statement->fetchColumn() > 0;
}

/* ---------------- work out what each person should start on ---------------- */
$countryShare = array();
foreach ($pdo->query('SELECT marketplace, share_percent FROM marketplace_settings') as $row) {
    $countryShare[$row['marketplace']] = (float) $row['share_percent'];
}

$tagsByEmail = array();
foreach ($pdo->query(
    "SELECT LOWER(user_email) AS email, marketplace, COUNT(*) AS tags
     FROM user_tags WHERE tracking_id IS NOT NULL AND tracking_id <> ''
     GROUP BY LOWER(user_email), marketplace"
) as $row) {
    $tagsByEmail[$row['email']][$row['marketplace']] = (int) $row['tags'];
}

/* Each person's CURRENT effective rate, taken from the money already imported:
   what they are owed divided by what their tags grossed. Seeding with this means
   nobody's pay moves the day this runs — the owner then adjusts individuals
   deliberately, rather than discovering the change afterwards.

   Somebody with no earnings yet has no effective rate to measure, so they fall back
   to the share of the country where most of their tags sit. */
$holdersByTagSeed = array();
foreach ($pdo->query(
    "SELECT DISTINCT LOWER(user_email) AS email, tracking_id FROM user_tags
     WHERE tracking_id IS NOT NULL AND tracking_id <> ''"
) as $row) {
    $holdersByTagSeed[$row['tracking_id']][] = $row['email'];
}

$grossByUser   = array();
$payableByUser = array();
foreach ($pdo->query(
    "SELECT tracking_id, total_earnings, rate_to_usd, share_percent
     FROM tracking_report_monthly WHERE currency <> ''"
) as $row) {
    if (!isset($holdersByTagSeed[$row['tracking_id']])) { continue; }
    $gross = (float) $row['total_earnings'] * (float) $row['rate_to_usd'];
    foreach ($holdersByTagSeed[$row['tracking_id']] as $email) {
        $grossByUser[$email]   = ($grossByUser[$email]   ?? 0) + $gross;
        $payableByUser[$email] = ($payableByUser[$email] ?? 0) + $gross * (float) $row['share_percent'] / 100;
    }
}

$plan = array();
$measured = 0;
$guessed  = 0;

foreach ($pdo->query('SELECT id, name, email FROM users ORDER BY name') as $user) {
    $email = strtolower((string) $user['email']);
    $spread = isset($tagsByEmail[$email]) ? $tagsByEmail[$email] : array();

    if (isset($grossByUser[$email]) && $grossByUser[$email] > 0.005) {
        $share = round($payableByUser[$email] / $grossByUser[$email] * 100, 2);
        $plan[] = array('id' => (int) $user['id'], 'name' => $user['name'], 'email' => $user['email'],
                        'share' => $share, 'why' => 'their current effective rate');
        $measured++;
        continue;
    }

    if (empty($spread)) {
        $plan[] = array('id' => (int) $user['id'], 'name' => $user['name'], 'email' => $user['email'],
                        'share' => $fallback, 'why' => 'no tags — default');
        $guessed++;
        continue;
    }

    arsort($spread);
    $dominant = key($spread);
    $share = isset($countryShare[$dominant]) ? $countryShare[$dominant] : $fallback;
    $plan[] = array('id' => (int) $user['id'], 'name' => $user['name'], 'email' => $user['email'],
                    'share' => $share, 'why' => 'no earnings yet — took ' . $dominant);
    $guessed++;
}

printf("  %d people had earnings, so their exact current rate was measured.\n", $measured);
printf("  %d had none, so they start on their main country's figure.\n\n", $guessed);

$byShare = array();
foreach ($plan as $row) {
    $key = pct007($row['share']);
    $byShare[$key] = ($byShare[$key] ?? 0) + 1;
}

echo "  Starting percentages:\n";
ksort($byShare);
foreach ($byShare as $share => $count) {
    printf("    %-6s %d users\n", $share, $count);
}

/* ---------------- what it does to the money ---------------- */
$shareByUser = array();
foreach ($plan as $row) {
    $shareByUser[strtolower($row['email'])] = $row['share'];
}

$holdersByTag = array();
foreach ($pdo->query(
    "SELECT DISTINCT LOWER(user_email) AS email, tracking_id FROM user_tags
     WHERE tracking_id IS NOT NULL AND tracking_id <> ''"
) as $row) {
    $holdersByTag[$row['tracking_id']][] = $row['email'];
}

$before = 0.0;
$after  = 0.0;
$movers = array();

foreach ($pdo->query(
    "SELECT tracking_id, total_earnings, rate_to_usd, share_percent
     FROM tracking_report_monthly WHERE currency <> ''"
) as $row) {
    $holders = isset($holdersByTag[$row['tracking_id']]) ? $holdersByTag[$row['tracking_id']] : array();
    $gross = (float) $row['total_earnings'] * (float) $row['rate_to_usd'];

    foreach ($holders as $email) {
        $old = $gross * (float) $row['share_percent'] / 100;
        $new = $gross * (isset($shareByUser[$email]) ? $shareByUser[$email] : $fallback) / 100;
        $before += $old;
        $after  += $new;
        if (abs($new - $old) > 0.005) {
            $movers[$email] = ($movers[$email] ?? 0) + ($new - $old);
        }
    }
}

printf("\n  Effect on what is owed right now (all imported months):\n");
printf("    on the old country shares : $%s\n", number_format($before, 2));
printf("    on the new per-user shares: $%s\n", number_format($after, 2));
printf("    difference                : %s$%s\n",
    $after >= $before ? '+' : '-', number_format(abs($after - $before), 2));

if (!empty($movers)) {
    arsort($movers);
    printf("\n  %d people are affected. The largest moves:\n", count($movers));
    $shown = 0;
    foreach ($movers as $email => $delta) {
        printf("    %-36s %s$%s\n", $email, $delta >= 0 ? '+' : '-', number_format(abs($delta), 2));
        if (++$shown >= 8) { break; }
    }
    echo "\n  Anyone already marked paid keeps the amount they were paid; the payout\n";
    echo "  sheet will flag the difference rather than silently restating it.\n";
}

if (!$apply) {
    echo "\n  DRY RUN — nothing was written. Re-run with --apply.\n\n";
    exit(0);
}

/* ---------------- apply ---------------- */
/* The ALTER is deliberately outside the transaction. MySQL commits implicitly on any
   DDL, so wrapping it would end the transaction early and make the later commit()
   throw "there is no active transaction" — with the writes already applied. */
try {
    if (!hasColumn007($pdo, 'users', 'share_percent')) {
        $pdo->exec("ALTER TABLE users
                    ADD COLUMN share_percent DECIMAL(5,2) NOT NULL DEFAULT 70.00 AFTER email");
        echo "\n  ✓ users.share_percent added\n";
    } else {
        echo "\n  · users.share_percent already exists\n";
    }
} catch (Throwable $error) {
    exit("\n  FAILED adding the column: " . $error->getMessage() . "\n\n");
}

try {
    $pdo->beginTransaction();
    $update = $pdo->prepare('UPDATE users SET share_percent = :s WHERE id = :id');
    foreach ($plan as $row) {
        $update->execute(array(':s' => $row['share'], ':id' => $row['id']));
    }
    $pdo->commit();
    echo "  ✓ " . count($plan) . " users seeded\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    exit("\n  FAILED seeding — no percentages were changed: " . $error->getMessage() . "\n\n");
}

echo "\n  From now on the payout is: earnings x rate x THE PERSON'S share.\n";
echo "  The country share stays in marketplace_settings as the figure a new user starts on.\n\n";
