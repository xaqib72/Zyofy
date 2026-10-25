<?php
require_once __DIR__ . '/site.php';

function dd_render_head($title, array $options = [])
{
    $description = $options['description'] ?? 'Zyofy helps shoppers discover useful products, fresh finds and practical buying guides.';
    $bodyClass = $options['body_class'] ?? '';
    $canonical = $options['canonical'] ?? dd_url($_SERVER['REQUEST_URI'] ?? '/');
    $noindex = dd_config('app.noindex', false);
    $ogTitle = $options['og_title'] ?? ($title . ' · Zyofy');
    $ogDescription = $options['og_description'] ?? $description;
    $ogUrl = $options['og_url'] ?? $canonical;
    $ogType = $options['og_type'] ?? 'website';
    $ogImage = trim((string) ($options['og_image'] ?? ''));
    $ogImageAlt = $options['og_image_alt'] ?? $title;
    $siteCssVersion = is_file(__DIR__ . '/../assets/css/site.css') ? (string) filemtime(__DIR__ . '/../assets/css/site.css') : '20260905';
    $signalCssVersion = is_file(__DIR__ . '/../assets/css/zyofy-2026.css') ? (string) filemtime(__DIR__ . '/../assets/css/zyofy-2026.css') : '20261025';
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title><?= dd_e($title) ?> · Zyofy</title>
  <meta name="description" content="<?= dd_e($description) ?>">
  <?php if ($noindex): ?><meta name="robots" content="noindex,nofollow,noarchive"><?php endif; ?>
  <link rel="canonical" href="<?= dd_e($canonical) ?>">

  <meta property="og:site_name" content="Zyofy">
  <meta property="og:type" content="<?= dd_e($ogType) ?>">
  <meta property="og:title" content="<?= dd_e($ogTitle) ?>">
  <meta property="og:description" content="<?= dd_e($ogDescription) ?>">
  <meta property="og:url" content="<?= dd_e($ogUrl) ?>">
  <?php if ($ogImage !== ''): ?>
  <meta property="og:image" content="<?= dd_e($ogImage) ?>">
  <meta property="og:image:secure_url" content="<?= dd_e($ogImage) ?>">
  <meta property="og:image:alt" content="<?= dd_e($ogImageAlt) ?>">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:image" content="<?= dd_e($ogImage) ?>">
  <?php else: ?>
  <meta name="twitter:card" content="summary">
  <?php endif; ?>
  <meta name="twitter:title" content="<?= dd_e($ogTitle) ?>">
  <meta name="twitter:description" content="<?= dd_e($ogDescription) ?>">

  <link rel="icon" type="image/svg+xml" href="<?= dd_e(dd_url('/assets/images/zyofy-mark.svg')) ?>">
  <meta name="theme-color" content="#0a101c">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400;12..96,600;12..96,700;12..96,800&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap">
  <?php /* site.css keeps the shared base utilities; the two earlier visual
           refreshes are superseded by the 2026 "Signal" design layer below. */ ?>
  <link rel="stylesheet" href="<?= dd_e(dd_url('/assets/css/site.css?v=' . $siteCssVersion)) ?>">
  <link rel="stylesheet" href="<?= dd_e(dd_url('/assets/css/zyofy-2026.css?v=' . $signalCssVersion)) ?>">
</head>
<body class="<?= dd_e($bodyClass) ?>">
<?php
}

function dd_render_affiliate_header_disclosure()
{
    ?>
<div class="affiliate-header-disclosure zf-disclosure" role="note" aria-label="Amazon affiliate disclosure">
  <div class="shell zf-disclosure-inner">
    <span class="zf-disclosure-badge">Affiliate</span>
    <p><strong>As an Amazon Associate I earn from qualifying purchases.</strong> Commission never changes the price you pay.</p>
    <a href="<?= dd_e(dd_url('/affiliate-disclaimer/')) ?>">How this works <span aria-hidden="true">↗</span></a>
  </div>
</div>
<?php
}

