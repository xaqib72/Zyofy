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
    <title>Admin Login - Pickwell</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <?php $brandPrefix = '../'; require __DIR__ . '/partials/brand_icons.php'; ?>

    <link rel="stylesheet" href="assets/pickwell-admin.css?v=<?= rawurlencode((string) filemtime(__DIR__ . '/assets/pickwell-admin.css')) ?>">

</head>
<body class="pickwell-admin-login">
<div class="login-card">
    <div class="login-header">
        <a class="login-brand" href="../index.php" aria-label="Pickwell home"><img src="../assets/brand/pickwell-logo-light.svg" alt="Pickwell" width="220" height="44"></a>
        <h1>Sign in to Pickwell</h1>
        <p class="sub">Merchant &amp; affiliate control panel</p>
    </div>

    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (!$adminExists): ?>
        <div class="error" style="background: rgba(31, 122, 77, 0.2); border-left-color: #1f7a4d;">
            ⚠️ No admin account found. <a href="setup-admin.php" style="color:#1f7a4d; font-weight:600;">Click here to create the first admin account</a>
        </div>
    <?php endif; ?>

    <form method="POST">
        <div class="input-group">
            <label>Email Address</label>
            <input type="email" name="email" required placeholder="admin@pickwell.t86.site">
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
