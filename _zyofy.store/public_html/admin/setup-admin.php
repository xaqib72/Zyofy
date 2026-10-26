<?php
// admin/setup-admin.php
session_start();
require_once '../db.php';

// This script creates the first admin account if none exists
$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE is_admin = 1");
$adminExists = $stmt->fetchColumn() > 0;

if ($adminExists) {
    header('Location: login.php');
    exit;
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($name) || empty($email) || empty($password)) {
        $error = 'All fields are required.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format.';
    } else {
        // Check if email already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'Email already registered.';
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password, is_admin) VALUES (?, ?, ?, 1)");
            $stmt->execute([$name, $email, $hashed]);
            $success = 'Admin account created successfully! <a href="login.php">Login now</a>';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Setup Admin - Pickwell</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <?php $brandPrefix = '../'; require __DIR__ . '/partials/brand_icons.php'; ?>

    <link rel="stylesheet" href="assets/pickwell-admin.css?v=<?= rawurlencode((string) filemtime(__DIR__ . '/assets/pickwell-admin.css')) ?>">
</head>
<body class="pickwell-admin-setup">
<div class="card setup-card">
    <div class="setup-mark"><img src="../assets/brand/pickwell-mark.svg" alt="Pickwell" width="44" height="44"><span>Pickwell <small>SECURE WORKSPACE</small></span></div>
    <h2>Create Admin Account</h2>
    <p class="sub">First-time setup — secure your dashboard</p>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="success"><?= $success ?></div><?php endif; ?>
    <?php if (!$success): ?>
    <form method="POST">
        <label for="setup-name">Full name</label><input id="setup-name" type="text" name="name" autocomplete="name" placeholder="Your name" required>
        <label for="setup-email">Work email</label><input id="setup-email" type="email" name="email" autocomplete="email" placeholder="admin@example.com" required>
        <label for="setup-password">Password</label><input id="setup-password" type="password" name="password" autocomplete="new-password" placeholder="At least 6 characters" required>
        <button type="submit">Create Admin</button>
    </form>
    <?php endif; ?>
</div>
</body>
</html>