/* Existing contributor/internal header kept intact for generator/account pages. */
function dd_render_header($active = '', $user = null)
{
    $links = [
        'home' => ['Home', '/'],
        'guides' => ['Guides', '/guides/'],
        'about' => ['About', '/about/'],
        'contact' => ['Contact', '/contact/'],
    ];
    ?>
<?php dd_render_affiliate_header_disclosure(); ?>
<header class="site-header zf-header" data-site-header>
  <div class="shell zf-header-inner">
    <a class="brand zf-brand" href="<?= dd_e(dd_url('/')) ?>" aria-label="Zyofy home">
      <span class="brand-mark zf-brand-mark" aria-hidden="true"><img src="<?= dd_e(dd_url('/assets/images/zyofy-mark.svg')) ?>" alt=""></span>
      <span class="brand-name zf-brand-type"><b>Zyofy</b><small>THE GOOD CHOICE EDIT</small></span>
    </a>
    <nav class="desktop-nav zf-nav" aria-label="Primary navigation">
      <?php foreach ($links as $key => [$label, $path]): ?>
        <a class="nav-link zf-nav-link<?= $active === $key ? ' is-active' : '' ?>" href="<?= dd_e(dd_url($path)) ?>"><?= dd_e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="header-actions zf-header-tools">
      <?php if ($user): ?>
        <a class="btn btn-ghost btn-sm zf-btn-ghost zf-btn-sm" href="<?= dd_e(dd_url('/logout.php')) ?>">Sign out</a>
      <?php else: ?>
        <a class="zf-account-link" href="<?= dd_e(dd_url('/login/')) ?>">Sign in</a>
      <?php endif; ?>
      <button class="menu-toggle zf-menu-toggle" type="button" aria-label="Toggle navigation" aria-expanded="false" data-menu-toggle><span></span><span></span><span></span></button>
    </div>
  </div>
  <div class="mobile-menu zf-mobile-menu" data-mobile-menu>
    <div class="shell mobile-menu-inner zf-mobile-inner">
      <?php foreach ($links as $key => [$label, $path]): ?>
        <a href="<?= dd_e(dd_url($path)) ?>"><?= dd_e($label) ?></a>
      <?php endforeach; ?>
      <div class="zf-mobile-foot">
        <?php if ($user): ?>
          <a href="<?= dd_e(dd_url('/logout.php')) ?>">Sign out</a>
        <?php else: ?>
          <a href="<?= dd_e(dd_url('/login/')) ?>">Sign in</a>
          <a class="zf-mobile-primary" href="<?= dd_e(dd_url('/register/')) ?>">Create account</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</header>
<?php
}

