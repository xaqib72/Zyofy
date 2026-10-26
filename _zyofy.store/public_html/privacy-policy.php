<?php
require_once __DIR__ . '/inc/site.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/db.php';

$user = dd_logged_in_user($pdo);
dd_render_head('Privacy Policy', [
    'description' => 'Pickwell privacy policy: how we handle account data, cookies and third-party links.',
    'body_class' => 'storefront-page editorial-content-page legal-page privacy-page',
]);
dd_render_storefront_header('', $user);
?>
<main>
  <section class="editorial-page-intro legal-intro">
    <div class="shell">
      <nav class="store-breadcrumb" aria-label="Breadcrumb"><a href="<?= dd_e(dd_url('/')) ?>">Home</a><span>›</span><span>Privacy policy</span></nav>
      <div class="editorial-page-intro-inner">
        <div><span class="zf-eyebrow"><span class="zf-dot" aria-hidden="true"></span> Your data, your business</span><h1>Privacy<br><em>policy.</em></h1><p>What we collect, why we need it, and how we handle it.</p></div>
        <div class="editorial-intro-stamp"><span>YOUR<br>PRIVACY</span><b>✳</b><small>DATA WITH<br>CARE</small></div>
      </div>
    </div>
  </section>

  <section class="editorial-document-section">
    <div class="shell editorial-document-layout">
      <aside class="editorial-document-aside"><span>PICKWELL / POLICY</span><h2>Private<br><em>by practice.</em></h2><p>We collect only information needed to run accounts, prevent abuse and reply to support requests.</p></aside>
      <article class="prose-card editorial-document">
        <h2>Information Pickwell Collects</h2>

        <h3>Account Information</h3>
        <p>New account registration is currently closed. For existing accounts, we hold the full name, email address and a cryptographic hash of the password provided at registration. We do not store plaintext passwords.</p>

        <h3>Session Data</h3>
        <p>Pickwell uses PHP session cookies to maintain your logged-in state and to protect against cross-site request forgery (CSRF). Session data is stored server-side and expires after a period of inactivity.</p>

        <h3>Contact Form</h3>
        <p>When you submit the contact form, your name, email address, and message are sent to our support email. These submissions are not stored in our database.</p>

        <h3>Server Logs</h3>
        <p>Standard web server logs record IP addresses, timestamps, and request details for security and diagnostic purposes. These logs are retained as part of normal server operations.</p>

        <h2>How We Use This Information</h2>
        <p>Collected data is used strictly to:</p>
        <ul>
          <li>Authenticate existing account holders</li>
          <li>Protect the system against unauthorized access or fraud</li>
          <li>Respond to support inquiries submitted through the contact form</li>
        </ul>

        <h2>Password Security</h2>
        <p>Account passwords are protected using industry-standard cryptographic hashing. We never store or transmit plaintext passwords. Password reset tokens are cryptographically randomized and expire after a limited time.</p>

        <h2>Third-Party Links</h2>
        <p>Pickwell may link to third-party websites. When you follow such a link you leave Pickwell, and that site's own privacy notices, cookie policies and terms govern your visit. Pickwell does not control and is not responsible for third-party data practices.</p>

        <h2>Data Sharing</h2>
        <p>Pickwell does not sell, rent, or share personal data with third parties for their marketing purposes. Data is shared only with service providers necessary for platform operation (such as email delivery for support responses) and as required by law.</p>

        <h2>Your Rights</h2>
        <p>Account holders may request access to, correction of, or deletion of their account data by contacting our support team. Account deletion requests will be processed in accordance with applicable data protection regulations.</p>

        <h2>Children's Privacy</h2>
        <p>Pickwell is not intended for users under the age of 18. We do not knowingly collect personal information from children.</p>

        <h2>Changes to This Policy</h2>
        <p>Pickwell may update this privacy policy from time to time. The "Last updated" date below reflects the most recent revision. Continued use of Pickwell after changes constitutes acceptance of the updated policy.</p>

        <h2>Contact</h2>
        <p>For questions about your data or this privacy policy, contact us at <a href="mailto:<?= dd_e(dd_config('app.support_email', 'mm4979351@gmail.com')) ?>"><?= dd_e(dd_config('app.support_email', 'mm4979351@gmail.com')) ?></a>.</p>

        <p class="form-help" style="margin-top:32px;border-top:1px solid var(--line);padding-top:16px">
          Last updated: 24 September 2026.
        </p>
      </article>
    </div>
  </section>
</main>
<?php dd_render_footer(); ?>
