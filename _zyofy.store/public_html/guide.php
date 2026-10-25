<?php
require_once __DIR__ . '/inc/site.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/guides-data.php';
require_once __DIR__ . '/db.php';

$user = dd_logged_in_user($pdo);
$slug = trim((string) ($_GET['slug'] ?? ''));
$guide = dd_guide_by_slug($slug);
if (!$guide) {
    http_response_code(404);
    dd_render_head('Guide not found', ['description' => 'The requested guide could not be found.', 'body_class' => 'storefront-page']);
    dd_render_storefront_header('guides', $user);
    ?>
    <main class="store-section"><div class="shell store-empty-state"><strong>Guide not found.</strong><p>Browse the guide library for another topic.</p><a class="store-btn store-btn-primary" href="<?= dd_e(dd_url('/guides/')) ?>">View all guides</a></div></main>
    <?php dd_render_footer(); exit;
}

$ogImage = dd_media_url($guide['image']);
dd_render_head($guide['title'], [
    'description' => $guide['excerpt'],
    'body_class' => 'storefront-page storefront-guide-article',
    'og_image' => $ogImage,
    'og_image_alt' => $guide['category'],
]);
dd_render_storefront_header('guides', $user);
?>
<main>
  <section class="guide-article-hero">
    <div class="shell">
      <nav class="store-breadcrumb" aria-label="Breadcrumb"><a href="<?= dd_e(dd_url('/')) ?>">Home</a><span>›</span><a href="<?= dd_e(dd_url('/guides/')) ?>">Guides</a><span>›</span><span><?= dd_e($guide['category']) ?></span></nav>
      <div class="guide-article-hero-grid">
        <div>
          <span class="storefront-kicker"><?= dd_e($guide['category']) ?></span>
          <h1><?= dd_e($guide['title']) ?></h1>
          <p><?= dd_e($guide['excerpt']) ?></p>
          <div class="guide-meta"><?= dd_e($guide['read_time']) ?> · Updated <?= dd_e($guide['updated']) ?></div>
        </div>
        <div class="guide-article-image" style="--image-position:<?= dd_e($guide['position'] ?? 'center') ?>"><img src="<?= dd_e($ogImage) ?>" alt="<?= dd_e($guide['title']) ?>" width="1600" height="900" decoding="async"></div>
      </div>
    </div>
  </section>

  <section class="store-section guide-article-section">
    <div class="shell guide-article-layout">
      <article class="guide-article-body">
        <div class="guide-note"><strong>Editorial note:</strong> this is original buying guidance, not a claim of hands-on testing. Use it as a checklist, then verify current specifications, price, availability and warranty details on the retailer's product page.</div>
        <?php foreach ($guide['sections'] as $section): ?>
          <section>
            <h2><?= dd_e($section[0]) ?></h2>
            <div class="guide-copy"><?= $section[1] ?></div>
          </section>
        <?php endforeach; ?>
      </article>
      <aside class="guide-article-aside">
        <div class="guide-aside-card">
          <span>Keep browsing</span>
          <h3>More buying guides</h3>
          <p>Practical checklists for home, tech, fitness, travel and everyday products.</p>
          <a class="store-btn store-btn-primary compact" href="<?= dd_e(dd_url('/guides/')) ?>">All guides →</a>
        </div>
      </aside>
    </div>
  </section>
</main>
<?php dd_render_footer(); ?>
