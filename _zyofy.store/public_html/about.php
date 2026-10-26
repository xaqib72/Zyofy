<?php
require_once __DIR__ . '/inc/site.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/db.php';

$user = dd_logged_in_user($pdo);
dd_render_head('About Us', [
    'description' => 'Learn who runs Pickwell, how our original buying guides are written and what we do and do not claim.',
    'body_class' => 'storefront-page editorial-content-page about-page',
]);
dd_render_storefront_header('about', $user);
?>
<main>
  <section class="editorial-page-intro about-intro">
    <div class="shell">
      <nav class="store-breadcrumb" aria-label="Breadcrumb"><a href="<?= dd_e(dd_url('/')) ?>">Home</a><span>›</span><span>About</span></nav>
      <div class="editorial-page-intro-inner">
        <div><span class="zf-eyebrow"><span class="zf-dot" aria-hidden="true"></span> The Pickwell point of view</span>
          <h1>Good decisions start<br>with <em>better questions.</em></h1>
          <p>Pickwell is an independently operated buying-guide publication. We make practical comparisons easier, without pretending every item was personally tested.</p>
        </div>
        <div class="editorial-intro-stamp"><span>PICKWELL</span><b>✳</b><small>INDEPENDENT<br>BUYING GUIDES</small></div>
      </div>
    </div>
  </section>

  <section class="editorial-document-section">
    <div class="shell editorial-document-layout">
      <aside class="editorial-document-aside"><span>01 / ABOUT</span><h2>Useful context.<br><em>Honest limits.</em></h2><p>Our job is to help you compare options with a clearer view of what matters.</p><a href="<?= dd_e(dd_url('/guides/')) ?>">Explore our guides <b>↗</b></a></aside>
      <article class="prose-card editorial-document">
        <h2>What we publish</h2>
        <p>Pickwell publishes original buying guides. Each guide explains practical comparison points such as size, materials, compatibility, maintenance, storage, comfort and everyday usability, with a checklist readers can use when comparing options from any seller.</p>

        <h2>How our guides are written</h2>
        <p>Our guides are editorial content created for Pickwell. They are written around common buying questions and product specifications. We do not claim hands-on testing unless that actually happened and is stated clearly on the page. We also do not copy customer reviews from other websites or present star ratings as our own.</p>

        <h2>How product information works</h2>
        <p>Specifications, prices and availability change over time. Always check the seller's own product page for current details before you buy.</p>

        <h2>How Pickwell earns money</h2>
        <p>Some product links may be affiliate links. Where they appear, the relationship is disclosed clearly. Any commission does not change the price you pay.</p>

        <h2>What we do not do</h2>
        <ul>
          <li>Pickwell does not sell, ship or fulfill products.</li>
                    <li>We do not publish prices, because they change too quickly to stay accurate.</li>
          <li>We do not claim a product was personally tested unless the article explicitly says so.</li>
        </ul>

        <h2>Questions or corrections</h2>
        <p>If you spot incorrect information or have a question about Pickwell, visit our <a href="<?= dd_e(dd_url('/contact/')) ?>">Contact Us</a> page or email <a href="mailto:<?= dd_e(dd_config('app.support_email', 'mm4979351@gmail.com')) ?>"><?= dd_e(dd_config('app.support_email', 'mm4979351@gmail.com')) ?></a>.</p>
      </article>
    </div>
  </section>
</main>
<?php dd_render_footer(); ?>
