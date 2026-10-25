<?php
require_once __DIR__ . '/inc/site.php';
require_once __DIR__ . '/inc/layout.php';
$csrf = dd_csrf_token();
dd_render_head('Forgot Password', ['body_class' => 'auth-page', 'description' => 'Reset your Zyofy account password securely.']);
dd_render_header();
?>
<main class="auth-shell">
  <section class="auth-art">
    <span class="hero-kicker">Account Recovery</span>
    <h1>Reset access.<br>Keep moving.</h1>
    <p>Enter the email address registered on your Zyofy account. If it exists in our system, we’ll dispatch a secure one-hour password reset link.</p>
    <div class="auth-benefits">
      <span>Time-limited one-hour security token</span>
      <span>Cryptographically verified reset flow</span>
      <span>Direct return to your account</span>
    </div>
  </section>

  <section class="auth-panel">
    <div class="auth-card">
      <span class="eyebrow">Password Help</span>
      <h2>Reset your password</h2>
      <p class="sub">For account privacy, identical confirmation is returned whether the address exists or not.</p>

      <div id="authNotice"></div>

      <form class="form-grid" id="forgotForm">
        <input type="hidden" name="csrf_token" value="<?= dd_e($csrf) ?>">

        <div class="field">
          <label for="email">Account email address</label>
          <input id="email" name="email" type="email" required autocomplete="email" placeholder="you@example.com">
        </div>

        <button class="btn btn-primary btn-lg" type="submit">
          <span>Send recovery link</span>
          <span aria-hidden="true">→</span>
        </button>
      </form>

      <p class="auth-switch">
        <a href="<?= dd_e(dd_url('/login/')) ?>">← Back to login</a>
      </p>
    </div>
  </section>
</main>

<script>
(() => {
  'use strict';
  const form = document.getElementById('forgotForm');
  const notice = document.getElementById('authNotice');

  form.addEventListener('submit', async e => {
    e.preventDefault();
    const btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<span>Sending link…</span>';
    notice.innerHTML = '';

    try {
      const r = await fetch('<?= dd_e(dd_url('/forgot-password.php')) ?>', {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin'
      });
      const d = await r.json();
      if (!d.success) {
        throw new Error(d.message || 'Could not dispatch recovery link.');
      }
      notice.innerHTML = '<div class="notice notice-info" style="margin-bottom:20px">' + escapeHtml(d.message) + '</div>';
      form.reset();
    } catch (err) {
      notice.innerHTML = '<div class="notice notice-error" style="margin-bottom:20px">' + escapeHtml(err.message) + '</div>';
    } finally {
      btn.disabled = false;
      btn.innerHTML = originalText;
    }
  });

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[c]));
  }
})();
</script>
<?php dd_render_footer(); ?>
