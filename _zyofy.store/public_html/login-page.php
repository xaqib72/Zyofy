<?php
require_once __DIR__ . '/inc/site.php';
require_once __DIR__ . '/inc/layout.php';
if (dd_user_is_logged_in()) {
    header('Location: ' . dd_url('/link-generator/'));
    exit;
}
$csrf = dd_csrf_token();
dd_render_head('Login', ['body_class' => 'auth-page', 'description' => 'Sign in to your Zyofy account.']);
dd_render_header();
?>
<main class="auth-shell">
  <section class="auth-art">
    <span class="zf-eyebrow"><span class="zf-dot" aria-hidden="true"></span> Welcome Back</span>
    <h1>Account<br>sign in.</h1>
    <p>Sign in to your existing Zyofy account. New registrations are currently closed.</p>
    <div class="auth-benefits">
      <span>Original buying guides</span>
      <span>No paid placements</span>
    </div>
  </section>

  <section class="auth-panel">
    <div class="auth-card">
      <span class="zf-eyebrow"><span class="zf-dot" aria-hidden="true"></span> Account Access</span>
      <h2>Login to Zyofy</h2>
      <p class="sub">Enter the email address and password connected to your account.</p>

      <div id="authNotice"></div>

      <form class="form-grid" id="loginForm">
        <input type="hidden" name="csrf_token" value="<?= dd_e($csrf) ?>">

        <div class="field">
          <label for="email">Email address</label>
          <input id="email" name="email" type="email" autocomplete="email" required placeholder="you@example.com">
        </div>

        <div class="field">
          <label for="password">Password</label>
          <input id="password" name="password" type="password" autocomplete="current-password" required placeholder="Your password">
        </div>

        <div class="form-meta">
          <span></span>
          <a href="<?= dd_e(dd_url('/forgot-password/')) ?>">Forgot password?</a>
        </div>

        <button class="btn btn-primary btn-lg" type="submit">
          <span>Login to account</span>
          <span aria-hidden="true">→</span>
        </button>
      </form>

      <p class="auth-switch">New to Zyofy? <a href="<?= dd_e(dd_url('/register/')) ?>">Create an account</a><br><span>Questions?</span> <a href="<?= dd_e(dd_url('/contact/')) ?>">Contact us</a></p>
    </div>
  </section>
</main>

<script>
(() => {
  'use strict';
  const form = document.getElementById('loginForm');
  const notice = document.getElementById('authNotice');

  form.addEventListener('submit', async e => {
    e.preventDefault();
    const btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<span>Logging in…</span>';
    notice.innerHTML = '';

    try {
      const r = await fetch('<?= dd_e(dd_url('/login.php')) ?>', {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin'
      });
      const d = await r.json();
      if (!d.success) {
        throw new Error(d.message || 'Login failed. Please check your credentials.');
      }
      window.location.href = d.redirect;
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
