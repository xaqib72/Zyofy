<?php
require_once __DIR__ . '/inc/site.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/guides-data.php';
require_once __DIR__ . '/inc/storefront.php';
require_once __DIR__ . '/db.php';

$user = dd_logged_in_user($pdo);
$guides = dd_guides_data();
$categories = dd_store_categories();
$categoryProductCounts = [];
foreach ($categories as $categorySlug => $_category) {
    try { $categoryProductCounts[$categorySlug] = dd_store_category_count($pdo, $categorySlug); }
    catch (Throwable $exception) { $categoryProductCounts[$categorySlug] = 0; }
}

$heroImage = 'https://images.unsplash.com/photo-1767720580810-58be50f89bf8?auto=format&fit=crop&w=2000&q=88';
$featuredProducts = [];
try {
    if (!dd_store_has_content_freshness($pdo)) throw new RuntimeException('Catalog freshness migration is not installed.');
    $productOrder = dd_store_has_created_at($pdo) ? 'p.created_at DESC, p.id DESC' : 'p.id DESC';
    $featuredProducts = $pdo->query('SELECT p.* FROM products p JOIN marketplace_settings m ON m.marketplace = p.marketplace WHERE m.is_active = 1 AND p.content_refreshed_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY ORDER BY ' . $productOrder . ' LIMIT 4')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $exception) {
    error_log('index.php featured products: ' . $exception->getMessage());
}
try {
    $manualFeatured = $pdo->query('SELECT slug AS manual_slug, title, image_url AS image, description, affiliate_url AS affiliate_link, marketplace, NULL AS price, NULL AS price_refreshed_at, created_at FROM manual_product_posts ORDER BY created_at DESC, id DESC LIMIT 4')->fetchAll(PDO::FETCH_ASSOC);
    $featuredProducts = array_merge($manualFeatured, $featuredProducts);
    usort($featuredProducts, static function ($a, $b) {
        return (strtotime((string) ($b['created_at'] ?? '')) ?: 0) <=> (strtotime((string) ($a['created_at'] ?? '')) ?: 0);
    });
    $featuredProducts = array_slice($featuredProducts, 0, 4);
} catch (Throwable $exception) {
    // This optional section appears after migration 012 is installed.
}
dd_render_head('Practical buying guides', [
    'description' => 'Original, practical buying guides covering home, tech, fitness, travel and everyday products.',
    'body_class' => 'storefront-page storefront-home',
    'og_image' => $heroImage,
    'og_image_alt' => 'Shopping lifestyle image for Zyofy',
]);
dd_render_storefront_header('home', $user);
?>
<main>
  <section class="zyofy-editorial-hero">
    <div class="shell editorial-intro">
      <div class="editorial-headline">
        <span class="editorial-overline"><i></i> THE GOOD CHOICE EDIT <b>01 / 04</b></span>
        <h1>Choose well.<br><em>Live easier.</em></h1>
      </div>
      <div class="editorial-intro-aside">
        <p>Buying advice for everyday things, shaped around what matters once the unboxing is over.</p>
        <div class="editorial-hero-actions"><a class="store-btn store-btn-primary" href="<?= dd_e(dd_url('/guides/')) ?>">Explore the guides <span>↗</span></a><a class="editorial-text-link" href="<?= dd_e(dd_url('/about/')) ?>">How we work <span>→</span></a></div>
        <span class="editorial-note"><b><?= number_format(count($guides)) ?></b> useful guides <i></i> Made for real life</span>
      </div>
    </div>
    <div class="shell editorial-tableau">
      <img src="<?= dd_e($heroImage) ?>" alt="A considered still life of useful everyday objects" width="1440" height="900" fetchpriority="high" decoding="async">
      <div class="tableau-index">ZYOFY <span>·</span> EDITORIAL JOURNAL <span>·</span> EST. 2026</div>
      <div class="tableau-caption"><span class="tableau-caption-mark">✳</span><span><small>LESS NOISE, BETTER PICKS</small><strong>Useful things.<br>Chosen with care.</strong></span></div>
      <div class="tableau-side-note">OBJECTS FOR<br>EVERYDAY LIVING <b>↗</b></div>
    </div>
  </section>

  <section class="storefront-trust-strip" aria-label="Shopping information">
    <div class="shell storefront-trust-items">
      <span><i>✓</i> Original editorial content</span>
      <span><i>✓</i> No hands-on testing claims unless stated</span>
      <span><i>✓</i> No paid placements</span>
    </div>
  </section>

  <?php if ($featuredProducts): ?>
  <section class="store-section homepage-product-edit">
    <div class="shell">
      <div class="store-section-head"><div><span class="editorial-overline">THE PRODUCT EDIT</span><h2>Worth a closer <span>look.</span></h2><p>Explore current finds and check the retailer page for the latest details.</p></div><a class="store-section-link" href="<?= dd_e(dd_url('/products/')) ?>">Browse all products <span>›</span></a></div>
      <p class="product-amazon-note">Some product links are affiliate links and may earn Zyofy a commission at no extra cost to you. As an Amazon Associate I earn from qualifying purchases. Product details and availability can change; check Amazon for current information.</p>
      <div class="retail-product-grid"><?php foreach ($featuredProducts as $product): ?><?= dd_product_card($product) ?><?php endforeach; ?></div>
    </div>
  </section>
  <?php endif; ?>

  <section class="zyofy-category-index">
    <div class="shell"><div class="category-index-heading"><div><span class="editorial-overline">START WITH WHAT YOU NEED</span><h2>Good advice for <em>every corner.</em></h2></div><span class="category-index-count">05 <i>/</i> COLLECTIONS</span></div>
      <div class="zyofy-category-grid">
      <?php foreach ($categories as $categorySlug => $category): ?>
        <a class="zyofy-category-card" href="<?= dd_e(dd_storefront_category_url($categorySlug)) ?>">
          <span class="category-art"><img src="<?= dd_e(dd_media_url($category['image'])) ?>" alt="" loading="lazy" decoding="async"></span>
          <span class="category-card-caption"><span><strong><?= dd_e($category['label']) ?></strong><small><?= number_format($categoryProductCounts[$categorySlug] ?? 0) ?> <?= ($categoryProductCounts[$categorySlug] ?? 0) === 1 ? 'product' : 'products' ?></small></span><b aria-hidden="true">↗</b></span>
        </a>
      <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="store-section zyofy-guide-feature" id="guides">
    <div class="shell">
      <div class="store-section-head">
        <div>
          <div><span class="editorial-overline">A MORE THOUGHTFUL SHORTLIST</span><h2>Advice that <span>gets to the point.</span></h2></div>
          <p>Original, practical articles covering the questions shoppers should check before choosing a product.</p>
        </div>
        <a class="store-section-link" href="<?= dd_e(dd_url('/guides/')) ?>">All guides <span>›</span></a>
      </div>
      <div class="guide-preview-grid">
        <?php foreach ($guides as $slug => $guide): ?>
          <article class="guide-preview-card">
            <a class="guide-preview-image" style="--image-position:<?= dd_e($guide['position'] ?? 'center') ?>" href="<?= dd_e(dd_url('/guides/' . rawurlencode($slug) . '/')) ?>"><img src="<?= dd_e(dd_media_url($guide['image'])) ?>" alt="<?= dd_e($guide['title']) ?>" loading="lazy" decoding="async" width="800" height="450"></a>
            <div class="guide-preview-body">
              <span><?= dd_e($guide['category']) ?></span>
              <h3><a href="<?= dd_e(dd_url('/guides/' . rawurlencode($slug) . '/')) ?>"><?= dd_e($guide['title']) ?></a></h3>
              <p><?= dd_e($guide['excerpt']) ?></p>
              <small><?= dd_e($guide['read_time']) ?> · Updated <?= dd_e($guide['updated']) ?></small>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="store-section store-section-faq">
    <div class="shell">
      <div class="store-section-head compact">
        <div>
          <h2>A few things <span>worth knowing</span></h2>
          <p>Clear answers so you know what you are looking at before you click.</p>
        </div>
      </div>
      <div class="store-faq-list">
        <details open>
          <summary>What does Zyofy publish?</summary>
          <p>Original buying guides that explain practical comparison points such as size, materials, compatibility, maintenance and everyday usability.</p>
        </details>
        <details>
          <summary>Were these products personally tested?</summary>
          <p>Zyofy does not claim hands-on testing unless that actually happened and is stated clearly on the page.</p>
        </details>
        <details>
          <summary>Does Zyofy use affiliate links?</summary>
          <p>Some Amazon product links may be affiliate links. Any qualifying commission does not change the price you pay; see our affiliate disclosure for details.</p>
        </details>
        <details>
          <summary>Does Zyofy sell or ship products?</summary>
          <p>No. Zyofy is an editorial website and does not sell, ship or fulfill any products.</p>
        </details>
      </div>
    </div>
  </section>

  <section class="store-section storefront-about-cta">
    <div class="shell storefront-about-card">
      <div>
        <span class="storefront-kicker light">Transparency first</span>
        <h2>Know what this site is<br><span>before you take its word.</span></h2>
        <p>See how the guides are written and what Zyofy does — and does not — claim.</p>
      </div>
      <div class="storefront-about-actions">
        <a class="store-btn store-btn-primary" href="<?= dd_e(dd_url('/about/')) ?>">About Zyofy <span>→</span></a>
        <a class="store-btn store-btn-secondary light" href="<?= dd_e(dd_url('/affiliate-disclaimer/')) ?>">Disclosure</a>
      </div>
    </div>
  </section>
</main>
<?php dd_render_footer(); ?>
