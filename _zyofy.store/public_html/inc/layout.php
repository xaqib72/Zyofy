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
    $storeCssVersion = is_file(__DIR__ . '/../assets/css/storefront.css') ? (string) filemtime(__DIR__ . '/../assets/css/storefront.css') : '20260905';
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
  <meta name="theme-color" content="#18233a">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="<?= dd_e(dd_url('/assets/css/site.css?v=' . $siteCssVersion)) ?>">
  <link rel="stylesheet" href="<?= dd_e(dd_url('/assets/css/storefront.css?v=' . $storeCssVersion)) ?>">
  <?php $premiumCssVersion = is_file(__DIR__ . '/../assets/css/premium-refresh.css') ? (string) filemtime(__DIR__ . '/../assets/css/premium-refresh.css') : '1'; ?>
  <link rel="stylesheet" href="<?= dd_e(dd_url('/assets/css/premium-refresh.css?v=' . $premiumCssVersion)) ?>">
</head>
<body class="<?= dd_e($bodyClass) ?>">
<?php
}

function dd_render_affiliate_header_disclosure()
{
    ?>
<div class="affiliate-header-disclosure" role="note" aria-label="Amazon affiliate disclosure">
  <span class="affiliate-header-disclosure-mark" aria-hidden="true">i</span>
  <span class="affiliate-header-disclosure-text"><strong>Affiliate disclosure</strong><span>As an Amazon Associate I earn from qualifying purchases.</span></span>
  <a href="<?= dd_e(dd_url('/affiliate-disclaimer/')) ?>">Learn more <b aria-hidden="true">↗</b></a>
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
<header class="site-header" data-site-header>
  <?php dd_render_affiliate_header_disclosure(); ?>
  <div class="shell header-inner">
    <a class="brand" href="<?= dd_e(dd_url('/')) ?>" aria-label="Zyofy home">
      <span class="brand-mark" aria-hidden="true"><img src="<?= dd_e(dd_url('/assets/images/zyofy-mark.svg')) ?>" alt=""></span><span class="brand-name">Zyofy</span>
    </a>
    <nav class="desktop-nav" aria-label="Primary navigation">
      <?php foreach ($links as $key => [$label, $path]): ?>
        <a class="nav-link<?= $active === $key ? ' is-active' : '' ?>" href="<?= dd_e(dd_url($path)) ?>"><?= dd_e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="header-actions">
      <?php if ($user): ?>
        <a class="btn btn-ghost btn-sm" href="<?= dd_e(dd_url('/logout.php')) ?>">Logout</a>
      <?php endif; ?>
      <button class="menu-toggle" type="button" aria-label="Toggle navigation" aria-expanded="false" data-menu-toggle><span></span><span></span><span></span></button>
    </div>
  </div>
  <div class="mobile-menu" data-mobile-menu>
    <div class="shell mobile-menu-inner">
      <?php foreach ($links as $key => [$label, $path]): ?>
        <a href="<?= dd_e(dd_url($path)) ?>"><?= dd_e($label) ?></a>
      <?php endforeach; ?>
      <?php if ($user): ?>
        <a href="<?= dd_e(dd_url('/logout.php')) ?>">Logout</a>
      <?php endif; ?>
    </div>
  </div>
</header>
<?php
}

function dd_render_storefront_header($active = '', $user = null)
{
    $links = $user ? [
        'home' => ['Home', '/'],
        'products' => ['Products', '/products/'],
        'generator' => ['Generate Link', '/link-generator/'],
        'earnings' => ['My Earnings', '/my-earnings/'],
    ] : [
        'home' => ['Discover', '/'],
        'products' => ['Products', '/products/'],
        'guides' => ['Buying guides', '/guides/'],
        'about' => ['Our approach', '/about/'],
        'contact' => ['Contact', '/contact/'],
    ];
    ?>
<header class="storefront-header" data-site-header>
  <?php dd_render_affiliate_header_disclosure(); ?>
  <div class="shell storefront-header-main">
    <a class="storefront-brand" href="<?= dd_e(dd_url('/')) ?>" aria-label="Zyofy home">
      <span class="storefront-brand-mark" aria-hidden="true"><img src="<?= dd_e(dd_url('/assets/images/zyofy-mark.svg')) ?>" alt=""></span>
      <span class="storefront-wordmark">Zyofy<small>THE GOOD CHOICE EDIT</small></span>
    </a>
    <div class="storefront-header-note"><span class="header-spark">✳</span><span>Thoughtful picks. Clear advice.<small>Independent guides for everyday decisions.</small></span></div>
    <div class="storefront-account">
      <?php if ($user): ?>
        <span class="storefront-user-name"><?= dd_e($user['name'] ?? $user['email'] ?? 'Contributor') ?></span>
        <a class="storefront-signout-button" href="<?= dd_e(dd_url('/logout.php')) ?>">Sign out</a>
      <?php else: ?>
        <a class="storefront-signin" href="<?= dd_e(dd_url('/login/')) ?>">Sign in</a>
        <a class="storefront-account-link storefront-join-link" href="<?= dd_e(dd_url('/register/')) ?>">Create account <span aria-hidden="true">↗</span></a>
      <?php endif; ?>
      <button class="menu-toggle storefront-menu-toggle" type="button" aria-label="Toggle navigation" aria-expanded="false" data-menu-toggle><span></span><span></span><span></span></button>
    </div>
  </div>
  <div class="storefront-nav-rail"><nav class="shell storefront-nav" aria-label="Primary navigation">
    <?php foreach ($links as $key => [$label, $path]): ?>
      <a class="<?= $active === $key ? 'is-active' : '' ?>" href="<?= dd_e(dd_url($path)) ?>"><?= dd_e($label) ?></a>
    <?php endforeach; ?>
    <span class="nav-rail-note">CURATED FOR REAL LIFE <b>✳</b></span>
  </nav></div>
  <div class="mobile-menu storefront-mobile-menu" data-mobile-menu><div class="shell mobile-menu-inner">
    <?php foreach ($links as $key => [$label, $path]): ?><a href="<?= dd_e(dd_url($path)) ?>"><?= dd_e($label) ?></a><?php endforeach; ?>
    <?php if ($user): ?><a class="mobile-signout" href="<?= dd_e(dd_url('/logout.php')) ?>">Sign out</a><?php else: ?><a href="<?= dd_e(dd_url('/login/')) ?>">Sign in</a><a href="<?= dd_e(dd_url('/register/')) ?>">Create account</a><?php endif; ?>
    <a href="<?= dd_e(dd_url('/affiliate-disclaimer/')) ?>">Affiliate disclosure</a>
  </div></div>
</header>
<?php
}

