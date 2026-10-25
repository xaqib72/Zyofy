<?php
require_once __DIR__ . '/inc/site.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/db.php';

$user = dd_logged_in_user($pdo);
dd_render_head('Terms & Conditions', [
    'description' => 'Zyofy terms of service and acceptable use agreement.',
    'body_class' => 'storefront-page editorial-content-page legal-page terms-page',
]);
dd_render_storefront_header('', $user);
?>
<main>
  <section class="editorial-page-intro legal-intro">
    <div class="shell editorial-page-intro-inner">
      <div><span class="editorial-overline"><i></i> THE TERMS OF USING ZYOFY</span><h1>Terms &amp;<br><em>conditions.</em></h1><p>The ground rules for using this publication and its services.</p></div>
      <div class="editorial-intro-stamp"><span>FAIR<br>USE</span><b>✳</b><small>READ BEFORE<br>YOU CONTINUE</small></div>
    </div>
  </section>

  <section class="editorial-document-section">
    <div class="shell editorial-document-layout">
      <aside class="editorial-document-aside"><span>ZYOFY / TERMS</span><h2>Simple rules.<br><em>Clear service.</em></h2><p>These terms describe what Zyofy provides, and what you agree to when using it.</p></aside>
      <article class="prose-card editorial-document">
        <h2>1. Acceptance of Terms</h2>
        <p>By accessing or using Zyofy, you agree to be bound by these Terms &amp; Conditions. If you do not agree with any part of these terms, you must not use this website. Zyofy may update these terms from time to time; continued use constitutes acceptance of any changes.</p>

        <h2>2. Nature of Zyofy</h2>
        <p>Zyofy is an independent website that publishes original buying guides. Zyofy does not sell, manufacture, ship or fulfill any products.</p>

        <h2>3. Affiliate Links</h2>
        <p>Some product links may be affiliate links. Affiliate relationships are disclosed on product pages and in the Affiliate Disclosure. Orders, payment, delivery and returns are handled by the retailer.</p>

        <h2>4. Accuracy of Information</h2>
        <p>Guides describe general buying criteria. Specifications, prices and availability of products change over time, so always verify current details with the seller before making a purchase.</p>

        <h2>5. Acceptable Use</h2>
        <p>You agree not to:</p>
        <ul>
          <li>Use Zyofy for any unlawful purpose</li>
          <li>Attempt to gain unauthorized access to any part of the website or its systems</li>
          <li>Copy, scrape or republish Zyofy content without permission</li>
          <li>Interfere with the normal operation of the website</li>
        </ul>

        <h2>6. Intellectual Property</h2>
        <p>Zyofy's original content, design and branding are the property of Zyofy. Third-party trademarks mentioned on the site belong to their respective owners.</p>

        <h2>7. Third-Party Links</h2>
        <p>Zyofy may link to third-party websites that are not under Zyofy's control. Zyofy is not responsible for, and does not endorse, the content or practices of those sites.</p>

        <h2>8. Disclaimer of Warranties</h2>
        <p>Zyofy is provided on an "as is" and "as available" basis without warranties of any kind, either express or implied. Zyofy does not warrant that the service will be uninterrupted, secure, or error-free. Product information, prices, and availability are provided without warranty of accuracy.</p>

        <h2>9. Limitation of Liability</h2>
        <p>To the fullest extent permitted by law, Zyofy and its operators shall not be liable for any indirect, incidental, special, consequential, or punitive damages, or any loss of profits or revenues, whether incurred directly or indirectly, or any loss of data, use, goodwill, or other intangible losses resulting from your use of or inability to use Zyofy.</p>

        <h2>10. Suspension / Termination</h2>
        <p>Zyofy reserves the right to suspend or terminate user accounts, deny access to the service, or remove content at its sole discretion, without prior notice, for conduct that it believes violates these terms or is harmful to other users, Zyofy, or third parties.</p>

        <h2>11. Changes to Terms</h2>
        <p>Zyofy may revise these terms at any time by updating this page. The "Last updated" date below reflects the most recent revision. Your continued use of Zyofy after any changes constitutes acceptance of the new terms.</p>

        <h2>12. Contact</h2>
        <p>If you have questions about these Terms &amp; Conditions, please contact us at <a href="mailto:<?= dd_e(dd_config('app.support_email', 'mm4979351@gmail.com')) ?>"><?= dd_e(dd_config('app.support_email', 'mm4979351@gmail.com')) ?></a>.</p>

        <p class="form-help" style="margin-top:32px;border-top:1px solid var(--line);padding-top:16px">
          Last updated: 24 September 2026.
        </p>
      </article>
    </div>
  </section>
</main>
<?php dd_render_footer(); ?>
