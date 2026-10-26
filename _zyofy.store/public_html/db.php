<?php
require_once __DIR__ . '/inc/app.php';

$db = dd_config('db', []);
$password = (string) ($db['password'] ?? '');

if ($password === '' || $password === 'PASTE_STAGING_DB_PASSWORD_HERE') {
    throw new RuntimeException('Pickwell database is not configured yet. Add the staging database password in config.php.');
}

$dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=%s',
    (string) ($db['host'] ?? 'localhost'),
    (string) ($db['name'] ?? ''),
    (string) ($db['charset'] ?? 'utf8mb4')
);

try {
    $pdo = new PDO($dsn, (string) ($db['user'] ?? ''), $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    error_log('Pickwell database connection failed: ' . $e->getMessage());
    if (dd_config('app.debug', false)) {
        throw $e;
    }
    throw new RuntimeException('Pickwell cannot connect to its database right now.');
}