function dd_render_footer()
{
    ?>
<footer class="zyofy-footer">
  <div class="shell zyofy-footer-disclosure"><span class="zyofy-footer-disclosure-mark" aria-hidden="true">i</span><div><strong>Amazon affiliate disclosure</strong><p>As an Amazon Associate I earn from qualifying purchases. Some links may earn Zyofy a commission at no extra cost to you.</p><p>CERTAIN CONTENT THAT APPEARS ON THIS SITE COMES FROM AMAZON. THIS CONTENT IS PROVIDED &quot;AS IS&quot; AND IS SUBJECT TO CHANGE OR REMOVAL AT ANY TIME.</p></div></div>
  <div class="shell zyofy-footer-main">
    <div class="zyofy-footer-brand">
      <a class="storefront-brand" href="<?= dd_e(dd_url('/')) ?>"><span class="storefront-brand-mark"><img src="<?= dd_e(dd_url('/assets/images/zyofy-mark.svg')) ?>" alt=""></span><span class="storefront-wordmark">Zyofy<small>THE GOOD CHOICE EDIT</small></span></a>
      <p>Clear-headed buying advice for the things that make everyday life work.</p>
      <a class="footer-about-link" href="<?= dd_e(dd_url('/about/')) ?>">How we put guides together <span>↗</span></a>
    </div>
    <div class="zyofy-footer-links"><span class="footer-label">EXPLORE</span><a href="<?= dd_e(dd_url('/guides/')) ?>">All buying guides</a><a href="<?= dd_e(dd_url('/#guides')) ?>">Latest edits</a><a href="<?= dd_e(dd_url('/contact/')) ?>">Ask a question</a></div>
    <div class="zyofy-footer-links"><span class="footer-label">ZYOFY</span><a href="<?= dd_e(dd_url('/about/')) ?>">Our approach</a><a href="<?= dd_e(dd_url('/contact/')) ?>">Contact</a><a href="<?= dd_e(dd_url('/login/')) ?>">Contributor sign in</a><a href="<?= dd_e(dd_url('/register/')) ?>">Create an account</a></div>
    <div class="zyofy-footer-links"><span class="footer-label">THE DETAILS</span><a href="<?= dd_e(dd_url('/affiliate-disclaimer/')) ?>">Affiliate disclosure</a><a href="<?= dd_e(dd_url('/privacy-policy/')) ?>">Privacy policy</a><a href="<?= dd_e(dd_url('/terms-conditions/')) ?>">Terms &amp; conditions</a></div>
  </div>
  <div class="zyofy-footer-bottom"><div class="shell"><span>© <?= date('Y') ?> Zyofy Store</span><span>Considered choices, made simpler. <b>✳</b></span></div></div>
</footer>
<script src="<?= dd_e(dd_url('/assets/js/site.js?v=' . (is_file(__DIR__ . '/../assets/js/site.js') ? filemtime(__DIR__ . '/../assets/js/site.js') : '1'))) ?>" defer></script>
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
<article class="product-card retail-product-card">
  <a class="product-card-image" href="<?= dd_e($publicUrl) ?>" aria-label="View <?= dd_e($product['title'] ?? 'product') ?>">
    <?php if ($image): ?>
      <img src="<?= dd_e($image) ?>" alt="<?= dd_e($product['title'] ?? 'Amazon product') ?>" loading="lazy" decoding="async" width="320" height="320">
    <?php else: ?>
      <span class="image-placeholder"><img src="<?= dd_e(dd_url('/assets/images/zyofy-mark.svg')) ?>" alt="Zyofy"></span>
    <?php endif; ?>
  </a>
  <div class="product-card-body">
    <span class="marketplace-pill"><?= dd_e($marketplaceLabel ?: 'Amazon') ?></span>
    <h3><a href="<?= dd_e($publicUrl) ?>" title="<?= dd_e($product['title'] ?? 'Amazon product') ?>"><?= dd_e($product['title'] ?? 'Untitled product') ?></a></h3>
    <a class="retail-product-btn" href="<?= dd_e($publicUrl) ?>">View product <span aria-hidden="true">↗</span></a>
  </div>
</article>
<?php
}