function dd_render_storefront_header($active = '', $user = null)
{
    $links = $user ? [
        'home' => ['Discover', '/'],
        'products' => ['Products', '/products/'],
        'generator' => ['Generate link', '/link-generator/'],
        'earnings' => ['My earnings', '/my-earnings/'],
    ] : [
        'home' => ['Discover', '/'],
        'products' => ['Products', '/products/'],
        'guides' => ['Buying guides', '/guides/'],
        'about' => ['Our approach', '/about/'],
        'contact' => ['Contact', '/contact/'],
    ];
    $accountName = (string) ($user['name'] ?? $user['email'] ?? 'Contributor');
    $accountInitial = function_exists('mb_substr')
        ? mb_strtoupper(mb_substr($accountName, 0, 1, 'UTF-8'), 'UTF-8')
        : strtoupper(substr($accountName, 0, 1));
    ?>
<?php dd_render_affiliate_header_disclosure(); ?>
<header class="storefront-header zf-header" data-site-header>
  <div class="shell storefront-header-main zf-header-inner">
    <a class="storefront-brand zf-brand" href="<?= dd_e(dd_url('/')) ?>" aria-label="Zyofy home">
      <span class="storefront-brand-mark zf-brand-mark" aria-hidden="true"><img src="<?= dd_e(dd_url('/assets/images/zyofy-mark.svg')) ?>" alt=""></span>
      <span class="storefront-wordmark zf-brand-type"><b>Zyofy</b><small>THE GOOD CHOICE EDIT</small></span>
    </a>
    <nav class="storefront-nav zf-nav" aria-label="Primary navigation">
      <?php foreach ($links as $key => [$label, $path]): ?>
        <a class="zf-nav-link<?= $active === $key ? ' is-active' : '' ?>" href="<?= dd_e(dd_url($path)) ?>"><?= dd_e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="storefront-account zf-header-tools">
      <form class="zf-quick-search" method="get" action="<?= dd_e(dd_url('/products/')) ?>" role="search">
        <label class="zf-sr-only" for="zfHeaderSearch">Search products</label>
        <input id="zfHeaderSearch" type="search" name="q" placeholder="Search finds" minlength="2" maxlength="100">
        <button type="submit" aria-label="Search products"><span aria-hidden="true">→</span></button>
      </form>
      <div class="zf-account">
        <?php if ($user): ?>
          <span class="zf-account-user"><i aria-hidden="true"><?= dd_e($accountInitial) ?></i><span><?= dd_e($accountName) ?></span></span>
          <a class="storefront-signout-button zf-btn zf-btn-ghost zf-btn-sm" href="<?= dd_e(dd_url('/logout.php')) ?>">Sign out</a>
        <?php else: ?>
          <a class="storefront-signin zf-account-link" href="<?= dd_e(dd_url('/login/')) ?>">Sign in</a>
          <a class="storefront-account-link storefront-join-link zf-btn zf-btn-primary zf-btn-sm" href="<?= dd_e(dd_url('/register/')) ?>">Create account <span aria-hidden="true">↗</span></a>
        <?php endif; ?>
      </div>
      <button class="menu-toggle storefront-menu-toggle zf-menu-toggle" type="button" aria-label="Toggle navigation" aria-expanded="false" data-menu-toggle><span></span><span></span><span></span></button>
    </div>
  </div>
  <div class="mobile-menu storefront-mobile-menu zf-mobile-menu" data-mobile-menu><div class="shell mobile-menu-inner zf-mobile-inner">
    <?php foreach ($links as $key => [$label, $path]): ?><a href="<?= dd_e(dd_url($path)) ?>"><?= dd_e($label) ?></a><?php endforeach; ?>
    <a href="<?= dd_e(dd_url('/affiliate-disclaimer/')) ?>">Affiliate disclosure</a>
    <div class="zf-mobile-foot">
      <?php if ($user): ?>
        <a href="<?= dd_e(dd_url('/logout.php')) ?>">Sign out</a>
      <?php else: ?>
        <a href="<?= dd_e(dd_url('/login/')) ?>">Sign in</a>
        <a class="zf-mobile-primary" href="<?= dd_e(dd_url('/register/')) ?>">Create account</a>
      <?php endif; ?>
    </div>
  </div></div>
</header>
<?php
}

