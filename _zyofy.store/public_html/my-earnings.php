<?php
/**
 * My Earnings — Contributor Financial Overview
 *
 * Built from the same dd_payout_month() figures the owner's payout sheet uses.
 * Zero business logic changes. Preserves CSV export, rate freezing, and calculations.
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/inc/site.php';
require_once __DIR__ . '/inc/layout.php';
dd_require_user_login(dd_url('/login/'));
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin/lib/payout.php';

// Earnings must reflect the most recent imported report on every request.
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function ddOut($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$hasDummyCommission = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'dummy_commission_percent'")->fetchColumn() > 0;
$userStatement = $pdo->prepare('SELECT id, name, email' . ($hasDummyCommission ? ', dummy_commission_percent' : ', 70.00 AS dummy_commission_percent') . ' FROM users WHERE id = :id LIMIT 1');
$userStatement->execute(array(':id' => (int) $_SESSION['user_id']));
$currentUser = $userStatement->fetch(PDO::FETCH_ASSOC);

if (!$currentUser) {
    dd_logout_user();
    header('Location: ' . dd_url('/login/'));
    exit;
}

$_SESSION['user_name']  = $currentUser['name'];
$_SESSION['user_email'] = $currentUser['email'];

$setupError    = '';
$reportMonths  = array();
$selectedMonth = '';
$me            = null;
$assignedTags  = array();

try {
    $hasTagShare = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_tags'
          AND COLUMN_NAME = 'share_percent'")->fetchColumn() > 0;

    $tagStatement = $pdo->prepare(
        'SELECT tracking_id, marketplace' . ($hasTagShare ? ', share_percent' : '') . "
         FROM user_tags
         WHERE user_email = :email AND tracking_id IS NOT NULL AND tracking_id <> ''
         ORDER BY marketplace, tracking_id"
    );
    $tagStatement->execute(array(':email' => $currentUser['email']));
    $myTags = $tagStatement->fetchAll(PDO::FETCH_ASSOC);
    $assignedTags = array_values(array_unique(array_map(function ($row) {
        return (string) $row['tracking_id'];
    }, $myTags)));

    $reportMonths = $pdo->query(
        "SELECT DISTINCT report_month FROM tracking_report_monthly
         WHERE report_month IS NOT NULL AND report_month <> '' ORDER BY report_month DESC"
    )->fetchAll(PDO::FETCH_COLUMN);

    $requested = isset($_GET['month']) ? trim((string) $_GET['month']) : '';
    if (preg_match('/^\d{4}-\d{2}$/', $requested) && in_array($requested, $reportMonths, true)) {
        $selectedMonth = $requested;
    } elseif (!empty($reportMonths)) {
        $selectedMonth = (string) $reportMonths[0];
    }

    if ($selectedMonth !== '') {
        $payout = dd_payout_month($pdo, $selectedMonth);
        $key = strtolower(trim((string) $currentUser['email']));
        if (isset($payout['contributors'][$key])) {
            $me = $payout['contributors'][$key];
        }
    }
} catch (Throwable $error) {
    error_log('my-earnings.php: ' . $error->getMessage());
    $setupError = 'Your earnings are not available right now. Please try again shortly, or contact the administrator.';
}

$monthLabel = $selectedMonth !== '' ? date('F Y', strtotime($selectedMonth . '-01')) : 'this month';
$dummyCommission = isset($currentUser['dummy_commission_percent']) ? (float) $currentUser['dummy_commission_percent'] : 70.0;
$dummyCommissionText = dd_pct($dummyCommission, 2);

$appCssPath = __DIR__ . '/admin/assets/app.css';
$appCssVersion = is_file($appCssPath) ? (string) filemtime($appCssPath) : '20260904';
$siteCssPath = __DIR__ . '/assets/css/site.css';
$siteCssVersion = is_file($siteCssPath) ? (string) filemtime($siteCssPath) : '20260905';

/* ---------------- CSV of my own earnings ---------------- */
if ($me && isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="my-earnings-' . $selectedMonth . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, array('Country', 'Currency', 'Tracking IDs', 'Clicks', 'Shipped',
                        'Earned in that currency', 'Commission rate', 'Rate to USD', 'Payable USD'));
    foreach ($me['countries'] as $country) {
        fputcsv($out, array(
            dd_country_label($pdo, $country['marketplace']), $country['currency'], count($country['tags']),
            $country['clicks'], $country['items_shipped'],
            number_format($country['earnings'], 2, '.', ''),
            number_format($dummyCommission, 2, '.', ''),
            dd_rate($country['rate']),
            number_format($country['payable_usd'], 2, '.', ''),
        ));
    }
    fputcsv($out, array('TOTAL', '', '', '', '', '', '', '', number_format($me['payable_usd'], 2, '.', '')));
    fclose($out);
    exit;
}

