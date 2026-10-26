<?php
require_once __DIR__ . '/inc/site.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/db.php';

$user = dd_logged_in_user($pdo);
$sent = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dd_csrf_validate($_POST['csrf_token'] ?? '')) {
        $error = 'Your session has expired. Please reload the page and try again.';
    } elseif (trim((string) ($_POST['website'] ?? '')) !== '') {
        $sent = true;
    } else {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($message) < 10) {
            $error = 'Please enter your name, a valid email address, and a message of at least 10 characters.';
        } else {
            $to = dd_config('app.support_email', 'mm4979351@gmail.com');
            $subject = 'Pickwell contact from ' . $name;
            $body = "Name: $name\nEmail: $email\n\n$message";
            $headers = "From: Pickwell <{$to}>\r\nReply-To: {$email}";
            @mail($to, $subject, $body, $headers);
            $sent = true;
        }
    }
}

$csrf = dd_csrf_token();
dd_render_head('Contact Us', ['description' => 'Contact Pickwell with questions, corrections, account issues, or feedback.', 'body_class' => 'storefront-page editorial-content-page contact-page']);
dd_render_storefront_header('contact', $user);
?>
<main>
  <section class="page-hero">
    <div class="shell content-head">
      <nav class="store-breadcrumb" aria-label="Breadcrumb"><a href="<?= dd_e(dd_url('/')) ?>">Home</a><span>›</span><span>Contact</span></nav>
      <span class="zf-eyebrow"><span class="zf-dot" aria-hidden="true"></span> Contact us</span>
      <h1>Questions, corrections or feedback?</h1>
      <p>Contact Pickwell about our guides, account access or site feedback. Pickwell does not sell products, so for an order, payment, shipping, return or refund, please contact the seller you purchased from.</p>
    </div>
  </section>

  <section class="section section-white">
    <div class="shell contact-grid">
      <!-- Left Contact Info Card -->
      <aside class="contact-card">
        <span class="eyebrow">Pickwell support</span>
        <h3>We're here to help.</h3>
        <p style="color:var(--muted);margin-bottom:20px;line-height:1.6">
          For general questions, content corrections, account access or site support, use the form or email us directly.
        </p>

        <div class="contact-method">
          <span>Owner phone</span>
          <a href="tel:<?= dd_e(preg_replace('/[^0-9+]/', '', (string) dd_config('app.support_phone', '+923192945306'))) ?>">
            <?= dd_e(dd_config('app.support_phone', '+923192945306')) ?>
          </a>
        </div>

        <div class="contact-method">
          <span>Owner email</span>
          <a href="mailto:<?= dd_e(dd_config('app.support_email', 'mm4979351@gmail.com')) ?>">
            <?= dd_e(dd_config('app.support_email', 'mm4979351@gmail.com')) ?>
          </a>
        </div>
      </aside>

      <!-- Right Contact Form Card -->
      <section class="contact-form">
        <?php if ($sent): ?>
          <div class="notice notice-info">
            <strong>Message received!</strong> Thank you for reaching out. The Pickwell support team has received your message and will respond shortly.
          </div>
        <?php else: ?>
          <?php if ($error): ?>
            <div class="notice notice-error" style="margin-bottom:20px"><?= dd_e($error) ?></div>
          <?php endif; ?>

          <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= dd_e($csrf) ?>">
            <!-- Honeypot anti-spam field -->
            <div style="display:none">
              <input name="website" tabindex="-1" autocomplete="off">
            </div>

            <div class="form-row">
              <div class="field">
                <label for="contactName">Your name</label>
                <input
                  id="contactName"
                  name="name"
                  required
                  placeholder="Alex Morgan"
                  value="<?= dd_e($_POST['name'] ?? ($user['name'] ?? '')) ?>"
                >
              </div>

              <div class="field">
                <label for="contactEmail">Email address</label>
                <input
                  id="contactEmail"
                  name="email"
                  type="email"
                  required
                  placeholder="alex@example.com"
                  value="<?= dd_e($_POST['email'] ?? ($user['email'] ?? '')) ?>"
                >
              </div>
            </div>

            <div class="field">
              <label for="contactMessage">How can we assist you?</label>
              <textarea
                id="contactMessage"
                name="message"
                required
                placeholder="Describe your question or issue in detail…"
              ><?= dd_e($_POST['message'] ?? '') ?></textarea>
            </div>

            <button class="btn btn-primary btn-lg" type="submit">
              <span>Send message</span>
              <span aria-hidden="true">→</span>
            </button>
          </form>
        <?php endif; ?>
      </section>
    </div>
  </section>
</main>
<?php dd_render_footer(); ?>
