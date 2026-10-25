<?php
require_once __DIR__ . '/inc/site.php';
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    dd_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}
if (!dd_csrf_validate($_POST['csrf_token'] ?? '')) {
    dd_json(['success' => false, 'message' => 'Your session expired. Reload the page and try again.'], 403);
}
$name = trim((string) ($_POST['name'] ?? ''));
$email = strtolower(trim((string) ($_POST['email'] ?? '')));
$password = (string) ($_POST['password'] ?? '');
$confirm = (string) ($_POST['confirm_password'] ?? '');

if ($name === '' || strlen($name) > 120) {
    dd_json(['success' => false, 'message' => 'Enter your name (up to 120 characters).'], 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
    dd_json(['success' => false, 'message' => 'Enter a valid email address.'], 422);
}
if (strlen($password) < 8 || strlen($password) > 4096) {
    dd_json(['success' => false, 'message' => 'Use a password between 8 and 4096 characters.'], 422);
}
if (!hash_equals($password, $confirm)) {
    dd_json(['success' => false, 'message' => 'The passwords do not match.'], 422);
}

$existing = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$existing->execute([$email]);
if ($existing->fetchColumn()) {
    dd_json(['success' => false, 'message' => 'An account already uses this email. Sign in instead.'], 409);
}

try {
    $insert = $pdo->prepare('INSERT INTO users (name, email, password, is_admin) VALUES (?, ?, ?, 0)');
    $insert->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
    $account = ['id' => (int) $pdo->lastInsertId(), 'name' => $name, 'email' => $email];
    dd_login_user($account);
    dd_json(['success' => true, 'message' => 'Account created.', 'redirect' => dd_url('/link-generator/')], 201);
} catch (PDOException $exception) {
    if ((string) $exception->getCode() === '23000') {
        dd_json(['success' => false, 'message' => 'An account already uses this email. Sign in instead.'], 409);
    }
    error_log('register.php: ' . $exception->getMessage());
    dd_json(['success' => false, 'message' => 'Account creation is temporarily unavailable. Please try again.'], 500);
}
