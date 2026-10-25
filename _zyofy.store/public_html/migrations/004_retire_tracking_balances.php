<?php
/**
 * Migration 004 — retire tracking_balances.
 *
 * This table held one running, ever-growing balance per tracking ID. It was never
 * currency-aware, so its 875.96 was dollars, euros, pounds and Canadian dollars
 * added together — the same mistake the reports used to make.
 *
 * Since the rebuild nothing writes to it and no screen reads it, so it now sits
 * frozen at whatever the old uploader last left there. Left in place it is a trap:
 * the next person to find it will reasonably assume it means something.
 *
 * It is renamed rather than dropped, so the numbers can still be looked at if
 * anybody ever asks where a historic figure came from.
 *
 *   php migrations/004_retire_tracking_balances.php          (shows what it would do)
 *   php migrations/004_retire_tracking_balances.php --apply
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This migration can only be run from the command line.\n");
}

require_once __DIR__ . '/../db.php';

$apply = in_array('--apply', $argv, true);

function tableExists004($pdo, $name)
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
    );
    $statement->execute(array(':t' => $name));
    return (int) $statement->fetchColumn() > 0;
}

echo "\nMigration 004 — retire tracking_balances\n";
echo str_repeat('=', 55) . "\n";

if (!tableExists004($pdo, 'tracking_balances')) {
    exit("  · tracking_balances no longer exists — nothing to do.\n\n");
}

if (tableExists004($pdo, 'tracking_balances_retired')) {
    exit("  · already retired as tracking_balances_retired.\n\n");
}

$rows = (int) $pdo->query('SELECT COUNT(*) FROM tracking_balances')->fetchColumn();
$sum  = (float) $pdo->query('SELECT COALESCE(SUM(balance), 0) FROM tracking_balances')->fetchColumn();

echo "  it currently holds : $rows rows, totalling " . number_format($sum, 2) . "\n";
echo "  that total is a mix of USD, EUR, GBP and CAD added together — not a real amount\n";
echo "  written by          : nothing since the rebuild\n";
echo "  read by             : no screen\n\n";

// Prove nothing still depends on it before suggesting the rename.
$appDir = dirname(__DIR__);
$stillUsed = array();
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appDir));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    if (strpos($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR) !== false
        || strpos($path, DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR) !== false) {
        continue;
    }
    $body = file_get_contents($path);
    if (strpos($body, 'tracking_balances') !== false) {
        $stillUsed[] = str_replace($appDir . DIRECTORY_SEPARATOR, '', $path);
    }
}

if (!empty($stillUsed)) {
    echo "  STILL REFERENCED BY:\n";
    foreach ($stillUsed as $path) {
        echo "    · $path\n";
    }
    echo "\n  Those references are the retired uploader, which is already switched off,\n";
    echo "  but check them before renaming.\n\n";
} else {
    echo "  no PHP file outside migrations mentions it\n\n";
}

if (!$apply) {
    echo "  DRY RUN — nothing was changed.\n";
    echo "  Re-run with --apply to rename it to tracking_balances_retired.\n\n";
    exit(0);
}

try {
    $pdo->exec('RENAME TABLE tracking_balances TO tracking_balances_retired');
    echo "  ✓ renamed to tracking_balances_retired\n";
    echo "    The data is untouched. If it turns out to be needed, rename it back:\n";
    echo "      RENAME TABLE tracking_balances_retired TO tracking_balances;\n\n";
} catch (Throwable $error) {
    exit("\n  FAILED — nothing was changed: " . $error->getMessage() . "\n\n");
}
