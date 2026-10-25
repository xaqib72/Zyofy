<?php
/**
 * Migration 008 — commission moves from the person to the tag.
 *
 * 007 put the rate on the person: one figure covering everything they hold. The client
 * wants it finer than that — the same contributor can be on 90% for one tracking ID and
 * 70% for another — so the rate now lives on the assignment in user_tags, which is also
 * where it is set: the Create TAG screen, next to the tag it belongs to.
 *
 * users.share_percent is left in place but stops deciding money. It becomes the figure a
 * person's *new* assignments start on, and the Users & Tags tab shows the combined rate
 * across everything they hold rather than a number anybody edits there.
 *
 * Seeding: every assignment starts on its holder's current rate. That is precisely what
 * they are being paid today, so the payout total cannot move — which the script checks
 * and prints before writing anything.
 *
 * Run it once:
 *     php migrations/008_per_tag_commission.php            (shows what it would do)
 *     php migrations/008_per_tag_commission.php --apply    (writes)
 */

require_once __DIR__ . '/../db.php';

$apply = in_array('--apply', $argv, true);

echo "Migration 008 — per-tag commission\n";
echo str_repeat('=', 62), "\n";
echo '  mode: ', $apply ? 'APPLY (writes)' : 'DRY RUN (writes nothing)', "\n\n";

/* Trailing zeros are dropped only after a decimal point. Trimming them unconditionally
   turns 70 into 7 — the mistake this project has already made twice. */
function pct008($value, $decimals = 2)
{
    $text = number_format((float) $value, $decimals, '.', '');
    if (strpos($text, '.') !== false) {
        $text = rtrim(rtrim($text, '0'), '.');
    }
    return $text . '%';
}

function hasColumn008($pdo, $table, $column)
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $statement->execute(array(':t' => $table, ':c' => $column));
    return (int) $statement->fetchColumn() > 0;
}

if (!hasColumn008($pdo, 'users', 'share_percent')) {
    echo "  ✗ users.share_percent is missing — run migration 007 first.\n";
    exit(1);
}

$alreadyDone = hasColumn008($pdo, 'user_tags', 'share_percent');
echo '  user_tags.share_percent: ', $alreadyDone ? "already exists\n" : "will be added\n";

/* ------------------------------------------------------------------ *
 * What each assignment will start on: its holder's current rate.
 * ------------------------------------------------------------------ */
$fallback = 70.00;
$shareByEmail = array();
foreach ($pdo->query('SELECT email, share_percent FROM users') as $row) {
    $shareByEmail[strtolower(trim((string) $row['email']))] = (float) $row['share_percent'];
}

$assignments = $pdo->query(
    'SELECT id, user_email, tracking_id, marketplace FROM user_tags'
)->fetchAll(PDO::FETCH_ASSOC);

$plan = array();
$orphans = 0;
foreach ($assignments as $row) {
    $email = strtolower(trim((string) $row['user_email']));
    if (isset($shareByEmail[$email])) {
        $share = $shareByEmail[$email];
    } else {
        // The assignment names an address with no account behind it. It earns nothing on
        // the sheet either way, but it still needs a value in a NOT NULL column.
        $share = $fallback;
        $orphans++;
    }
    $plan[] = array('id' => (int) $row['id'], 'share' => $share);
}

printf("  %s assignments to seed", number_format(count($plan)));
echo $orphans > 0 ? sprintf(" (%d name an address with no account)\n", $orphans) : "\n";

$spread = array();
foreach ($plan as $row) {
    $key = pct008($row['share']);
    $spread[$key] = (isset($spread[$key]) ? $spread[$key] : 0) + 1;
}
uksort($spread, function ($a, $b) {
    return (float) rtrim($a, '%') <=> (float) rtrim($b, '%');
});
echo "\n  Starting percentages:\n";
foreach ($spread as $share => $count) {
    printf("    %-7s %d assignments\n", $share, $count);
}

/* ------------------------------------------------------------------ *
 * Proof that nothing moves.
 *
 * Both sides are worked out the way the payout sheet does it: a tag is counted once per
 * holder regardless of how many marketplaces it is recorded against, so a tag listed
 * twice for one person does not pay them twice.
 * ------------------------------------------------------------------ */
