<?php
require_once __DIR__ . '/inc/site.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/guides-data.php';
require_once __DIR__ . '/db.php';

$user = dd_logged_in_user($pdo);
$guides = dd_guides_data();

dd_render_head('Guides and buying advice', [
    'description' => 'Practical buying guides for home, tech, fitness, travel and everyday products.',
    'body_class' => 'storefront-page storefront-guides',
]);
dd_render_storefront_header('guides', $user);
?>
<main>
  <section class="guide-library-hero">
    <div class="shell">
      <nav class="store-breadcrumb" aria-label="Breadcrumb"><a href="<?= dd_e(dd_url('/')) ?>">Home</a><span>›</span><span>Guides</span></nav>
      <div class="guide-library-card">
        <span class="zf-eyebrow"><span class="zf-dot" aria-hidden="true"></span> The library</span>
        <h1>Guides and <span>buying advice.</span></h1>
        <p>Fifteen original buying guides covering home, technology, fitness, travel and everyday products. We focus on useful comparison criteria and never claim hands-on testing unless it actually happened.</p>
      </div>
    </div>
  </section>

  <section class="store-section guide-library-section">
    <div class="shell">
      <div class="guide-count"><span class="zf-dot" aria-hidden="true"></span> <?= number_format(count($guides)) ?> original guides published</div>
      <div class="guide-library-grid">
        <?php foreach ($guides as $slug => $guide): ?>
          <article class="guide-library-item" data-zf-reveal="40">
            <a class="guide-library-art" href="<?= dd_e(dd_url('/guides/' . rawurlencode($slug) . '/')) ?>"><img src="<?= dd_e(dd_media_url($guide['image'])) ?>" alt="" loading="lazy" decoding="async" width="800" height="600"></a>
            <div class="guide-library-copy"><span><?= dd_e($guide['category']) ?></span>
            <h2><a href="<?= dd_e(dd_url('/guides/' . rawurlencode($slug) . '/')) ?>"><?= dd_e($guide['title']) ?></a></h2>
            <p><?= dd_e($guide['excerpt']) ?></p>
            <small><?= dd_e($guide['read_time']) ?> · Updated <?= dd_e($guide['updated']) ?></small></div>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="store-section store-section-faq guide-transparency">
    <div class="shell zf-faq">
      <div>
        <span class="zf-eyebrow"><span class="zf-dot" aria-hidden="true"></span> Editorial standards</span>
        <h2 style="margin:14px 0 0;font-size:clamp(1.7rem,3vw,2.4rem);letter-spacing:-.038em">How these<br><em style="font-style:normal;color:var(--zf-slate-2)">guides work.</em></h2>
        <p style="margin:16px 0 0;color:var(--zf-slate);max-width:40ch">Useful context before you take any recommendation at face value.</p>
      </div>
      <div class="store-faq-list">
        <details open>
          <summary>Do these guides claim every product was personally tested?</summary>
          <p>No. Pickwell does not claim hands-on testing unless that actually happened and is stated clearly. These guides focus on buying criteria, specifications and practical questions shoppers can use when comparing options.</p>
        </details>
        <details>
          <summary>Are prices listed in the guides?</summary>
          <p>No. Prices change quickly, so the guides focus on buying criteria. Check the seller's own page for the current price and availability.</p>
        </details>
        <details>
          <summary>How does Pickwell make money?</summary>
          <p>Some Amazon product links may be affiliate links. Any qualifying commission does not change the price you pay; see our affiliate disclosure for details.</p>
        </details>
        <details>
          <summary>Do brands pay to appear in these guides?</summary>
          <p>Pickwell does not imply a brand paid for placement unless a sponsorship is explicitly disclosed.</p>
        </details>
      </div>
    </div>
  </section>
</main>
<?php dd_render_footer(); ?>
