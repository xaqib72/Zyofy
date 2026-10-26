<?php
require_once __DIR__ . '/inc/site.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/db.php';

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$valid = false;
$email = '';
$notice = '';
$success = false;

if ($token !== '') {
    $stmt = $pdo->prepare('SELECT email, expires_at FROM password_resets WHERE token=? LIMIT 1');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if ($row && strtotime($row['expires_at']) >= time()) {
        $valid = true;
        $email = $row['email'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dd_csrf_validate($_POST['csrf_token'] ?? '')) {
        $notice = 'Your session expired. Reload the reset link and try again.';
    } elseif (!$valid) {
        $notice = 'This reset link is invalid or has expired.';
    } else {
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        if (strlen($password) < 8) {
            $notice = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirm) {
            $notice = 'The passwords do not match.';
        } else {
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE users SET password=? WHERE email=?')->execute([password_hash($password, PASSWORD_DEFAULT), $email]);
                $pdo->prepare('DELETE FROM password_resets WHERE email=?')->execute([$email]);
                $pdo->commit();
                $success = true;
                $valid = false;
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log('reset-password.php: ' . $e->getMessage());
                $notice = 'Could not update your password right now.';
            }
        }
    }
}

$csrf = dd_csrf_token();
dd_render_head('Reset Password · Pickwell', ['body_class' => 'auth-page']);
dd_render_header();
?>
<main class="auth-shell">
  <section class="auth-art">
    <span class="zf-eyebrow"><span class="zf-dot" aria-hidden="true"></span> Security Update</span>
    <h1>Choose a new<br>password.</h1>
    <p>Password reset links are single-use and expire one hour after generation for your protection.</p>
    <div class="auth-benefits">
      <span>Argon2 / Bcrypt cryptographic security</span>
      <span>Immediate invalidation of prior reset links</span>
      <span>Instant re-authentication into your dashboard</span>
    </div>
  </section>

  <section class="auth-panel">
    <div class="auth-card">
      <span class="zf-eyebrow"><span class="zf-dot" aria-hidden="true"></span> Account Security</span>
      <h2><?= $success ? 'Password updated' : 'Reset your password' ?></h2>

      <?php if ($success): ?>
        <div class="notice notice-info" style="margin:20px 0">
          Your password has been changed successfully. You may now log in with your new credentials.
        </div>
        <a class="btn btn-primary btn-lg" href="<?= dd_e(dd_url('/login/')) ?>" style="width:100%">
          <span>Continue to login</span>
          <span aria-hidden="true">→</span>
        </a>

      <?php elseif (!$valid): ?>
        <div class="notice notice-error" style="margin:20px 0">
          <?= dd_e($notice ?: 'This password recovery link is invalid or has expired.') ?>
        </div>
        <a class="btn btn-primary btn-lg" href="<?= dd_e(dd_url('/forgot-password/')) ?>" style="width:100%">
          <span>Request a new link</span>
          <span aria-hidden="true">→</span>
        </a>

      <?php else: ?>
        <p class="sub">Please enter at least 8 characters for your new password.</p>

        <?php if ($notice): ?>
          <div class="notice notice-error" style="margin-bottom:20px"><?= dd_e($notice) ?></div>
        <?php endif; ?>

        <form method="post" class="form-grid">
          <input type="hidden" name="token" value="<?= dd_e($token) ?>">
          <input type="hidden" name="csrf_token" value="<?= dd_e($csrf) ?>">

          <div class="field">
            <label for="password">New password</label>
            <input id="password" type="password" name="password" required minlength="8" autocomplete="new-password" placeholder="At least 8 characters">
          </div>

          <div class="field">
            <label for="confirm_password">Confirm new password</label>
            <input id="confirm_password" type="password" name="confirm_password" required minlength="8" autocomplete="new-password" placeholder="Repeat your new password">
          </div>

          <button class="btn btn-primary btn-lg" type="submit">
            <span>Update password</span>
            <span aria-hidden="true">→</span>
          </button>
        </form>
      <?php endif; ?>
    </div>
  </section>
</main>
<?php dd_render_footer(); ?>