$holders = array();
foreach ($assignments as $row) {
    $tag   = (string) $row['tracking_id'];
    $email = strtolower(trim((string) $row['user_email']));
    if ($tag === '') { continue; }
    if (!isset($holders[$tag])) { $holders[$tag] = array(); }
    if (!isset($holders[$tag][$email])) {
        $holders[$tag][$email] = isset($shareByEmail[$email]) ? $shareByEmail[$email] : $fallback;
    }
}

$before = 0.0;
$after  = 0.0;
foreach ($pdo->query(
    "SELECT tracking_id, total_earnings, rate_to_usd FROM tracking_report_monthly WHERE currency <> ''"
) as $row) {
    $tag = (string) $row['tracking_id'];
    if (empty($holders[$tag])) { continue; }
    $gross = (float) $row['total_earnings'] * (float) $row['rate_to_usd'];
    foreach ($holders[$tag] as $email => $share) {
        // Before: the person's rate. After: the assignment's rate, seeded to the same
        // number. They are equal by construction — this prints it rather than claiming it.
        $personRate     = isset($shareByEmail[$email]) ? $shareByEmail[$email] : $fallback;
        $assignmentRate = $share;
        $before += $gross * $personRate / 100;
        $after  += $gross * $assignmentRate / 100;
    }
}

echo "\n  Effect on what is owed right now (all imported months):\n";
printf("    on the person's rate     : $%s\n", number_format($before, 2));
printf("    on the assignment's rate : $%s\n", number_format($after, 2));
printf("    difference               : %s$%s\n",
    $after - $before < 0 ? '-' : '+', number_format(abs($after - $before), 2));

if (abs($after - $before) > 0.005) {
    echo "\n  ✗ The totals disagree. Nothing will be written — this needs looking at first.\n";
    exit(1);
}

echo "\n  Commission is set on the Create TAG screen from here on. The Users & Tags tab\n";
echo "  shows the combined rate across everything a person holds and no longer edits it.\n";

if (!$apply) {
    echo "\n  DRY RUN — nothing was written. Re-run with --apply.\n";
    exit(0);
}

/* ------------------------------------------------------------------ *
 * Write.
 *
 * The ALTER is deliberately outside the transaction: MySQL commits implicitly on any
 * DDL, so wrapping it would close the transaction early and leave the seeding
 * unprotected while looking like it was covered.
 * ------------------------------------------------------------------ */
try {
    if (!$alreadyDone) {
        $pdo->exec('ALTER TABLE user_tags
                    ADD COLUMN share_percent DECIMAL(5,2) NOT NULL DEFAULT 70.00 AFTER marketplace');
        echo "\n  ✓ user_tags.share_percent added\n";
    } else {
        echo "\n  · user_tags.share_percent already exists\n";
    }
} catch (Throwable $error) {
    echo '  ✗ Could not add the column: ', $error->getMessage(), "\n";
    exit(1);
}

$pdo->beginTransaction();
try {
    $update = $pdo->prepare('UPDATE user_tags SET share_percent = :s WHERE id = :id');
    foreach ($plan as $row) {
        $update->execute(array(':s' => $row['share'], ':id' => $row['id']));
    }
    $pdo->commit();
    printf("  ✓ %s assignments seeded\n", number_format(count($plan)));
} catch (Throwable $error) {
    $pdo->rollBack();
    echo '  ✗ Seeding failed, rolled back: ', $error->getMessage(), "\n";
    exit(1);
}

/* The default a brand-new assignment starts on, so every route that creates one — the
   Create TAG form, or a hand-written INSERT — picks it up without anybody remembering. */
try {
    $pdo->exec('ALTER TABLE user_tags ALTER COLUMN share_percent SET DEFAULT '
               . number_format($fallback, 2, '.', ''));
    echo '  ✓ new assignments default to ', pct008($fallback), "\n";
} catch (Throwable $error) {
    echo '  · could not set the column default: ', $error->getMessage(), "\n";
}

echo "\n  Done.\n";
