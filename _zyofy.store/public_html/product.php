<?php
require_once __DIR__ . '/inc/site.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/storefront.php';
require_once __DIR__ . '/inc/amazon-products.php';
require_once __DIR__ . '/inc/manual-products.php';
require_once __DIR__ . '/db.php';

$user = dd_logged_in_user($pdo);
$manualSlug = trim((string) ($_GET['manual'] ?? ''));
$manualProduct = null;
if ($manualSlug !== '') {
    try { $manualProduct = dd_manual_product_from_slug($pdo, $manualSlug); }
    catch (Throwable $exception) { error_log('product.php manual product lookup failed: ' . get_class($exception)); }
}
$isManualProduct = is_array($manualProduct);
$asin = strtoupper(trim((string) ($_GET['asin'] ?? '')));
$marketplace = trim((string) ($_GET['marketplace'] ?? ''));
$marketplace = $isManualProduct ? (string) $manualProduct['marketplace'] : $marketplace;
$shareToken = trim((string) ($_GET['share'] ?? ''));
$product = $manualProduct;
$lookupError = '';

if (!$isManualProduct && preg_match('/^[A-Z0-9]{10}$/', $asin)) {
    if ($marketplace === '') {
        $marketplaceStatement = $pdo->prepare('SELECT marketplace FROM products WHERE asin = ? ORDER BY id DESC LIMIT 1');
        $marketplaceStatement->execute([$asin]);
        $marketplace = (string) ($marketplaceStatement->fetchColumn() ?: 'www.amazon.com');
    }
    try {
        $product = dd_creator_get_product($pdo, $asin, $marketplace);
    } catch (Throwable $exception) {
        error_log('product.php Creators API lookup: ' . $exception->getMessage());
    }
    if (!$product) {
        if (dd_store_has_content_freshness($pdo)) {
            $fallback = $pdo->prepare('SELECT p.* FROM products p JOIN marketplace_settings m ON m.marketplace = p.marketplace WHERE p.asin = ? AND p.marketplace = ? AND m.is_active = 1 AND p.content_refreshed_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY LIMIT 1');
            $fallback->execute([$asin, $marketplace]);
            $product = $fallback->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$product) $lookupError = 'Product details are unavailable. Please check the Amazon API connection or return to the catalog.';
    }
    if ($product && dd_store_has_content_freshness($pdo)) {
        // GetItems may omit an offer price; retain the latest cached catalog values
        // instead of replacing a previously fetched price with an empty response.
        $cachedStatement = $pdo->prepare('SELECT p.* FROM products p JOIN marketplace_settings m ON m.marketplace = p.marketplace WHERE p.asin = ? AND p.marketplace = ? AND m.is_active = 1 AND p.content_refreshed_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY ORDER BY p.id DESC LIMIT 1');
        $cachedStatement->execute([$asin, $marketplace]);
        $cachedProduct = $cachedStatement->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($cachedProduct) {
            foreach (['title', 'image', 'description', 'affiliate_link'] as $field) {
                if (trim((string) ($product[$field] ?? '')) === '' && trim((string) ($cachedProduct[$field] ?? '')) !== '') {
                    $product[$field] = $cachedProduct[$field];
                }
            }
        }
    }
}

if (!$product) {
    http_response_code(404);
    dd_render_head('Product not found', ['body_class' => 'storefront-page not-found-page']);
    dd_render_storefront_header('products', $user);
    ?><main class="not-found-main"><div class="shell not-found-card"><span class="not-found-mark">404 <b>✳</b></span><span class="editorial-overline"><i></i> PRODUCT NOT AVAILABLE</span><h1>This listing is<br><em>not here today.</em></h1><p><?= dd_e($lookupError ?: 'We could not find that product in the selected marketplace.') ?></p><div><a class="store-btn store-btn-primary" href="<?= dd_e(dd_url('/products/')) ?>">Back to products <span>↗</span></a></div></div></main><?php
    dd_render_footer();
    exit;
}

$shareTrackingId = '';
if (!$isManualProduct && $shareToken !== '' && preg_match('/^[A-Za-z0-9_-]{20,64}$/', $shareToken)) {
    try {
        $shareStatement = $pdo->prepare('SELECT ps.tracking_id FROM product_shares ps JOIN products p ON p.id = ps.product_id JOIN marketplace_settings m ON m.marketplace = ps.marketplace WHERE ps.share_token = ? AND p.asin = ? AND p.marketplace = ? AND m.is_active = 1 LIMIT 1');
        $shareStatement->execute([$shareToken, $asin, $marketplace]);
        $shareTrackingId = (string) ($shareStatement->fetchColumn() ?: '');
        if ($shareTrackingId !== '') {
            $updateShare = $pdo->prepare('UPDATE product_shares SET last_used_at = NOW() WHERE share_token = ?');
            $updateShare->execute([$shareToken]);
        }
    } catch (Throwable $exception) {
        error_log('product.php share lookup: ' . $exception->getMessage());
    }
}

