<?php
/**
 * Pickwell — shared brand <head> assets.
 *
 * Presentation only. Outputs the icon set, web manifest link and font stack so
 * every admin screen (dashboard, sign-in, first-run setup, sub-pages) declares
 * exactly one, consistent set of branding tags.
 *
 * Set $brandPrefix before including:
 *   ''    → the file lives at the app root (admin/index.php)
 *   '../' → the file lives one directory down (admin/partials/*)
 */
if (!isset($brandPrefix)) {
    $brandPrefix = '../';
}
$brandVersion = static function (string $relative) use ($brandPrefix): string {
    $path = __DIR__ . '/../../' . ltrim($brandPrefix, '/') . $relative;
    return is_file($path) ? (string) filemtime($path) : '1';
};
?>
<link rel="icon" href="<?= htmlspecialchars($brandPrefix, ENT_QUOTES, 'UTF-8') ?>favicon.ico" sizes="any">
<link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars($brandPrefix, ENT_QUOTES, 'UTF-8') ?>assets/brand/favicon.svg">
<link rel="icon" type="image/png" sizes="32x32" href="<?= htmlspecialchars($brandPrefix, ENT_QUOTES, 'UTF-8') ?>assets/brand/icon-32.png">
<link rel="apple-touch-icon" sizes="180x180" href="<?= htmlspecialchars($brandPrefix, ENT_QUOTES, 'UTF-8') ?>apple-touch-icon.png">
<link rel="manifest" href="<?= htmlspecialchars($brandPrefix, ENT_QUOTES, 'UTF-8') ?>manifest.webmanifest">
<meta name="theme-color" content="#0d3b32">
<meta name="application-name" content="Pickwell">
<meta name="apple-mobile-web-app-title" content="Pickwell">
<meta name="robots" content="noindex,nofollow,noarchive">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&family=IBM+Plex+Mono:wght@400;500&display=swap">