$shareText = ddOut($dummyCommissionText) . '%';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>My Earnings · Pickwell</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;850;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<!-- Absolute paths so clean nested URLs never break stylesheet loading -->
<link rel="stylesheet" href="<?= dd_e(dd_url('/assets/css/site.css?v=' . $siteCssVersion)) ?>">
<link rel="icon" href="<?= dd_e(dd_url('/favicon.ico')) ?>" sizes="any">
<link rel="icon" type="image/svg+xml" href="<?= dd_e(dd_url('/assets/brand/favicon.svg')) ?>">
<link rel="apple-touch-icon" sizes="180x180" href="<?= dd_e(dd_url('/apple-touch-icon.png')) ?>">
<link rel="manifest" href="<?= dd_e(dd_url('/manifest.webmanifest')) ?>">
<meta name="theme-color" content="#0d3b32">
<link rel="stylesheet" href="<?= dd_e(dd_url('/assets/css/storefront.css?v=' . (is_file(__DIR__ . '/assets/css/storefront.css') ? filemtime(__DIR__ . '/assets/css/storefront.css') : '20261007'))) ?>">
<link rel="stylesheet" href="<?= dd_e(dd_url('/admin/assets/app.css?v=' . $appCssVersion)) ?>">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600&family=Plus+Jakarta+Sans:wght@500;600;700;800&family=IBM+Plex+Mono:wght@400;500&display=swap">
<link rel="stylesheet" href="<?= dd_e(dd_url('/assets/css/pickwell.css?v=' . (is_file(__DIR__ . '/assets/css/pickwell.css') ? filemtime(__DIR__ . '/assets/css/pickwell.css') : '1'))) ?>">
<style>
  .fintech-header {
    position: sticky;
    top: 0;
    z-index: 1000;
    background: rgba(10, 15, 13, 0.92);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    box-shadow: 0 4px 24px rgba(0, 0, 0, 0.25);
  }
  .fintech-header-inner {
    max-width: 1300px;
    margin: 0 auto;
    padding: 0 24px;
    height: 74px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
  }
  .contributor-nav {
    display: flex;
    align-items: center;
    gap: 6px;
    background: rgba(255, 255, 255, 0.04);
    padding: 4px;
    border-radius: 9999px;
    border: 1px solid rgba(255, 255, 255, 0.06);
  }
  .contributor-nav a {
    color: #c4d4ca;
    text-decoration: none;
    font-weight: 650;
    font-size: 0.9rem;
    padding: 8px 16px;
    border-radius: 9999px;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    transition: all 0.2s ease;
    white-space: nowrap;
  }
  .contributor-nav a:hover {
    color: #ffffff;
    background: rgba(255, 255, 255, 0.08);
  }
  .contributor-nav a.on {
    background: rgba(16, 185, 129, 0.24);
    border: 1px solid rgba(52, 211, 153, 0.35);
    color: #ffffff;
    font-weight: 750;
  }
  .fintech-user-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid rgba(255, 255, 255, 0.1);
    color: #d1dfd6;
    padding: 6px 14px;
    border-radius: 9999px;
    font-size: 0.84rem;
    font-weight: 600;
  }
  .fintech-user-badge i {
    color: #34d399;
  }
  .hero-payout {
    background:
      radial-gradient(ellipse 70% 50% at 80% 20%, rgba(220, 169, 79, 0.2), transparent 60%),
      linear-gradient(140deg, #0d3b32 0%, #11493e 60%, #082a23 100%);
    color: #ffffff;
    border-radius: 28px;
    padding: 40px;
    border: 1px solid rgba(255, 255, 255, 0.12);
    box-shadow:
      inset 0 1px 0 rgba(255, 255, 255, 0.18),
      0 20px 48px -8px rgba(0, 0, 0, 0.45);
    margin-top: 24px;
  }
  .hero-payout .cap {
    font-size: 0.78rem;
    font-weight: 850;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: #dca94f;
  }
  .hero-payout .big-num {
    font-size: clamp(2.8rem, 6vw, 4.4rem);
    font-weight: 900;
    letter-spacing: -0.04em;
    line-height: 1.04;
    margin: 14px 0 10px;
    color: #ffffff;
    font-variant-numeric: tabular-nums;
  }
  .hero-payout .big-num span {
    font-size: 1.1rem;
    font-weight: 700;
    color: rgba(220, 169, 79, .8);
    margin-left: 10px;
    letter-spacing: 0;
  }
  .hero-payout .msg {
    color: #c7d8cd;
    font-size: 0.98rem;
    max-width: 680px;
    line-height: 1.6;
  }
  .hero-payout-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    border-top: 1px solid rgba(255, 255, 255, 0.12);
    margin-top: 32px;
    padding-top: 26px;
  }
  .stat-tile .l {
    font-size: 0.74rem;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #8da495;
    margin-bottom: 6px;
  }
  .stat-tile .v {
    font-size: 1.65rem;
    font-weight: 850;
    color: #ffffff;
    font-variant-numeric: tabular-nums;
  }
  .fintech-header-actions {
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .earnings-menu-toggle {
    display: none;
    width: 46px;
    height: 46px;
    flex: 0 0 46px;
    border-radius: 14px;
    border: 1px solid rgba(255,255,255,.14);
    background: rgba(255,255,255,.06);
    align-items: center;
    justify-content: center;
    flex-direction: column;
    gap: 5px;
    cursor: pointer;
    padding: 0;
  }
  .earnings-menu-toggle span {
    display: block;
    width: 22px;
    height: 2px;
    border-radius: 999px;
    background: #fff;
    transition: transform .2s ease, opacity .2s ease;
  }
  .earnings-menu-toggle.is-open span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
  .earnings-menu-toggle.is-open span:nth-child(2) { opacity: 0; }
  .earnings-menu-toggle.is-open span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }
  .earnings-mobile-menu { display: none; }

  @media (max-width: 900px) {
    .fintech-header-inner {
      height: 64px;
      padding: 0 18px;
      gap: 12px;
    }
    .fintech-header .brand { gap: 10px; min-width: 0; }
    .fintech-header .brand-mark {
      width: 36px; height: 36px; border-radius: 12px; font-size: 1.08rem;
    }
    .fintech-header .brand-name { font-size: 1.08rem; }
    .contributor-nav, .fintech-header-actions { display: none; }
    .earnings-menu-toggle { display: flex; margin-left: auto; }
    .earnings-mobile-menu {
      position: absolute;
      top: 100%; left: 0; right: 0; z-index: 1001;
      padding: 12px 18px 18px;
      background: rgba(10,15,13,.985);
      border-bottom: 1px solid rgba(255,255,255,.08);
      box-shadow: 0 18px 34px rgba(0,0,0,.32);
    }
    .earnings-mobile-menu.is-open { display: grid; gap: 6px; }
    .earnings-mobile-menu a {
      display: flex; align-items: center; gap: 11px; min-height: 48px;
      padding: 11px 14px; border-radius: 12px; color: #d9e6de;
      text-decoration: none; font-weight: 700; font-size: .98rem;
    }
    .earnings-mobile-menu a:hover, .earnings-mobile-menu a.on {
      color: #fff; background: rgba(16,185,129,.18);
    }
    .earnings-mobile-menu a.logout {
      margin-top: 4px; color: #b9c9bf; border-top: 1px solid rgba(255,255,255,.08);
      border-radius: 0; padding-top: 15px;
    }
    .hero-payout-stats { grid-template-columns: repeat(2, 1fr); gap: 18px; }
  }
  @media (max-width: 600px) {
    .hero-payout { padding: 28px 20px; }
    .hero-payout-stats { grid-template-columns: 1fr; }
    .fintech-user-badge { display: none; }
  }