$affiliateLink = trim((string) ($product['affiliate_link'] ?? ''));
if (!$isManualProduct && $shareTrackingId !== '') $affiliateLink = dd_amazon_link($marketplace, $asin, $shareTrackingId);
elseif (!$isManualProduct) {
    // For catalog pages without a contributor share token, honor the affiliate tag
    // selected by the admin when this product was saved.
    try {
        $savedLink = $pdo->prepare('SELECT affiliate_link FROM products WHERE asin = ? AND marketplace = ? ORDER BY id DESC LIMIT 1');
        $savedLink->execute([$asin, $marketplace]);
        $catalogAffiliateLink = trim((string) ($savedLink->fetchColumn() ?: ''));
        if ($catalogAffiliateLink !== '') $affiliateLink = $catalogAffiliateLink;
    } catch (Throwable $exception) {
        error_log('product.php saved affiliate link lookup: ' . $exception->getMessage());
    }
}
$image = trim((string) ($product['image'] ?? ''));
if ($image !== '') {
    $imageParts = parse_url($image);
    if (!is_array($imageParts) || strtolower((string) ($imageParts['scheme'] ?? '')) !== 'https' || empty($imageParts['host'])) {
        $image = '';
    }
}
$description = trim((string) ($product['description'] ?? ''));
dd_render_head((string) ($product['title'] ?? 'Product details'), [
    'description' => 'Product information and details for ' . (string) ($product['title'] ?? 'this product') . '.',
    'body_class' => 'storefront-page storefront-product-detail',
    'og_image' => $image,
]);
dd_render_storefront_header('products', $user);
?>
<main class="product-detail-main">
  <div class="shell">
    <?php if ($affiliateLink !== ''): ?>
      <div class="product-detail-action product-detail-action-top">
        <div class="product-detail-cta-top"><a class="product-detail-buy" href="<?= dd_e($affiliateLink) ?>" target="_blank" rel="sponsored nofollow noopener">View on Amazon <span aria-hidden="true">↗</span></a><small class="product-detail-paid-link">Paid link</small></div>
        <p class="product-detail-disclosure product-detail-disclosure-highlight"><strong>Affiliate disclosure</strong><span>As an Amazon Associate I earn from qualifying purchases.</span></p>
      </div>
    <?php endif; ?>
    <nav class="store-breadcrumb" aria-label="Breadcrumb"><a href="<?= dd_e(dd_url('/')) ?>">Home</a><span>›</span><a href="<?= dd_e(dd_url('/products/')) ?>">Products</a><span>›</span><span><?= dd_e($asin) ?></span></nav>
    <section class="product-detail-panel">
      <div class="product-detail-gallery"><div class="product-detail-visual"><?php if ($image !== ''): ?><img src="<?= dd_e($image) ?>" alt="<?= dd_e($product['title'] ?? 'Product image') ?>" decoding="async"><?php else: ?><span class="product-detail-image-unavailable">Product image unavailable</span><?php endif; ?><span class="product-detail-market"><?= dd_e(dd_marketplace_label($pdo, $marketplace)) ?></span></div>
      </div>
      <div class="product-detail-copy">
        <span class="editorial-overline"><i></i> PRODUCT INFORMATION</span>
        <h1><?= dd_e($product['title'] ?? 'Product details') ?></h1>
        <p class="product-detail-asin"><?= $isManualProduct ? 'Marketplace' : 'ASIN ' . dd_e($asin) ?> <span aria-hidden="true">·</span> <?= dd_e($isManualProduct ? preg_replace('/^www\./', '', $marketplace) : dd_marketplace_label($pdo, $marketplace)) ?></p>
        <?php if ($description !== ''): ?>
          <section class="product-detail-facts" aria-labelledby="productDetailsHeading">
            <h2 id="productDetailsHeading">Product details</h2>
            <ul class="product-detail-description">
              <?php foreach (preg_split('/\r\n|\r|\n/', $description) as $detail): $detail = trim($detail); if ($detail === '') continue; ?>
                <li><?= dd_e($detail) ?></li>
              <?php endforeach; ?>
            </ul>
          </section>
        <?php endif; ?>
        <p class="product-detail-content-notice"><strong><?= $isManualProduct ? 'Product information' : 'Amazon content notice' ?></strong><span><?= $isManualProduct ? 'Product details are provided by Zyofy. Check the Amazon listing for current product information.' : 'Certain content that appears on this site comes from Amazon. This content is provided as is and may change or be removed at any time.' ?></span></p>
      </div>
      <?php if ($affiliateLink === ''): ?><p class="product-search-notice">This product does not have a valid retailer link yet.</p><?php endif; ?>
    </section>
  </div>
</main>
<?php dd_render_footer(); ?>
