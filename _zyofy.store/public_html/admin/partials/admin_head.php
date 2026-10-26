<?php
/**
 * Shared chrome for the rebuilt admin screens.
 *
 * Deliberately the dashboard's own markup: the same .header / .header-inner bar, the
 * same round brand mark, the same green admin badge and outlined logout, then the
 * same .tabs strip under it. These screens should read as more tabs of the dashboard,
 * not as a separate application bolted on beside it.
 *
 * Include after the session and auth checks have run. Set two variables first:
 *   $pageTitle   — goes in <title>
 *   $currentPage — payout | upload | settings   (marks the active tab)
 */

if (!isset($pageTitle))   { $pageTitle = 'Pickwell Admin'; }
if (!isset($currentPage)) { $currentPage = ''; }

function adminOut($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$adminName = isset($_SESSION['admin_name']) && $_SESSION['admin_name'] !== ''
    ? $_SESSION['admin_name']
    : 'Admin';

/**
 * The stylesheet is versioned by its own last-modified time.
 *
 * Without this a browser — or the LiteSpeed cache sitting in front of the site —
 * happily keeps serving whatever it saw first, including a 404 from before the file
 * existed, and every screen renders with no styling at all.
 */
$appCssPath = __DIR__ . '/../assets/app.css';
$appCssVersion = is_file($appCssPath) ? (string) filemtime($appCssPath) : '1';
$premiumCssPath = __DIR__ . '/../assets/premium-refresh.css';
$premiumCssVersion = is_file($premiumCssPath) ? (string) filemtime($premiumCssPath) : '1';

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= adminOut($pageTitle) ?> — Pickwell Admin</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<?php $brandPrefix = '../'; require __DIR__ . '/brand_icons.php'; ?>
<link rel="stylesheet" href="assets/app.css?v=<?= adminOut($appCssVersion) ?>">
<link rel="stylesheet" href="assets/premium-refresh.css?v=<?= adminOut($premiumCssVersion) ?>">
<?php $opsCssPath = __DIR__ . '/../assets/pickwell-admin.css'; $opsCssVersion = is_file($opsCssPath) ? (string) filemtime($opsCssPath) : '1'; ?>
<link rel="stylesheet" href="assets/pickwell-admin.css?v=<?= adminOut($opsCssVersion) ?>">
</head>
<body class="pickwell-admin">
<header class="header">
  <div class="header-inner">
    <button class="mobile-nav-toggle" id="adminNavToggle" type="button" aria-label="Open admin navigation" aria-controls="adminSidebar" aria-expanded="false"><i class="fas fa-bars"></i></button>
    <span class="admin-topbar-title"><?= adminOut($pageTitle) ?></span>
    <div class="header-actions">
      <span class="admin-badge"><i class="fas fa-shield-halved"></i> <?= adminOut($adminName) ?></span>
      <a class="logout" href="logout.php"><i class="fas fa-right-from-bracket"></i> Logout</a>
    </div>
  </div>
</header>

<aside class="admin-workspace-nav admin-side-rail" id="adminSidebar" aria-label="Admin workspace navigation">
  <a class="rail-brand" href="index.php"><span class="brand-mark"><img src="../assets/brand/pickwell-mark-light.svg" alt=""></span><span><b>Pickwell</b><small>CONTROL CENTER</small></span></a>
  <span class="nav-group-label">PEOPLE</span>
  <a class="tab-button" href="index.php?tab=usersTab"><i class="fas fa-users"></i> Users &amp; tags</a>
  <a class="tab-button" href="index.php?tab=createTagTab"><i class="fas fa-tag"></i> Tracking tags</a>
  <span class="nav-group-label nav-group-spaced">PERFORMANCE</span>
  <a class="tab-button" href="index.php?tab=reportsTab"><i class="fas fa-chart-column"></i> Reports &amp; earnings</a>
  <a class="tab-button<?= $currentPage === 'upload' ? ' active' : '' ?>" href="upload-month.php"><i class="fas fa-arrow-up-from-bracket"></i> Import reports</a>
  <a class="tab-button<?= $currentPage === 'payout' ? ' active' : '' ?>" href="user-commission.php"><i class="fas fa-file-invoice-dollar"></i> Payouts</a>
  <span class="nav-group-label nav-group-spaced">CONFIGURATION</span>
  <a class="tab-button" href="index.php?tab=apiTab"><i class="fas fa-plug"></i> API connections</a>
  <a class="tab-button<?= $currentPage === 'settings' ? ' active' : '' ?>" href="exchange-rates.php"><i class="fas fa-earth-americas"></i> Markets &amp; rates</a>
  <span class="nav-group-label nav-group-spaced">SITE</span>
  <a class="tab-button" href="../products.php"><i class="fas fa-box-open"></i> View catalog</a>
  <a class="tab-button" href="../Home.php"><i class="fas fa-arrow-up-right-from-square"></i> Public homepage</a>
  <div class="nav-rail-foot"><span class="status-dot"></span><span><?= adminOut($adminName) ?><small>Administrator</small></span><a href="logout.php" aria-label="Logout"><i class="fas fa-right-from-bracket"></i></a></div>
</aside>
<button class="admin-nav-backdrop" id="adminNavBackdrop" type="button" aria-label="Close admin navigation"></button>

<script>
(function () {
  var toggle = document.getElementById('adminNavToggle');
  var rail = document.getElementById('adminSidebar');
  var backdrop = document.getElementById('adminNavBackdrop');
  if (!toggle || !rail || !backdrop) return;
  function closeNav() {
    var wasOpen = document.body.classList.contains('admin-nav-open');
    document.body.classList.remove('admin-nav-open');
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-label', 'Open admin navigation');
    if (wasOpen && window.matchMedia('(max-width: 700px)').matches) toggle.focus();
  }
  toggle.addEventListener('click', function () {
    var open = document.body.classList.toggle('admin-nav-open');
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    toggle.setAttribute('aria-label', open ? 'Close admin navigation' : 'Open admin navigation');
    if (open && window.matchMedia('(max-width: 700px)').matches) {
      var firstNavItem = rail.querySelector('.tab-button');
      if (firstNavItem) firstNavItem.focus();
    }
  });
  backdrop.addEventListener('click', closeNav);
  rail.addEventListener('click', function (event) {
    if (event.target.closest('.tab-button')) closeNav();
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeNav();
  });
})();
</script>

<main class="page">
  <div class="admin-welcome admin-subpage-welcome">
    <div><span class="admin-overline">PICKWELL <i>/</i> WORKSPACE</span><h1><?= adminOut($pageTitle) ?></h1><p>Manage contributors, reports and marketplace settings from one secure workspace.</p></div>
    <div class="admin-welcome-badge"><span class="status-dot"></span> Workspace protected</div>
  </div>