</style>
<link rel="stylesheet" href="<?= dd_e(dd_url('/assets/css/pickwell.css?v=' . (is_file(__DIR__ . '/assets/css/pickwell.css') ? filemtime(__DIR__ . '/assets/css/pickwell.css') : '2'))) ?>">
</head>
<body class="storefront-page contributor-earnings-page contributor-page" style="background:var(--bg)">

<?php dd_render_storefront_header('earnings', $currentUser); ?>

<main class="page" style="max-width:1300px;margin:36px auto;padding:0 24px 80px">
  <div class="page-title">
    <span class="eyebrow" style="margin-bottom:8px">Financial Statement</span>
    <h1 style="font-size:2.2rem;font-weight:850;letter-spacing:-0.03em;color:var(--ink)">
      <i class="fas fa-chart-pie" style="color:var(--green)"></i> My Earnings
    </h1>
    <p class="lede" style="color:var(--muted);font-size:1rem;margin-top:6px">
      Monthly earnings data attributed to your assigned tracking IDs.
    </p>
  </div>

  <?php if ($setupError !== ''): ?>
    <div class="notice notice-error" style="margin-top:24px"><i class="fas fa-triangle-exclamation"></i> <?= ddOut($setupError) ?></div>

  <?php elseif (empty($assignedTags)): ?>
    <div class="notice notice-warn" style="margin-top:24px">
      <strong>No tracking ID assigned yet:</strong> Your account does not have an active tracking tag assigned. Once the site administrator assigns one, your earnings breakdown will appear here following report imports.
    </div>

  <?php elseif ($selectedMonth === ''): ?>
    <div class="notice notice-info" style="margin-top:24px">
      <strong>No monthly reports uploaded yet:</strong> You hold <?= count($assignedTags) ?> tracking tag<?= count($assignedTags) === 1 ? '' : 's' ?>. As soon as the first report is imported by the administrator, your attributed earnings will render here.
    </div>

  <?php else: ?>

    <!-- Controls Row: Month Selector, Download CSV, Print -->
    <div class="row" style="margin-top:28px;align-items:center;background:#ffffff;padding:16px 20px;border-radius:18px;border:1px solid var(--line);box-shadow:var(--shadow-sm)">
      <div class="fld" style="margin:0">
        <label for="month" style="font-weight:800;color:var(--ink-2);margin-bottom:6px;display:block;font-size:0.82rem">Select Reporting Month</label>
        <form method="get" action="<?= dd_e(dd_url('/my-earnings/')) ?>" id="monthForm">
          <select id="month" name="month" onchange="document.getElementById('monthForm').submit()" style="font-weight:700;padding:10px 16px;border-radius:12px;border:1px solid var(--line-strong)">
            <?php foreach ($reportMonths as $month): ?>
              <option value="<?= ddOut($month) ?>"<?= $selectedMonth === $month ? ' selected' : '' ?>>
                <?= ddOut(date('F Y', strtotime($month . '-01'))) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>
      <div class="spacer"></div>
      <?php if ($me && !empty($me['countries'])): ?>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <a class="btn btn-outline btn-sm" href="<?= dd_e(dd_url('/my-earnings/?' . http_build_query(array('month' => $selectedMonth, 'export' => 'csv')))) ?>">
            <i class="fas fa-file-csv"></i> Download CSV
          </a>
          <button class="btn btn-dark btn-sm" onclick="window.print()">
            <i class="fas fa-print"></i> Print Statement
          </button>
        </div>
      <?php endif; ?>
    </div>

    <!-- 3D Dimensional Hero Payout Panel -->
    <div class="hero-payout">
      <div class="cap">Payable Amount · <?= ddOut($monthLabel) ?></div>
      <div class="big-num"><?= dd_usd($me ? $me['payable_usd'] : 0) ?><span>USD</span></div>
      <div class="msg">
        <?php if ($me && $me['payable_usd'] > 0): ?>
          Aggregated across <?= count($me['countries']) ?> active <?= count($me['countries']) === 1 ? 'country' : 'countries' ?> using exchange rates frozen at month close and per-tag commission terms.
        <?php else: ?>
          No qualified sales were attributed to your tracking IDs in this reporting cycle.
        <?php endif; ?>
      </div>

      <?php if ($me && !empty($me['payment'])): ?>
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:18px">
          <span class="pill pill-green">
            <i class="fas fa-check-circle"></i> Paid on <?= ddOut(date('j F Y', strtotime($me['payment']['paid_on']))) ?>
          </span>
          <?php if ($me['payment']['drifted']): ?>
            <span class="pill pill-amber">
              <i class="fas fa-triangle-exclamation"></i> <?= dd_usd($me['payment']['paid_amount']) ?> disbursed; figures re-verified since
            </span>
          <?php endif; ?>
        </div>
      <?php elseif ($me && $me['payable_usd'] > 0): ?>
        <div style="margin-top:18px">
          <span class="pill pill-amber">
            <i class="fas fa-clock"></i> Payment pending payout disbursement
          </span>
        </div>
      <?php endif; ?>

      <!-- 4 High-Impact Metric Tiles -->
      <div class="hero-payout-stats">
        <div class="stat-tile">
          <div class="l">Clicks Generated</div>
          <div class="v"><?= number_format($me ? (int) $me['clicks'] : 0) ?></div>
        </div>
        <div class="stat-tile">
          <div class="l">Items Ordered</div>
          <div class="v"><?= number_format($me ? (int) $me['items_ordered'] : 0) ?></div>
        </div>
        <div class="stat-tile">
          <div class="l">Items Returned</div>
          <div class="v"><?= number_format($me ? (int) $me['items_returned'] : 0) ?></div>
        </div>
        <div class="stat-tile">
          <div class="l">Commission rate</div>
          <div class="v"><?= $shareText ?></div>
        </div>
      </div>
    </div>

    <!-- Country / Currency Breakdown Table Card -->
    <section class="card" style="background:#ffffff;border-radius:26px;padding:32px;border:1px solid var(--line);box-shadow:var(--shadow-3d-card);margin-top:28px">
      <div class="card-header" style="margin-bottom:16px">
        <div>
          <h2 style="font-size:1.45rem;font-weight:850;letter-spacing:-0.02em;color:var(--ink)">
            <i class="fas fa-globe" style="color:var(--green)"></i> Country &amp; Currency Breakdown
          </h2>
          <p class="sub" style="color:var(--muted);font-size:0.9rem;margin-top:4px">
            Each marketplace settles in local currency and converts to USD using the exchange rate locked at month close.
          </p>
        </div>
      </div>

      <div class="tw" style="margin-top:16px;border-radius:18px;border:1px solid var(--line);overflow-x:auto">
        <table class="earnings-country-table" style="width:100%;border-collapse:collapse;min-width:700px">
          <thead>
            <tr style="background:var(--surface-2)">
              <th style="padding:14px 18px;font-size:0.78rem;font-weight:800;text-transform:uppercase;letter-spacing:0.06em;color:var(--muted)">Country</th>
              <th style="padding:14px 18px;font-size:0.78rem;font-weight:800;text-transform:uppercase;letter-spacing:0.06em;color:var(--muted)">Currency</th>
              <th class="r" style="padding:14px 18px;font-size:0.78rem;font-weight:800;text-transform:uppercase;letter-spacing:0.06em;color:var(--muted)">Earned in Currency</th>
              <th class="r" style="padding:14px 18px;font-size:0.78rem;font-weight:800;text-transform:uppercase;letter-spacing:0.06em;color:var(--muted)">Commission rate</th>
              <th class="r" style="padding:14px 18px;font-size:0.78rem;font-weight:800;text-transform:uppercase;letter-spacing:0.06em;color:var(--muted)">Rate to USD</th>
              <th class="r" style="padding:14px 18px;font-size:0.78rem;font-weight:800;text-transform:uppercase;letter-spacing:0.06em;color:var(--muted)">Payable (USD)</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$me || empty($me['countries'])): ?>
            <tr>
              <td colspan="6" class="dim" style="text-align:center;padding:36px;color:var(--muted)">
                No sales or commissions reported against your tracking tags for this month.
              </td>
            </tr>
          <?php endif; ?>
          <?php if ($me): foreach ($me['countries'] as $country): ?>
            <tr style="border-top:1px solid var(--line)">
              <td data-label="Country" class="name" style="padding:16px 18px;font-weight:800;color:var(--ink)">
                <?= ddOut(dd_country_label($pdo, $country['marketplace'])) ?>
              </td>
              <td data-label="Currency" style="padding:16px 18px;font-weight:700;color:var(--ink-2)">
                <span class="pill pill-slate"><?= ddOut($country['currency']) ?></span>
              </td>
              <td data-label="Earned" class="r num" style="padding:16px 18px;font-weight:700;color:var(--ink-2)">
                <?= ddOut(dd_money($country['currency'], $country['earnings'])) ?>
              </td>
              <td data-label="Your share" class="r num" style="padding:16px 18px;font-weight:700;color:var(--green-deep)">
                <?= ddOut($dummyCommissionText) ?>%
              </td>
              <td data-label="Rate to USD" class="r num" style="padding:16px 18px;color:var(--muted)">
                <?= ddOut(dd_rate($country['rate'])) ?>
              </td>
              <td data-label="Payable (USD)" class="r num" style="padding:16px 18px;font-weight:850;color:var(--green-deep);font-size:1.05rem">
                <?= dd_usd($country['payable_usd']) ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
          <tfoot>
            <tr style="background:var(--surface-2);border-top:2px solid var(--line)">
              <td data-label="Summary" style="padding:16px 18px;font-weight:850;color:var(--ink)">Total</td>
              <td></td>
              <td class="r dim">&mdash;</td>
              <td class="r dim">&mdash;</td>
              <td class="r dim">&mdash;</td>
              <td data-label="Total payable" class="r num" style="padding:16px 18px;font-weight:900;color:var(--green-deep);font-size:1.15rem">
                <?= dd_usd($me ? $me['payable_usd'] : 0) ?>
              </td>
            </tr>
          </tfoot>
        </table>
      </div>

      <p class="sub" style="margin-top:20px;font-size:0.86rem;color:var(--muted);line-height:1.6">
        Formula: <span style="font-family:monospace;background:var(--surface-2);padding:2px 6px;border-radius:4px;color:var(--ink)">Earned Currency &times; Rate &times; Share = Payable (USD)</span>. Exchange rates are permanently locked upon import to ensure payout consistency.
      </p>

      <?php if ($me && $me['unpriced'] > 0): ?>
        <div class="notice notice-warn" style="margin-top:20px">
          <strong>Notice:</strong> Some transactions were imported prior to complete currency resolution. The administrator has been notified to re-index those records.
        </div>
      <?php endif; ?>
    </section>

    <!-- Tracking IDs Detail Card -->
    <?php if ($me && !empty($me['countries'])): ?>
    <section class="card" style="background:#ffffff;border-radius:26px;padding:32px;border:1px solid var(--line);box-shadow:var(--shadow-3d-card);margin-top:28px">
      <div class="card-header" style="margin-bottom:16px">
        <div>
          <h2 style="font-size:1.45rem;font-weight:850;letter-spacing:-0.02em;color:var(--ink)">
            <i class="fas fa-tags" style="color:var(--green)"></i> Your Assigned Tracking IDs
          </h2>
          <p class="sub" style="color:var(--muted);font-size:0.9rem;margin-top:4px">
            Breakdown by specific Amazon Associate tracking tags and individual commission rates.
          </p>
        </div>
      </div>
      <div class="tw" style="margin-top:16px;border-radius:18px;border:1px solid var(--line);overflow-x:auto">
        <table class="earnings-tracking-table" style="width:100%;border-collapse:collapse;min-width:700px">
          <thead>
            <tr style="background:var(--surface-2)">
              <th style="padding:14px 18px;font-size:0.78rem;font-weight:800;text-transform:uppercase;letter-spacing:0.06em;color:var(--muted)">Tracking ID</th>
              <th style="padding:14px 18px;font-size:0.78rem;font-weight:800;text-transform:uppercase;letter-spacing:0.06em;color:var(--muted)">Marketplace</th>
              <th class="r" style="padding:14px 18px;font-size:0.78rem;font-weight:800;text-transform:uppercase;letter-spacing:0.06em;color:var(--muted)">Clicks</th>
              <th class="r" style="padding:14px 18px;font-size:0.78rem;font-weight:800;text-transform:uppercase;letter-spacing:0.06em;color:var(--muted)">Shipped</th>
              <th class="r" style="padding:14px 18px;font-size:0.78rem;font-weight:800;text-transform:uppercase;letter-spacing:0.06em;color:var(--muted)">Earned</th>
              <th class="r" style="padding:14px 18px;font-size:0.78rem;font-weight:800;text-transform:uppercase;letter-spacing:0.06em;color:var(--muted)">Commission</th>
              <th class="r" style="padding:14px 18px;font-size:0.78rem;font-weight:800;text-transform:uppercase;letter-spacing:0.06em;color:var(--muted)">Payable (USD)</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($me['countries'] as $country): ?>
            <?php foreach ($country['tags'] as $tag): ?>
              <tr style="border-top:1px solid var(--line)">
                <td data-label="Tracking ID" class="name" style="padding:14px 18px;font-weight:800;color:var(--green-deep);font-family:monospace">
                  <?= ddOut($tag['tracking_id']) ?>
                </td>
                <td data-label="Marketplace" style="padding:14px 18px;color:var(--ink-2);font-weight:650">
                  <?= ddOut(dd_country_label($pdo, $country['marketplace'])) ?>
                </td>
                <td data-label="Clicks" class="r num" style="padding:14px 18px;color:var(--ink-2)">
                  <?= number_format((int) $tag['clicks']) ?>
                </td>
                <td data-label="Shipped" class="r num" style="padding:14px 18px;color:var(--ink-2)">
                  <?= number_format((int) $tag['items_shipped']) ?>
                </td>
                <td data-label="Earned" class="r num" style="padding:14px 18px;font-weight:700;color:var(--ink-2)">
                  <?= ddOut(dd_money($country['currency'], $tag['earnings'])) ?>
                </td>
                <td data-label="Commission" class="r num" style="padding:14px 18px;font-weight:750;color:var(--green-deep)">
                  <?= ddOut($dummyCommissionText) ?>%
                </td>
                <td data-label="Payable (USD)" class="r num" style="padding:14px 18px;font-weight:850;color:var(--green-deep)">
                  <?= dd_usd($tag['payable_usd']) ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
    <?php endif; ?>

    <!-- Glossary / Help Accordion -->
    <details class="card" style="background:#ffffff;border-radius:24px;border:1px solid var(--line);box-shadow:var(--shadow-sm);margin-top:28px;padding:0 32px">
      <summary style="cursor:pointer;font-weight:800;font-size:1.05rem;padding:24px 0;list-style:none;display:flex;justify-content:space-between;align-items:center;color:var(--ink)">
        <span><i class="fas fa-circle-question" style="color:var(--green);margin-right:8px"></i> Financial Terms &amp; Definitions</span>
        <span style="color:var(--muted);font-size:0.88rem">Click to expand</span>
      </summary>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:20px;padding:0 0 30px;border-top:1px solid var(--line);margin-top:8px;padding-top:24px">
        <div><strong style="color:var(--ink)">Clicks:</strong><br><span style="color:var(--muted);font-size:0.88rem">Total customer clicks routed through your attributed Pickwell links.</span></div>
        <div><strong style="color:var(--ink)">Items Ordered:</strong><br><span style="color:var(--muted);font-size:0.88rem">Products added to an Amazon cart following an attributed referral.</span></div>
        <div><strong style="color:var(--ink)">Items Shipped:</strong><br><span style="color:var(--muted);font-size:0.88rem">Orders physically fulfilled by Amazon. Commissions are payable on shipped volume.</span></div>
        <div><strong style="color:var(--ink)">Items Returned:</strong><br><span style="color:var(--muted);font-size:0.88rem">Orders returned by buyers, with associated commission retracted.</span></div>
        <div><strong style="color:var(--ink)">Currency Rate:</strong><br><span style="color:var(--muted);font-size:0.88rem">Fixed USD exchange rate locked at import to prevent retroactive recalculation.</span></div>
        <div><strong style="color:var(--ink)">Payable in USD:</strong><br><span style="color:var(--muted);font-size:0.88rem">Final net compensation disbursed for the reporting cycle.</span></div>
      </div>
    </details>

  <?php endif; ?>
</main>

<?php dd_render_footer(); ?>
