<?php
require_once __DIR__ . '/inc/site.php';
require_once __DIR__ . '/inc/layout.php';
if (dd_user_is_logged_in()) {
    header('Location: ' . dd_url('/link-generator/'));
    exit;
}
$csrf = dd_csrf_token();
dd_render_head('Create your account', ['body_class' => 'auth-page register-page', 'description' => 'Create a Pickwell contributor account.']);
dd_render_header();
?>
<main class="auth-shell register-shell">
  <section class="auth-art">
    <span class="zf-eyebrow"><span class="zf-dot" aria-hidden="true"></span> Your Pickwell workspace</span>
    <h1>Start with<br>a better link.</h1>
    <p>Create your contributor account to access the product catalog, link tools and earnings workspace.</p>
    <div class="auth-benefits"><span>Personal tracking tags</span><span>Product link tools</span><span>Clear monthly earnings</span></div>
  </section>
  <section class="auth-panel">
    <div class="auth-card">
      <span class="zf-eyebrow"><span class="zf-dot" aria-hidden="true"></span> Create account</span><h2>Join Pickwell</h2>
      <p class="sub">Set up your account details. An administrator can assign tracking tags after you join.</p>
      <div id="authNotice" role="status" aria-live="polite"></div>
      <form class="form-grid" id="registerForm">
        <input type="hidden" name="csrf_token" value="<?= dd_e($csrf) ?>">
        <div class="field"><label for="name">Full name</label><input id="name" name="name" type="text" autocomplete="name" maxlength="120" required placeholder="Your name"></div>
        <div class="field"><label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="email" maxlength="190" required placeholder="you@example.com"></div>
        <div class="field"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required placeholder="At least 8 characters"></div>
        <div class="field"><label for="confirm_password">Confirm password</label><input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="8" required placeholder="Repeat your password"></div>
        <button class="btn btn-primary btn-lg" type="submit"><span>Create account</span><span aria-hidden="true">→</span></button>
      </form>
      <p class="auth-switch">Already have an account? <a href="<?= dd_e(dd_url('/login/')) ?>">Sign in</a></p>
    </div>
  </section>
</main>
<script>
(() => {
  const form = document.getElementById('registerForm');
  const notice = document.getElementById('authNotice');
  form.addEventListener('submit', async event => {
    event.preventDefault();
    const button = form.querySelector('button[type="submit"]');
    const original = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<span>Creating account…</span>';
    notice.innerHTML = '';
    try {
      const response = await fetch('<?= dd_e(dd_url('/register.php')) ?>', {method:'POST',body:new FormData(form),credentials:'same-origin'});
      const data = await response.json();
      if (!response.ok || !data.success) throw new Error(data.message || 'Could not create the account.');
      window.location.href = data.redirect;
    } catch (error) {
      const item = document.createElement('div');
      item.className = 'notice notice-error';
      item.style.marginBottom = '18px';
      item.textContent = error.message;
      notice.replaceChildren(item);
    } finally {
      button.disabled = false;
      button.innerHTML = original;
    }
  });
})();
</script>
<?php dd_render_footer(); ?>
