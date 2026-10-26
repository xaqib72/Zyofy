<?php
// admin/actions/diagnostic.php
// Server-health check for when an upload misbehaves. Open it in a browser while
// logged in as an admin: https://yoursite.com/pickwell-admin/admin/actions/diagnostic.php
//
// It prints the PHP version, whether the database connects, which extensions are
// present and what the upload limits are. None of that should be readable by the
// public, so it is behind the admin login — it used to be open to anybody.

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Not available. Log in as an administrator first.\n");
}

echo "<pre>";
echo "1. PHP is executing. Version: " . PHP_VERSION . "\n";

echo "2. Checking session...\n";
echo "   Session started OK.\n";
echo "   admin_logged_in in session: " . (isset($_SESSION['admin_logged_in']) ? var_export($_SESSION['admin_logged_in'], true) : 'NOT SET') . "\n";

echo "3. Checking db.php path (../../db.php from this file's location)...\n";
$dbPath = __DIR__ . '/../../db.php';
echo "   Resolved path: $dbPath\n";
echo "   File exists: " . (file_exists($dbPath) ? 'YES' : 'NO - THIS IS LIKELY YOUR PROBLEM') . "\n";

if (file_exists($dbPath)) {
    try {
        require_once $dbPath;
        echo "   db.php loaded OK.\n";
        if (isset($pdo)) {
            echo "   \$pdo variable exists.\n";
            $test = $pdo->query("SELECT 1")->fetchColumn();
            echo "   Test query result: $test — DATABASE CONNECTION OK.\n";
        } else {
            echo "   WARNING: db.php loaded but \$pdo variable is not set. Check db.php's variable name.\n";
        }
    } catch (Throwable $e) {
        echo "   ERROR loading db.php or connecting: " . $e->getMessage() . "\n";
    }
}

echo "4. Checking required PHP extensions...\n";
echo "   ZipArchive (needed for .xlsx): " . (class_exists('ZipArchive') ? 'AVAILABLE' : 'NOT AVAILABLE - xlsx upload will fail, use CSV instead') . "\n";
echo "   SimpleXML (needed for .xlsx): " . (class_exists('SimpleXMLElement') ? 'AVAILABLE' : 'NOT AVAILABLE') . "\n";
echo "   PDO MySQL: " . (extension_loaded('pdo_mysql') ? 'AVAILABLE' : 'NOT AVAILABLE') . "\n";

echo "5. Checking upload limits (relevant to file upload failing)...\n";
echo "   upload_max_filesize: " . ini_get('upload_max_filesize') . "\n";
echo "   post_max_size: " . ini_get('post_max_size') . "\n";
echo "   max_execution_time: " . ini_get('max_execution_time') . "\n";
echo "   memory_limit: " . ini_get('memory_limit') . "\n";

echo "\nDONE. If everything above says OK, the issue is likely a server-level block\n";
echo "(security module / WAF) rejecting the file upload POST request before PHP even\n";
echo "fully processes it. Check your hosting control panel's error log for entries\n";
echo "around the time you tried uploading (in Hostinger hPanel: Advanced > Error Logs).\n";
echo "</pre>";