function dd_render_footer()
{
    ?>
<footer class="zyofy-footer zf-footer">
  <div class="shell">
    <div class="zf-footer-top">
      <div class="zyofy-footer-brand zf-footer-brand">
        <a class="storefront-brand zf-brand" href="<?= dd_e(dd_url('/')) ?>">
          <span class="storefront-brand-mark zf-brand-mark"><img src="<?= dd_e(dd_url('/assets/images/zyofy-mark.svg')) ?>" alt=""></span>
          <span class="storefront-wordmark zf-brand-type"><b>Zyofy</b><small>THE GOOD CHOICE EDIT</small></span>
        </a>
        <p>Clear-headed buying advice for the things that make everyday life work. Independent guides, honest limits, no paid placements.</p>
        <a class="footer-about-link zf-section-link" style="color:#fff;border-color:rgba(201,244,91,.6)" href="<?= dd_e(dd_url('/about/')) ?>">How we put guides together <span aria-hidden="true">↗</span></a>
      </div>
      <div class="zyofy-footer-links zf-footer-col">
        <span class="footer-label zf-footer-label">Explore</span>
        <a href="<?= dd_e(dd_url('/products/')) ?>">Product catalog</a>
        <a href="<?= dd_e(dd_url('/guides/')) ?>">All buying guides</a>
        <a href="<?= dd_e(dd_url('/#guides')) ?>">Latest edits</a>
        <a href="<?= dd_e(dd_url('/contact/')) ?>">Ask a question</a>
      </div>
      <div class="zyofy-footer-links zf-footer-col">
        <span class="footer-label zf-footer-label">Zyofy</span>
        <a href="<?= dd_e(dd_url('/about/')) ?>">Our approach</a>
        <a href="<?= dd_e(dd_url('/contact/')) ?>">Contact</a>
        <a href="<?= dd_e(dd_url('/login/')) ?>">Contributor sign in</a>
        <a href="<?= dd_e(dd_url('/register/')) ?>">Create an account</a>
      </div>
      <div class="zyofy-footer-links zf-footer-col">
        <span class="footer-label zf-footer-label">The details</span>
        <a href="<?= dd_e(dd_url('/affiliate-disclaimer/')) ?>">Affiliate disclosure</a>
        <a href="<?= dd_e(dd_url('/privacy-policy/')) ?>">Privacy policy</a>
        <a href="<?= dd_e(dd_url('/terms-conditions/')) ?>">Terms &amp; conditions</a>
      </div>
    </div>
    <div class="zyofy-footer-disclosure zf-footer-disclosure">
      <span class="zyofy-footer-disclosure-mark zf-footer-disclosure-mark" aria-hidden="true">i</span>
      <div>
        <strong>Amazon affiliate disclosure</strong>
        <p>As an Amazon Associate I earn from qualifying purchases. Some links may earn Zyofy a commission at no extra cost to you.</p>
        <p>CERTAIN CONTENT THAT APPEARS ON THIS SITE COMES FROM AMAZON. THIS CONTENT IS PROVIDED &quot;AS IS&quot; AND IS SUBJECT TO CHANGE OR REMOVAL AT ANY TIME.</p>
      </div>
    </div>
  </div>
  <div class="zyofy-footer-bottom zf-footer-bottom">
    <div class="shell" style="display:flex;flex-wrap:wrap;gap:10px 24px;justify-content:space-between">
      <span>© <?= date('Y') ?> Zyofy Store</span>
      <span>Considered choices, made simpler.</span>
      <span><a href="<?= dd_e(dd_url('/affiliate-disclaimer/')) ?>">Disclosure</a> · <a href="<?= dd_e(dd_url('/privacy-policy/')) ?>">Privacy</a> · <a href="<?= dd_e(dd_url('/terms-conditions/')) ?>">Terms</a></span>
    </div>
  </div>
</footer>
<script src="<?= dd_e(dd_url('/assets/js/site.js?v=' . (is_file(__DIR__ . '/../assets/js/site.js') ? filemtime(__DIR__ . '/../assets/js/site.js') : '1'))) ?>" defer></script>
<script src="<?= dd_e(dd_url('/assets/js/zyofy-2026.js?v=' . (is_file(__DIR__ . '/../assets/js/zyofy-2026.js') ? filemtime(__DIR__ . '/../assets/js/zyofy-2026.js') : '1'))) ?>" defer></script>
</body>
</html>
<?php
}

function dd_product_card(array $product)
{
    $isManual = !empty($product['manual_slug']);
    if ($isManual && !function_exists('dd_manual_product_url')) require_once __DIR__ . '/manual-products.php';
    $image = $isManual ? trim((string) ($product['image'] ?? '')) : dd_amazon_image($product['image'] ?? '');
    $asin = $product['asin'] ?? '';
    $publicUrl = $isManual ? dd_manual_product_url($product['manual_slug']) : dd_product_public_url($asin, $product['marketplace'] ?? null);
    $marketplaceLabel = preg_replace('/^www\./', '', (string) ($product['marketplace'] ?? 'Amazon'));
    ?>
<article class="product-card retail-product-card zf-card">
  <a class="product-card-image zf-card-media" href="<?= dd_e($publicUrl) ?>" aria-label="View <?= dd_e($product['title'] ?? 'product') ?>">
    <?php if ($image): ?>
      <img src="<?= dd_e($image) ?>" alt="<?= dd_e($product['title'] ?? 'Amazon product') ?>" loading="lazy" decoding="async" width="320" height="320">
    <?php else: ?>
      <span class="image-placeholder"><img src="<?= dd_e(dd_url('/assets/images/zyofy-mark.svg')) ?>" alt="Zyofy"></span>
    <?php endif; ?>
  </a>
  <div class="product-card-body zf-card-body">
    <span class="marketplace-pill zf-card-tag"><?= dd_e($marketplaceLabel ?: 'Amazon') ?></span>
    <h3><a href="<?= dd_e($publicUrl) ?>" title="<?= dd_e($product['title'] ?? 'Amazon product') ?>"><?= dd_e($product['title'] ?? 'Untitled product') ?></a></h3>
    <a class="retail-product-btn zf-card-link" href="<?= dd_e($publicUrl) ?>"><span>View product</span><span aria-hidden="true">↗</span></a>
  </div>
</article>
<?php
}
