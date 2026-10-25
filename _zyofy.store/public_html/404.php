<?php
require_once __DIR__ . '/inc/site.php';
require_once __DIR__ . '/inc/layout.php';

http_response_code(404);
dd_render_head('Page not found', ['description' => 'The page you are looking for could not be found.', 'body_class' => 'storefront-page not-found-page']);
dd_render_storefront_header('');
?>
<main class="not-found-main"><div class="shell not-found-card"><span class="not-found-mark">404 <b>✳</b></span><span class="editorial-overline"><i></i> A SMALL DETOUR</span><h1>This page took<br><em>a different turn.</em></h1><p>The address may have changed or the page may no longer be here. Pick up with one of our practical buying guides.</p><div><a class="store-btn store-btn-primary" href="<?= dd_e(dd_url('/guides/')) ?>">Browse the guide library <span>↗</span></a><a class="not-found-home" href="<?= dd_e(dd_url('/')) ?>">Back to the front page</a></div></div></main>
<?php dd_render_footer(); ?>
