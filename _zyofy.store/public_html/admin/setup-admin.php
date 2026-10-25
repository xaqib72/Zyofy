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
    <title>Setup Admin - Zyofy</title>
    <link rel="icon" type="image/svg+xml" href="../assets/images/zyofy-mark.svg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        *{margin:0;padding:0;box-sizing:border-box;}
        :root{color-scheme:light;--ink:#1d2940;--muted:#788091;--line:#e5e0d8;--coral:#cf6258}
        body{background:#f3f0e9;background-image:radial-gradient(circle at 14% 18%,rgba(207,98,88,.11),transparent 28%),radial-gradient(circle at 88% 82%,rgba(53,73,105,.1),transparent 30%);font-family:Inter,Arial,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100svh;padding:20px;color:var(--ink)}
        .card{background:#fffdfa;border:1px solid var(--line);border-radius:12px;padding:clamp(25px,6vw,46px);max-width:490px;width:100%;box-shadow:0 25px 80px rgba(31,40,57,.1)}
        .setup-mark{display:flex;align-items:center;gap:11px;margin-bottom:27px;color:var(--ink);font:700 1.05rem Georgia,serif;letter-spacing:.02em}.setup-mark img{width:40px;height:40px;border-radius:12px;background:#1c2941}.setup-mark small{display:block;margin-top:4px;color:#999187;font:800 .48rem Inter,Arial,sans-serif;letter-spacing:.17em}
        h2{color:var(--ink);margin-bottom:9px;font:500 clamp(1.8rem,7vw,2.35rem)/1 Georgia,serif;letter-spacing:-.045em}
        .sub{color:var(--muted);margin-bottom:28px;line-height:1.55;font-size:.9rem}
        form{display:grid;gap:8px}
        label{color:#586274;font-size:.78rem;font-weight:750}
        input{width:100%;min-height:48px;padding:12px 14px;background:#fff;border:1px solid #dcd8d1;border-radius:6px;color:var(--ink);margin-bottom:10px;font:inherit}
        input:focus{outline:3px solid rgba(207,98,88,.15);border-color:var(--coral)}
        button{background:var(--coral);border:0;padding:14px;border-radius:6px;color:white;font-weight:750;cursor:pointer;width:100%;margin-top:6px}
        button:hover{background:#b94e4d}
        .error{color:#9e3232;background:#fff0ec;padding:12px 14px;border:1px solid #f2d0c8;border-radius:6px;margin-bottom:1rem;line-height:1.5}
        .success{color:#24664b;background:#edf7f0;padding:12px 14px;border:1px solid #d0e8d9;border-radius:6px;margin-bottom:1rem;line-height:1.5}
        a{color:#b94e4d;text-underline-offset:3px}
        @media(max-width:420px){body{padding:13px}.card{border-radius:9px}}
    </style>
</head>
<body>
<div class="card">
    <div class="setup-mark"><img src="../assets/images/zyofy-mark.svg" alt="Zyofy"><span>ZYOFY <small>SECURE WORKSPACE</small></span></div>
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
