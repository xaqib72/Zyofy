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
$guideEntries = array_values($guides);
$leadGuide = $guideEntries[0] ?? null;
$restGuides = array_slice($guideEntries, 1, 4);
$featuredGuideSlugs = array_keys($guides);
$leadGuideSlug = $featuredGuideSlugs[0] ?? '';
dd_render_head('Smart shopping, curated', [
    'description' => 'Pickwell curates useful everyday products and pairs them with plain-English buying guides. Independent picks for shoppers in the US, UK and Canada.',
    'body_class' => 'storefront-page storefront-home',
    'og_image' => $heroImage,
    'og_image_alt' => 'Shopping lifestyle image for Pickwell',
]);
dd_render_storefront_header('home', $user);
?>
<main class="zf-home">
  <section class="zf-hero">
    <div class="shell zf-hero-grid">
      <div class="zf-hero-copy">
        <p class="zf-eyebrow"><span class="zf-dot" aria-hidden="true"></span> The Pickwell edit — Vol. 01</p>
        <h1>Fewer tabs.<br><em>Better choices.</em></h1>
        <p class="zf-hero-lede">An independent edit of useful everyday products, paired with plain-English buying guides. Start with a search, or browse the collections we keep coming back to.</p>
        <form class="zf-hero-search" method="get" action="<?= dd_e(dd_url('/products/')) ?>" role="search">
          <label for="zfHeroSearch">Search the product edit</label>
          <div class="zf-search-row">
            <input id="zfHeroSearch" type="search" name="q" placeholder="Headphones, espresso, travel bag…" minlength="2" maxlength="100" autocomplete="off">
            <button type="submit"><span>Search</span><span aria-hidden="true">↗</span></button>
          </div>
        </form>
        <ul class="zf-hero-facts">
          <li><b><?= number_format(count($guides)) ?></b> original guides</li>
          <li><b><?= number_format(count($categories)) ?></b> curated collections</li>
          <li><b>0</b> paid placements</li>
        </ul>
      </div>
      <div class="zf-hero-visual">
        <figure class="zf-hero-figure">
          <img src="<?= dd_e($heroImage) ?>" alt="A considered still life of useful everyday objects" width="1440" height="1550" fetchpriority="high" decoding="async">
          <figcaption class="zf-hero-stamp">
            <span>
              <span>Editorial journal · Est. 2026</span>
              <strong>Useful things,<br>chosen with care.</strong>
            </span>
            <b aria-hidden="true">✳</b>
          </figcaption>
        </figure>
        <?php if ($leadGuide && $leadGuideSlug !== ''): ?>
          <a class="zf-hero-float" href="<?= dd_e(dd_url('/guides/' . rawurlencode($leadGuideSlug) . '/')) ?>">
            <small>Latest guide</small>
            <strong><?= dd_e($leadGuide['title']) ?></strong>
            <span><?= dd_e($leadGuide['read_time']) ?> read <b aria-hidden="true">→</b></span>
          </a>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section class="zf-ribbon" aria-label="Shopping information">
    <div class="shell zf-ribbon-inner">
      <span><i aria-hidden="true">✓</i> Original editorial content</span>
      <span><i aria-hidden="true">✓</i> No hands-on testing claims unless stated</span>
      <span><i aria-hidden="true">✓</i> No paid placements</span>
      <span class="zf-ribbon-note">Check the retailer before you buy</span>
    </div>
  </section>

  <?php if ($featuredProducts): ?>
  <section class="zf-section homepage-product-edit" id="edit">
    <div class="shell">
      <div class="zf-section-head" data-zf-reveal>
        <div>
          <span class="zf-eyebrow"><span class="zf-dot" aria-hidden="true"></span> The product edit</span>
          <h2>Worth a closer <em>look.</em></h2>
          <p>Explore current finds from the catalog and check the retailer page for the latest price, availability and warranty details.</p>
        </div>
        <a class="zf-section-link" href="<?= dd_e(dd_url('/products/')) ?>">Browse all products <span aria-hidden="true">↗</span></a>
      </div>
      <p class="product-amazon-note">Some product links are affiliate links and may earn Pickwell a commission at no extra cost to you. As an Amazon Associate I earn from qualifying purchases. Product details and availability can change; check Amazon for current information.</p>
      <div class="retail-product-grid" data-zf-reveal="60"><?php foreach ($featuredProducts as $product): ?><?= dd_product_card($product) ?><?php endforeach; ?></div>
    </div>
  </section>
  <?php endif; ?>

  <section class="zf-section pickwell-category-index">
    <div class="shell">
      <div class="zf-section-head" data-zf-reveal>
        <div>
          <span class="zf-eyebrow"><span class="zf-dot" aria-hidden="true"></span> Start with what you need</span>
          <h2>Good advice for <em>every corner.</em></h2>
          <p>Five collections, organised around the rooms and routines people actually shop for.</p>
        </div>
        <span class="zf-page-badge"><b><?= str_pad((string) count($categories), 2, '0', STR_PAD_LEFT) ?></b><span>Collections</span></span>
      </div>
      <div class="zf-category-grid" data-zf-reveal="80">
        <?php $categoryIndex = 0; foreach ($categories as $categorySlug => $category): $categoryIndex++; ?>
          <a class="zf-category" href="<?= dd_e(dd_storefront_category_url($categorySlug)) ?>">
            <img src="<?= dd_e(dd_media_url($category['image'])) ?>" alt="" loading="lazy" decoding="async">
            <b><?= str_pad((string) $categoryIndex, 2, '0', STR_PAD_LEFT) ?> / <?= dd_e(strtoupper($category['short'] ?? $category['label'])) ?></b>
            <strong><?= dd_e($category['label']) ?></strong>
            <small><?= number_format($categoryProductCounts[$categorySlug] ?? 0) ?> <?= ($categoryProductCounts[$categorySlug] ?? 0) === 1 ? 'product' : 'products' ?></small>
            <i aria-hidden="true">↗</i>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="zf-section pickwell-guide-feature" id="guides">
    <div class="shell">
      <div class="zf-section-head" data-zf-reveal>
        <div>
          <span class="zf-eyebrow"><span class="zf-dot" aria-hidden="true"></span> A more thoughtful shortlist</span>
          <h2>Advice that <em>gets to the point.</em></h2>
          <p>Original, practical articles covering the questions shoppers should check before choosing a product.</p>
        </div>
        <a class="zf-section-link" href="<?= dd_e(dd_url('/guides/')) ?>">All guides <span aria-hidden="true">↗</span></a>
      </div>
      <div class="zf-guide-grid">
        <?php if ($leadGuide && $leadGuideSlug !== ''): ?>
          <a class="zf-guide-feature" href="<?= dd_e(dd_url('/guides/' . rawurlencode($leadGuideSlug) . '/')) ?>" data-zf-reveal>
            <img src="<?= dd_e(dd_media_url($leadGuide['image'])) ?>" alt="<?= dd_e($leadGuide['title']) ?>" loading="lazy" decoding="async">
            <div class="zf-guide-feature-body">
              <span><?= dd_e($leadGuide['category']) ?> · Editor's pick</span>
              <h3><?= dd_e($leadGuide['title']) ?></h3>
              <p><?= dd_e($leadGuide['excerpt']) ?></p>
              <small><?= dd_e($leadGuide['read_time']) ?> · Updated <?= dd_e($leadGuide['updated']) ?></small>
            </div>
          </a>
        <?php endif; ?>
        <div class="zf-guide-list" data-zf-reveal="60">
          <?php foreach ($restGuides as $restSlug => $guide): ?>
            <a class="zf-guide-row" href="<?= dd_e(dd_url('/guides/' . rawurlencode($restSlug) . '/')) ?>">
              <span class="zf-guide-row-image"><img src="<?= dd_e(dd_media_url($guide['image'])) ?>" alt="" loading="lazy" decoding="async"></span>
              <span class="zf-guide-row-copy">
                <span class="zf-guide-row-kicker"><?= dd_e($guide['category']) ?></span>
                <h3><?= dd_e($guide['title']) ?></h3>
              </span>
              <i aria-hidden="true">→</i>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </section>

  <section class="zf-section store-section-faq">
    <div class="shell zf-faq">
      <div data-zf-reveal>
        <span class="zf-eyebrow"><span class="zf-dot" aria-hidden="true"></span> Worth knowing</span>
        <h2 style="margin:14px 0 0;font-size:clamp(1.8rem,3.2vw,2.6rem);letter-spacing:-.038em">A few things<br>worth <em style="font-style:normal;color:var(--zf-slate-2)">knowing.</em></h2>
        <p style="margin:16px 0 0;color:var(--zf-slate);max-width:40ch">Clear answers so you know what you are looking at before you click through to a retailer.</p>
      </div>
      <div class="store-faq-list" data-zf-reveal="80">
        <details open>
          <summary>What does Pickwell publish?</summary>
          <p>Original buying guides that explain practical comparison points such as size, materials, compatibility, maintenance and everyday usability.</p>
        </details>
        <details>
          <summary>Were these products personally tested?</summary>
          <p>Pickwell does not claim hands-on testing unless that actually happened and is stated clearly on the page.</p>
        </details>
        <details>
          <summary>Does Pickwell use affiliate links?</summary>
          <p>Some Amazon product links may be affiliate links. Any qualifying commission does not change the price you pay; see our affiliate disclosure for details.</p>
        </details>
        <details>
          <summary>Does Pickwell sell or ship products?</summary>
          <p>No. Pickwell is an editorial website and does not sell, ship or fulfill any products.</p>
        </details>
      </div>
    </div>
  </section>

  <section class="zf-section storefront-about-cta">
    <div class="shell">
      <div class="zf-cta" data-zf-reveal>
        <div>
          <span class="zf-eyebrow"><span class="zf-dot" aria-hidden="true"></span> Transparency first</span>
          <h2>Know what this site is<br><span>before you take its word.</span></h2>
          <p>See how the guides are written, how product data is sourced and what Pickwell does — and does not — claim.</p>
        </div>
        <div class="zf-cta-actions">
          <a class="store-btn store-btn-primary" href="<?= dd_e(dd_url('/about/')) ?>">About Pickwell <span aria-hidden="true">→</span></a>
          <a class="store-btn store-btn-secondary light" href="<?= dd_e(dd_url('/affiliate-disclaimer/')) ?>">Read the disclosure</a>
        </div>
      </div>
    </div>
  </section>
</main>
<?php dd_render_footer(); ?>
