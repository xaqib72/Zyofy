<?php
// admin/login.php
session_start();
require_once '../db.php';

// Check if admin already logged in
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: index.php');
    exit;
}

$error = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please enter email and password.';
    } else {
        // Check if user exists and is admin
        $stmt = $pdo->prepare("SELECT id, name, email, password, is_admin FROM users WHERE LOWER(TRIM(email)) = LOWER(?) LIMIT 1");
        $stmt->execute([trim($email)]);
        $user = $stmt->fetch();

        $storedPassword = (string) ($user['password'] ?? '');
        $isPasswordHash = preg_match('/^\$(?:2y|argon2i|argon2id)\$/', $storedPassword) === 1;
        $passwordMatches = $user && ($isPasswordHash
            ? password_verify($password, $storedPassword)
            : ($storedPassword !== '' && hash_equals($storedPassword, $password)));

        if ($passwordMatches && isset($user['is_admin']) && (int) $user['is_admin'] === 1) {
            // Older installs may still contain a plain-text password. Upgrade it
            // immediately after a successful login; never keep using it in clear text.
            if (!$isPasswordHash) {
                $upgradedHash = password_hash($password, PASSWORD_DEFAULT);
                $upgrade = $pdo->prepare('UPDATE users SET password = ? WHERE id = ? AND password = ?');
                $upgrade->execute([$upgradedHash, (int) $user['id'], $storedPassword]);
                if ($upgrade->rowCount() !== 1) {
                    $verifyLatest = $pdo->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
                    $verifyLatest->execute([(int) $user['id']]);
                    if (!password_verify($password, (string) $verifyLatest->fetchColumn())) {
                        $error = 'Your account could not be secured during sign-in. Please try again.';
                    }
                }
            }
        }

        if ($passwordMatches && isset($user['is_admin']) && (int) $user['is_admin'] === 1 && $error === '') {
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_user_id'] = $user['id'];
            $_SESSION['admin_name'] = $user['name'];
            header('Location: index.php');
            exit;
        } else {
            $error = 'Invalid credentials or you do not have admin access.';
        }
    }
}

// Check if any admin exists in the system, if not, show setup link
$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE is_admin = 1");
$adminExists = $stmt->fetchColumn() > 0;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Zyofy</title>
    <link rel="icon" type="image/svg+xml" href="../assets/images/zyofy-mark.svg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0f172a;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }
        .login-card {
            background: #1e293b;
            border-radius: 28px;
            padding: 2.5rem;
            width: 100%;
            max-width: 440px;
            box-shadow: 0 25px 45px -12px rgba(0,0,0,0.5);
        }
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .logo-icon img { width:100%;height:100%;border-radius:inherit;display:block; }
        .logo-icon {
            width: 56px;
            height: 56px;
            background: #4da33c;
            color: white;
            font-size: 1.8rem;
            font-weight: 700;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
        }
        h1 {
            color: white;
            font-size: 1.8rem;
            font-weight: 700;
            letter-spacing: -0.02em;
        }
        .sub {
            color: #94a3b8;
            margin-top: 0.5rem;
        }
        .input-group {
            margin-bottom: 1.5rem;
        }
        label {
            display: block;
            color: #cbd5e1;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        input {
            width: 100%;
            padding: 12px 16px;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 16px;
            color: white;
            font-size: 1rem;
            transition: 0.2s;
        }
        input:focus {
            outline: none;
            border-color: #4da33c;
            box-shadow: 0 0 0 3px rgba(77, 163, 60, 0.2);
        }
        button {
            width: 100%;
            background: #4da33c;
            border: none;
            padding: 12px;
            border-radius: 40px;
            font-weight: 600;
            font-size: 1rem;
            color: white;
            cursor: pointer;
            transition: 0.2s;
        }
        button:hover {
            background: #1f5a2c;
            transform: translateY(-2px);
        }
        .error {
            background: rgba(220, 38, 38, 0.2);
            border-left: 4px solid #ef4444;
            padding: 12px;
            border-radius: 12px;
            color: #fecaca;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }
        .setup-link {
            text-align: center;
            margin-top: 1.5rem;
        }
        .setup-link a {
            color: #4da33c;
            text-decoration: none;
        }
        .setup-link a:hover {
            text-decoration: underline;
        }
    
        body.zyofy-admin-login{background:#f3f0ea;background-image:radial-gradient(circle at 16% 22%,rgba(213,108,83,.11),transparent 27%),radial-gradient(circle at 85% 79%,rgba(73,93,122,.12),transparent 28%)}
        .zyofy-admin-login .login-card{max-width:480px;padding:42px 42px 38px;border:1px solid #e8e2d9;border-radius:8px;background:#fffdfa;box-shadow:0 28px 80px rgba(31,40,57,.12)}
        .zyofy-admin-login .login-header{text-align:left;margin-bottom:30px}
        .zyofy-admin-login .logo-icon{width:48px;height:48px;margin:0 0 24px;border-radius:13px;background:#1d2a42}
        .zyofy-admin-login h1{color:#1e2940;font:500 2.45rem/1 Georgia,serif;letter-spacing:-.045em}
        .zyofy-admin-login .sub{color:#788091;line-height:1.55}
        .zyofy-admin-login .input-group{margin-bottom:19px}
        .zyofy-admin-login .input-group label{display:block;margin-bottom:7px;color:#515d70;font-size:.79rem;font-weight:750}
        .zyofy-admin-login .input-group input{min-height:49px;padding:12px 14px;border:1px solid #dedbd5;border-radius:6px;background:#fff;color:#18233a}
        .zyofy-admin-login form button{min-height:49px;margin-top:4px;border-radius:6px;background:#ce5a55}
        @media (max-width: 480px) {
            body.zyofy-admin-login { padding: 14px; }
            .zyofy-admin-login .login-card { padding: clamp(22px, 7vw, 32px); border-radius: 10px; }
            .zyofy-admin-login h1 { font-size: clamp(1.8rem, 9vw, 2.2rem); }
        }

    </style>
    <link rel="stylesheet" href="assets/premium-refresh.css?v=<?= rawurlencode((string) filemtime(__DIR__ . '/assets/premium-refresh.css')) ?>">
</head>
<body class="zyofy-admin-login">
<div class="login-card">
    <div class="login-header">
        <div class="logo-icon"><img src="../assets/images/zyofy-mark.svg" alt="Zyofy"> </div>
        <h1>Admin Login</h1>
        <p class="sub">Zyofy • Control Panel</p>
    </div>

    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (!$adminExists): ?>
        <div class="error" style="background: rgba(77, 163, 60, 0.2); border-left-color: #4da33c;">
            ⚠️ No admin account found. <a href="setup-admin.php" style="color:#4da33c; font-weight:600;">Click here to create the first admin account</a>
        </div>
    <?php endif; ?>

    <form method="POST">
        <div class="input-group">
            <label>Email Address</label>
            <input type="email" name="email" required placeholder="admin@zyofy.store">
        </div>
        <div class="input-group">
            <label>Password</label>
            <input type="password" name="password" required placeholder="••••••">
        </div>
        <button type="submit">Login to Dashboard</button>
    </form>
</div>
</body>
</html>
