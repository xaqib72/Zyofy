<?php
require_once __DIR__ . '/inc/site.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/storefront.php';
require_once __DIR__ . '/inc/amazon-products.php';
require_once __DIR__ . '/db.php';

$user = dd_logged_in_user($pdo);
$query = trim((string) ($_GET['q'] ?? ''));
$marketplace = trim((string) ($_GET['marketplace'] ?? 'www.amazon.com'));
$category = trim((string) ($_GET['category'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$categories = dd_store_categories();
$perPage = 24;
$products = [];
$catalogTotal = 0;
$error = '';
$isLiveSearch = false;

$marketplaces = $pdo->query('SELECT marketplace, country_label FROM marketplace_settings WHERE is_active = 1 ORDER BY country_label')->fetchAll(PDO::FETCH_ASSOC);
if ($marketplaces && !in_array($marketplace, array_column($marketplaces, 'marketplace'), true)) {
    $marketplace = (string) $marketplaces[0]['marketplace'];
}

if ($query !== '') {
    try {
        $searchTerms = $category !== '' && isset($categories[$category]) ? $query . ' ' . $categories[$category]['label'] : $query;
        $products = dd_creator_search_products($pdo, $searchTerms, $marketplace, $page);
        $catalogTotal = count($products);
        $isLiveSearch = true;
    } catch (Throwable $exception) {
        error_log('products.php Creators API search: ' . $exception->getMessage());
        $error = $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : 'Product search is temporarily unavailable. Check the marketplace API connection in Admin, then try again.';
    }
}

if (!$isLiveSearch && dd_store_has_content_freshness($pdo)) {
    $params = [];
    $where = ['m.is_active = 1'];
    $where[] = 'p.content_refreshed_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY';
    if ($category !== '') {
        $clause = dd_store_category_clause($category, $params, 'pc');
        if ($clause !== '') $where[] = $clause;
    }
    if ($query !== '') {
        $where[] = '(p.title LIKE :product_q OR p.asin LIKE :product_asin)';
        $params[':product_q'] = '%' . $query . '%';
        $params[':product_asin'] = '%' . $query . '%';
    }
    $whereSql = implode(' AND ', $where);
    $count = $pdo->prepare("SELECT COUNT(*) FROM products p JOIN marketplace_settings m ON m.marketplace = p.marketplace WHERE $whereSql");
    $count->execute($params);
    $catalogTotal = (int) $count->fetchColumn();
    $productOrder = dd_store_has_created_at($pdo) ? 'p.created_at DESC, p.id DESC' : 'p.id DESC';
    $statement = $pdo->prepare("SELECT p.* FROM products p JOIN marketplace_settings m ON m.marketplace = p.marketplace WHERE $whereSql ORDER BY $productOrder LIMIT :limit OFFSET :offset");
    foreach ($params as $key => $value) $statement->bindValue($key, $value, PDO::PARAM_STR);
    // Fetch the prefix needed for a global newest-first merge with manual posts.
    $statement->bindValue(':limit', min(10000, $page * $perPage), PDO::PARAM_INT);
    $statement->bindValue(':offset', 0, PDO::PARAM_INT);
    $statement->execute();
    $products = $statement->fetchAll(PDO::FETCH_ASSOC);
} elseif (!$isLiveSearch) {
    $error = 'Catalog caching needs the content freshness database migration. Live keyword search is still available above.';
}

$manualProducts = [];
$manualProductTotal = 0;
try {
    $manualWhere = ['marketplace = :manual_market'];
    $manualParams = [':manual_market' => $marketplace];
    if ($query !== '') {
        $manualWhere[] = '(title LIKE :manual_title OR description LIKE :manual_description)';
        $manualParams[':manual_title'] = '%' . $query . '%';
        $manualParams[':manual_description'] = '%' . $query . '%';
    }
    $manualCountStatement = $pdo->prepare('SELECT COUNT(*) FROM manual_product_posts WHERE ' . implode(' AND ', $manualWhere));
    $manualCountStatement->execute($manualParams);
    $manualProductTotal = (int) $manualCountStatement->fetchColumn();
    $manualStatement = $pdo->prepare('SELECT slug AS manual_slug, title, image_url AS image, description, affiliate_url AS affiliate_link, marketplace, NULL AS price, NULL AS price_refreshed_at, created_at FROM manual_product_posts WHERE ' . implode(' AND ', $manualWhere) . ' ORDER BY created_at DESC, id DESC LIMIT :manual_limit OFFSET 0');
    foreach ($manualParams as $key => $value) $manualStatement->bindValue($key, $value, PDO::PARAM_STR);
    $manualStatement->bindValue(':manual_limit', min(10000, $page * $perPage), PDO::PARAM_INT);
    $manualStatement->execute();
    $manualProducts = $manualStatement->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $exception) {
    // The separate migration enables this optional catalog.
}

if (!$isLiveSearch) $catalogTotal += $manualProductTotal;
$displayProducts = array_merge($manualProducts, $products);
usort($displayProducts, static function ($a, $b) {
    $aTime = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
    $bTime = strtotime((string) ($b['created_at'] ?? '')) ?: 0;
    return ($bTime <=> $aTime) ?: ((int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0));
});
if (!$isLiveSearch) $displayProducts = array_slice($displayProducts, ($page - 1) * $perPage, $perPage);

$description = $query !== '' ? 'Search product listings on Amazon by keyword.' : 'Browse products and fresh finds from Amazon.';
dd_render_head('Products', ['description' => $description, 'body_class' => 'storefront-page storefront-products']);
dd_render_storefront_header('products', $user);
?>
<main>
  <section class="product-catalog-intro">
    <div class="shell product-catalog-intro-inner">
      <div><span class="editorial-overline"><i></i> THE PRODUCT EDIT</span><h1>Find your<br><em>next good thing.</em></h1><p>Search the Amazon catalog, compare the details and check the retailer page for the latest price and availability.</p></div>
      <div class="catalog-count-stamp"><b><?= number_format($catalogTotal) ?></b><span><?= $isLiveSearch ? 'LIVE RESULTS' : 'IN THE CATALOG' ?></span><i>✳</i></div>
    </div>
  </section>
  <section class="product-catalog-body">
    <div class="shell">
      <form class="product-search-panel" method="get" action="<?= dd_e(dd_url('/products/')) ?>">
        <label class="product-search-field"><span>What are you looking for?</span><input type="search" name="q" value="<?= dd_e($query) ?>" placeholder="Try headphones, coffee maker, travel bag…" minlength="2" maxlength="100"></label>
        <label class="product-market-field"><span>Marketplace</span><select name="marketplace"><?php foreach ($marketplaces as $row): ?><option value="<?= dd_e($row['marketplace']) ?>"<?= $marketplace === $row['marketplace'] ? ' selected' : '' ?>><?= dd_e($row['country_label']) ?></option><?php endforeach; ?></select></label>
        <label class="product-market-field"><span>Category</span><select name="category"><option value="">All categories</option><?php foreach ($categories as $slug => $categoryItem): ?><option value="<?= dd_e($slug) ?>"<?= $category === $slug ? ' selected' : '' ?>><?= dd_e($categoryItem['label']) ?></option><?php endforeach; ?></select></label>
        <button type="submit"><span>Search products</span><b aria-hidden="true">↗</b></button>
      </form>
      <?php if ($error !== ''): ?><div class="product-search-notice" role="status"><?= dd_e($error) ?></div><?php endif; ?>
      <div class="product-catalog-meta"><span><?= $query !== '' ? 'Results for “' . dd_e($query) . '”' : 'Recently added products' ?></span><?php if ($isLiveSearch): ?><small>Live Amazon catalog results</small><?php endif; ?></div>
      <?php if ($displayProducts): ?>
        <div class="product-amazon-note">Some product links are affiliate links and may earn Zyofy a commission at no extra cost to you. As an Amazon Associate I earn from qualifying purchases. Product information and availability can change; check Amazon for current details.</div>
        <div class="retail-product-grid">
          <?php foreach ($displayProducts as $product): ?><?= dd_product_card($product) ?><?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="product-catalog-empty"><span>✳</span><h2><?= $query !== '' ? 'No products found yet.' : 'Your catalog starts here.' ?></h2><p><?php if ($query !== ''): ?>Try a broader search, or choose another active marketplace.<?php else: ?>Search the Amazon catalog above to find products. Search results need a valid Creators API connection configured in the admin workspace.<?php endif; ?></p></div>
      <?php endif; ?>
      <?php $pageCount = $isLiveSearch ? ($catalogTotal === 10 && $page < 10 ? $page + 1 : $page) : (int) ceil($catalogTotal / $perPage); ?>
      <?php if ($catalogTotal > 0 && $pageCount > 1): ?>
        <nav class="product-pagination" aria-label="Product pages">
          <?php if ($page > 1): ?><a href="<?= dd_e(dd_url('/products/?' . http_build_query(dd_storefront_product_query_args($query, $category, $page - 1, $marketplace)))) ?>">← Previous</a><?php endif; ?>
          <span>Page <?= number_format($page) ?><?= $isLiveSearch && $catalogTotal === 10 ? ' · more results available' : ' of ' . number_format($pageCount) ?></span>
          <?php if ($page < $pageCount): ?><a href="<?= dd_e(dd_url('/products/?' . http_build_query(dd_storefront_product_query_args($query, $category, $page + 1, $marketplace)))) ?>">Next →</a><?php endif; ?>
        </nav>
      <?php endif; ?>
      <?php if (!$isLiveSearch && $page * $perPage < $catalogTotal): ?>
        <button class="product-load-more" id="loadMoreProducts" type="button" data-offset="<?= (int) ($page * $perPage) ?>" data-url="<?= dd_e(dd_url('/load_more.php?' . http_build_query(['category' => $category, 'q' => $query, 'marketplace' => $marketplace]))) ?>">Load more products <span aria-hidden="true">↓</span></button>
      <?php endif; ?>
      <p class="store-disclosure product-disclosure"><strong>Affiliate disclosure.</strong> Some links may earn Zyofy a commission at no additional cost to you. Product availability and prices can change; always confirm current details on Amazon before buying.</p>
    </div>
  </section>
</main>
<script>
(() => {
  const button=document.getElementById('loadMoreProducts');
  const grid=document.querySelector('.retail-product-grid');
  if(!button||!grid)return;
  button.addEventListener('click',async()=>{
    button.disabled=true;button.dataset.label=button.textContent;button.textContent='Loading products…';
    try{
      const url=new URL(button.dataset.url,window.location.href);url.searchParams.set('offset',button.dataset.offset);
      const response=await fetch(url,{credentials:'same-origin'});
      if(!response.ok)throw new Error('Products could not be loaded.');
      const markup=await response.text();const template=document.createElement('template');template.innerHTML=markup;
      grid.append(template.content);button.dataset.offset=String(Number(button.dataset.offset)+24);
      if(response.headers.get('X-Has-More')!=='1')button.remove();
      else{button.disabled=false;button.textContent=button.dataset.label;}
    }catch(error){button.disabled=false;button.textContent='Try again';button.setAttribute('aria-label',error.message);}
  });
})();
</script>
<?php dd_render_footer(); ?>
