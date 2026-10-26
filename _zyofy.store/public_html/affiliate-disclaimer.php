<?php
require_once __DIR__ . '/inc/site.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/db.php';

$user = dd_logged_in_user($pdo);
dd_render_head('Affiliate Disclosure', [
    'description' => 'Pickwell affiliate disclosure and transparency statement.',
    'body_class' => 'storefront-page editorial-content-page legal-page disclosure-page',
]);
dd_render_storefront_header('', $user);
?>
<main>
  <section class="editorial-page-intro legal-intro">
    <div class="shell">
      <nav class="store-breadcrumb" aria-label="Breadcrumb"><a href="<?= dd_e(dd_url('/')) ?>">Home</a><span>›</span><span>Affiliate disclosure</span></nav>
      <div class="editorial-page-intro-inner">
        <div><span class="zf-eyebrow"><span class="zf-dot" aria-hidden="true"></span> Transparency, in plain language</span><h1>Affiliate<br><em>disclosure.</em></h1><p>How Pickwell is funded and what you should know about our editorial content.</p></div>
        <div class="editorial-intro-stamp"><span>OPEN<br>BOOK</span><b>✳</b><small>OUR CURRENT<br>RELATIONSHIPS</small></div>
      </div>
    </div>
  </section>

  <section class="editorial-document-section">
    <div class="shell editorial-document-layout">
      <aside class="editorial-document-aside"><span>PICKWELL / POLICY</span><h2>Clear by<br><em>design.</em></h2><p>We explain how the site works so readers can make informed decisions.</p></aside>
      <article class="prose-card editorial-document">
        <h2>Current Status</h2>
        <p>Product pages may include tracked links to Amazon. Pickwell does not sell, ship or fulfill the products; purchases are completed with Amazon.</p>
        <p><strong>As an Amazon Associate I earn from qualifying purchases.</strong></p>

        <h2>What This Site Contains</h2>
        <p>Pickwell publishes original buying guides that explain practical comparison points such as size, materials, compatibility, maintenance and everyday usability. The guides are written to help readers compare options on their own.</p>

        <h2>Future Affiliate Relationships</h2>
        <p>Affiliate links are identified near product links. A qualifying purchase may earn a commission without changing the price you pay.</p>

        <h2>Editorial Independence</h2>
        <p>Guides on Pickwell are not paid placements. Pickwell does not label content as sponsored unless there is an actual sponsorship arrangement, and any such arrangement would be disclosed clearly.</p>

        <p class="form-help" style="margin-top:32px;border-top:1px solid var(--line);padding-top:16px">
          Last updated: 24 September 2026.
        </p>
      </article>
    </div>
  </section>
</main>
<?php dd_render_footer(); ?>
