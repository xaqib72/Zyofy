<?php
/**
 * Migration 003 — remember where each uploaded file was kept.
 *
 * The old uploader read PHP's temporary copy and let it go. When the six files from
 * 4 August 2026 turned out to be filed under the wrong month, there was nothing left
 * to open and check. From now on the importer keeps the original, and this column
 * records where it went.
 *
 *   php migrations/003_store_source_files.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This migration can only be run from the command line.\n");
}

require_once __DIR__ . '/../db.php';
// The archive path is defined once, in the library the importer uses, so the two
// can never point at different folders.
require_once __DIR__ . '/../admin/lib/report_reader.php';

function hasColumn003($pdo, $table, $column)
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $statement->execute(array(':t' => $table, ':c' => $column));
    return (int) $statement->fetchColumn() > 0;
}

echo "\nMigration 003 — keep the source file with each upload\n";
echo str_repeat('=', 55) . "\n";

if (hasColumn003($pdo, 'report_uploads', 'stored_file')) {
    echo "  · report_uploads.stored_file (already done)\n";
} else {
    $pdo->exec("ALTER TABLE report_uploads
                ADD COLUMN stored_file VARCHAR(255) NOT NULL DEFAULT '' AFTER filename");
    echo "  ✓ report_uploads.stored_file added\n";
}

$archive = dd_report_archive_dir();
if (!is_dir($archive)) {
    if (@mkdir($archive, 0700, true)) {
        echo "  ✓ archive folder created at $archive\n";
    } else {
        echo "  ! could not create $archive — imports will still work, but the file will not be kept\n";
    }
} else {
    echo "  · archive folder exists at $archive\n";
}

echo "\n  Anything imported from now on keeps its original file there.\n";
echo "  The six pre-rebuild uploads cannot be recovered — they were never saved.\n\n";
