<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

function adminEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/* Drops trailing zeros only after a decimal point, so 90.00 reads as 90 while 90 stays
   90 — trimming unconditionally would print it as 9. Same rule as dd_pct() in the
   payout library; the "%" sign is added by the caller. */
function ddTrimPercent($value, $decimals = 2)
{
    $text = number_format((float) $value, $decimals, '.', '');
    return strpos($text, '.') === false ? $text : rtrim(rtrim($text, '0'), '.');
}

if (!isset($_SESSION['report_csrf_token'])) {
    $_SESSION['report_csrf_token'] = bin2hex(random_bytes(32));
}

$adminEmail = isset($_SESSION['admin_email']) ? (string) $_SESSION['admin_email'] : '';
if ($adminEmail === '' && isset($_SESSION['admin_user_id'])) {
    $adminStatement = $pdo->prepare('SELECT email FROM users WHERE id = :id LIMIT 1');
    $adminStatement->execute(array(':id' => (int) $_SESSION['admin_user_id']));
    $adminEmail = (string) $adminStatement->fetchColumn();
    $_SESSION['admin_email'] = $adminEmail;
}

// share_percent is each contributors own commission rate (migration 007).
$hasUserShare = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'share_percent'")->fetchColumn() > 0;
$hasDummyCommission = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'dummy_commission_percent'")->fetchColumn() > 0;
$dummyCommissionSelect = $hasDummyCommission ? ', dummy_commission_percent' : ', 70.00 AS dummy_commission_percent';
$userStatement = $pdo->query($hasUserShare
    ? 'SELECT id, name, email, created_at, share_percent' . $dummyCommissionSelect . ' FROM users ORDER BY id DESC'
    : 'SELECT id, name, email, created_at' . $dummyCommissionSelect . ' FROM users ORDER BY id DESC');
$users = $userStatement->fetchAll(PDO::FETCH_ASSOC);

/* Commission lives on the assignment (migration 008) and is set on the Create TAG
   screen. The Users & Tags tab only reports the combined figure. */
$hasTagShare = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_tags' AND COLUMN_NAME = 'share_percent'")->fetchColumn() > 0;
$tagStatement = $pdo->query($hasTagShare
    ? 'SELECT id, user_email, tracking_id, marketplace, share_percent FROM user_tags ORDER BY user_email, marketplace'
    : 'SELECT id, user_email, tracking_id, marketplace FROM user_tags ORDER BY user_email, marketplace');
$allTags = $tagStatement->fetchAll(PDO::FETCH_ASSOC);

/* What a brand-new assignment starts on — held as the column default so the form, and
   anything else that inserts a row, agree without having to be told. */
$defaultTagShare = 70.00;
if ($hasTagShare) {
    try {
        $value = $pdo->query("SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_tags' AND COLUMN_NAME = 'share_percent'")->fetchColumn();
        if ($value !== null && $value !== false) {
            $defaultTagShare = (float) trim((string) $value, "'");
        }
    } catch (Throwable $error) {
        error_log('index.php default tag share: ' . $error->getMessage());
    }
}
$tagsByUser = array();
foreach ($allTags as $tag) {
    if (!isset($tagsByUser[$tag['user_email']])) $tagsByUser[$tag['user_email']] = array();
    $tagsByUser[$tag['user_email']][] = $tag;
}

$apiStatement = $pdo->query('SELECT * FROM amazon_api_settings ORDER BY id DESC');
$apiCredentials = $apiStatement->fetchAll(PDO::FETCH_ASSOC);

// Product catalog management uses the same cache as storefront/search and link generation.
$adminProducts = array();
$manualAdminProducts = array();
$manualProductSchemaReady = false;
$productSchemaReady = false;
$productSchemaMessage = '';
try {
    $productColumns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'")->fetchAll(PDO::FETCH_COLUMN);
    $productSchemaReady = in_array('id', $productColumns, true)
        && in_array('asin', $productColumns, true)
        && in_array('marketplace', $productColumns, true);
    if ($productSchemaReady) {
        $productOrder = in_array('created_at', $productColumns, true) ? 'p.created_at DESC, p.id DESC' : 'p.id DESC';
        $adminProducts = $pdo->query("SELECT p.id, p.title, p.asin, p.image, p.price, p.marketplace, p.description, " . (in_array('created_at', $productColumns, true) ? 'p.created_at' : 'NULL AS created_at') . ",
            p.affiliate_link" . (in_array('price_refreshed_at', $productColumns, true) ? ', p.price_refreshed_at' : ', NULL AS price_refreshed_at') . ",
            (SELECT COUNT(*) FROM product_shares ps WHERE ps.product_id = p.id) AS share_count
            FROM products p ORDER BY $productOrder")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $productSchemaMessage = 'The products table is missing required catalog columns.';
    }
} catch (Throwable $error) {
    error_log('admin product catalog load: ' . $error->getMessage());
    $productSchemaMessage = 'Product catalog could not be loaded. Confirm the products and product_shares tables are initialized.';
}
try {
    $manualProductSchemaReady = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'manual_product_posts'")->fetchColumn() > 0;
    if ($manualProductSchemaReady) {
        $manualAdminProducts = $pdo->query('SELECT id, slug, title, image_url, description, marketplace, affiliate_url, created_at FROM manual_product_posts ORDER BY created_at DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $error) {
    error_log('admin manual product load failed: ' . get_class($error));
}
$adminProductRows = [];
foreach ($adminProducts as $catalogProduct) {
    $catalogProduct['product_source'] = 'catalog';
    $adminProductRows[] = $catalogProduct;
}
foreach ($manualAdminProducts as $manualProductRow) {
    $adminProductRows[] = [
        'id' => (int) $manualProductRow['id'], 'manual_slug' => $manualProductRow['slug'],
        'product_source' => 'manual', 'title' => $manualProductRow['title'],
        'asin' => '', 'image' => $manualProductRow['image_url'], 'price' => null,
        'marketplace' => $manualProductRow['marketplace'], 'description' => $manualProductRow['description'],
        'affiliate_link' => $manualProductRow['affiliate_url'], 'price_refreshed_at' => null,
        'created_at' => $manualProductRow['created_at'], 'share_count' => 0,
    ];
}
usort($adminProductRows, static function ($a, $b) {
    $aTime = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
    $bTime = strtotime((string) ($b['created_at'] ?? '')) ?: 0;
    return ($bTime <=> $aTime) ?: ((int) $b['id'] <=> (int) $a['id']);
});

/* Facts about the tags that more than one tab needs, worked out once here rather
   than inside whichever tab happens to render first. */
$tagHolders = array();          // tracking id => the emails holding it
foreach ($allTags as $tagRow) {
    if ($tagRow['tracking_id'] === null || $tagRow['tracking_id'] === '') { continue; }
    $tagHolders[$tagRow['tracking_id']][strtolower($tagRow['user_email'])] = true;
}

$userNameByEmail = array();
foreach ($users as $userRow) {
    $userNameByEmail[strtolower($userRow['email'])] = $userRow['name'];
}

// Which tracking IDs have ever appeared in an uploaded report.
$tagsSeenInReports = array();
try {
    foreach ($pdo->query('SELECT DISTINCT tracking_id FROM tracking_report_monthly') as $row) {
        $tagsSeenInReports[$row['tracking_id']] = true;
    }
} catch (Throwable $error) {
    error_log('admin index seen-tags error: ' . $error->getMessage());
}

/* The combined commission shown against each person on the Users & Tags tab.
 *
 * Commission is per assignment now, so one person can hold several rates. The figure
 * printed is the one that is actually true of their money: what all their tags pay out,
 * divided by what all their tags grossed. Where they have earned nothing there is no
 * weighting to do, so a single rate is shown as itself and a mix is shown as a range.
 *
 * Nothing here is editable — this is a report of what was set on the Create TAG screen. */
$combinedShare = array();     // email => ['value' => %|null, 'low' => %, 'high' => %, 'weighted' => bool]
if ($hasTagShare) {
    try {
        $grossByAssignment = array();
        foreach ($pdo->query(
            "SELECT tracking_id, marketplace, SUM(total_earnings * rate_to_usd) AS gross
             FROM tracking_report_monthly WHERE currency <> ''
             GROUP BY tracking_id, marketplace"
        ) as $row) {
            $grossByAssignment[$row['tracking_id'] . '|' . $row['marketplace']] = (float) $row['gross'];
        }

        $tally = array();
        foreach ($allTags as $tagRow) {
            $email = strtolower(trim((string) $tagRow['user_email']));
            $share = (float) $tagRow['share_percent'];
            $gross = isset($grossByAssignment[$tagRow['tracking_id'] . '|' . $tagRow['marketplace']])
                ? $grossByAssignment[$tagRow['tracking_id'] . '|' . $tagRow['marketplace']]
                : 0.0;

            if (!isset($tally[$email])) {
                $tally[$email] = array('gross' => 0.0, 'payable' => 0.0, 'low' => $share, 'high' => $share);
            }
            $tally[$email]['gross']   += $gross;
            $tally[$email]['payable'] += $gross * $share / 100;
            $tally[$email]['low']  = min($tally[$email]['low'], $share);
            $tally[$email]['high'] = max($tally[$email]['high'], $share);
        }

        foreach ($tally as $email => $sums) {
            $combinedShare[$email] = array(
                'value'    => $sums['gross'] > 0 ? $sums['payable'] / $sums['gross'] * 100 : null,
                'low'      => $sums['low'],
                'high'     => $sums['high'],
                'weighted' => $sums['gross'] > 0,
            );
        }
    } catch (Throwable $error) {
        error_log('admin index combined share error: ' . $error->getMessage());
    }
}

$reportUploads = array();
$reportSetupMessage = '';
try {
    $historyStatement = $pdo->query("
        SELECT uploads.id,
               uploads.filename,
               uploads.report_month,
               uploads.marketplace,
               uploads.currency,
               uploads.rate_to_usd,
               uploads.share_percent,
               uploads.rate_source,
               uploads.rows_processed,
               uploads.uploaded_by,
               uploads.uploaded_at,
               (SELECT COUNT(*) FROM report_upload_items AS items WHERE items.upload_id = uploads.id) AS item_count,
               (SELECT COUNT(*) FROM report_uploads AS same_month WHERE same_month.report_month = uploads.report_month) AS month_upload_count
        FROM report_uploads AS uploads
        ORDER BY uploads.id DESC
        LIMIT 200
    ");
    $reportUploads = $historyStatement->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $error) {
    error_log('admin index report history error: ' . $error->getMessage());
    $reportSetupMessage = 'Report history tables are not ready. Import report_tables.sql once, then refresh this page.';
}

$reportMonths = array();
$selectedReportMonth = '';
$reportAnalyticsMessage = '';
$reportAnalytics = array(
    'tracking_count' => 0,
    'record_count' => 0,
    'clicks' => 0,
    'items_ordered' => 0,
    'ordered_revenue' => 0,
    'items_shipped' => 0,
    'items_returned' => 0,
    'items_shipped_revenue' => 0,
    'items_returned_revenue' => 0,
    'total_earnings' => 0,
    'bonus' => 0,
    'items_shipped_earnings' => 0,
    'items_returned_earnings' => 0,
);
$reportTrackingRows = array();
$reportAnalyticsPage = isset($_GET['report_page']) ? max(1, (int) $_GET['report_page']) : 1;
$reportAnalyticsPerPage = 100;
$reportAnalyticsPageCount = 1;

try {
    $monthStatement = $pdo->query("
        SELECT DISTINCT report_month
        FROM tracking_report_monthly
        WHERE report_month IS NOT NULL AND report_month <> ''
        ORDER BY report_month DESC
    ");
    $reportMonths = $monthStatement->fetchAll(PDO::FETCH_COLUMN);

    $requestedReportMonth = isset($_GET['report_month']) ? trim((string) $_GET['report_month']) : '';
    if ($requestedReportMonth === 'all') {
        $selectedReportMonth = 'all';
    } elseif (preg_match('/^\d{4}-\d{2}$/', $requestedReportMonth) && in_array($requestedReportMonth, $reportMonths, true)) {
        $selectedReportMonth = $requestedReportMonth;
    } elseif (!empty($reportMonths)) {
        $selectedReportMonth = (string) $reportMonths[0];
    }

    $reportFilterSql = '';
    $reportFilterParams = array();
    if ($selectedReportMonth !== '' && $selectedReportMonth !== 'all') {
        $reportFilterSql = ' WHERE report_month = :report_month';
        $reportFilterParams[':report_month'] = $selectedReportMonth;
    }

    $analyticsStatement = $pdo->prepare("
        SELECT COUNT(DISTINCT tracking_id) AS tracking_count,
               COUNT(*) AS record_count,
               COALESCE(SUM(clicks), 0) AS clicks,
               COALESCE(SUM(items_ordered), 0) AS items_ordered,
               COALESCE(SUM(ordered_revenue), 0) AS ordered_revenue,
               COALESCE(SUM(items_shipped), 0) AS items_shipped,
               COALESCE(SUM(items_returned), 0) AS items_returned,
               COALESCE(SUM(items_shipped_revenue), 0) AS items_shipped_revenue,
               COALESCE(SUM(items_returned_revenue), 0) AS items_returned_revenue,
               COALESCE(SUM(total_earnings), 0) AS total_earnings,
               COALESCE(SUM(bonus), 0) AS bonus,
               COALESCE(SUM(items_shipped_earnings), 0) AS items_shipped_earnings,
               COALESCE(SUM(items_returned_earnings), 0) AS items_returned_earnings
        FROM tracking_report_monthly" . $reportFilterSql
    );
    $analyticsStatement->execute($reportFilterParams);
    $analyticsResult = $analyticsStatement->fetch(PDO::FETCH_ASSOC);
    if (is_array($analyticsResult)) {
        $reportAnalytics = array_merge($reportAnalytics, $analyticsResult);
    }

    $reportAnalyticsPageCount = max(1, (int) ceil((int) $reportAnalytics['record_count'] / $reportAnalyticsPerPage));
    if ($reportAnalyticsPage > $reportAnalyticsPageCount) {
        $reportAnalyticsPage = $reportAnalyticsPageCount;
    }
    $reportAnalyticsOffset = ($reportAnalyticsPage - 1) * $reportAnalyticsPerPage;

    $detailsStatement = $pdo->prepare("
        SELECT monthly.report_month,
               monthly.tracking_id,
               monthly.marketplace,
               monthly.currency,
               monthly.rate_to_usd,
               monthly.share_percent,
               (monthly.total_earnings * monthly.rate_to_usd * monthly.share_percent / 100) AS payable_usd,
               monthly.clicks,
               monthly.items_ordered,
               monthly.ordered_revenue,
               monthly.items_shipped,
               monthly.items_returned,
               monthly.items_shipped_revenue,
               monthly.items_returned_revenue,
               monthly.total_earnings,
               monthly.bonus,
               monthly.items_shipped_earnings,
               monthly.items_returned_earnings,
               (SELECT GROUP_CONCAT(DISTINCT tags.user_email ORDER BY tags.user_email SEPARATOR ', ')
                  FROM user_tags AS tags
                 WHERE tags.tracking_id = monthly.tracking_id) AS assigned_users,
               (SELECT GROUP_CONCAT(DISTINCT tags.marketplace ORDER BY tags.marketplace SEPARATOR ', ')
                  FROM user_tags AS tags
                 WHERE tags.tracking_id = monthly.tracking_id) AS marketplaces
        FROM tracking_report_monthly AS monthly" . $reportFilterSql . "
        ORDER BY monthly.report_month DESC, monthly.total_earnings DESC, monthly.tracking_id ASC
        LIMIT " . (int) $reportAnalyticsPerPage . " OFFSET " . (int) $reportAnalyticsOffset
    );
    $detailsStatement->execute($reportFilterParams);
    $reportTrackingRows = $detailsStatement->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $error) {
    error_log('admin index report analytics error: ' . $error->getMessage());
    $reportAnalyticsMessage = 'Report analytics are not ready yet. Import report_tables.sql once, then upload a report.';
}

/* ------------------------------------------------------------------ *
 * Currency-aware totals.
 *
 * The block above sums total_earnings across every currency into one number,
 * which is meaningless — USD, EUR and GBP cannot be added. These queries keep
 * each currency in its own bucket and convert to USD only through the rate and
 * share that were frozen onto each row when the month was imported.
 * ------------------------------------------------------------------ */
$currencyTotals   = array();
$payableUsdTotal  = 0.0;
$unsetCurrencyRows = 0;
$countrySettings  = array();
$rateSettings     = array();
$settingsReady    = false;

try {
    $currencyStatement = $pdo->prepare("
        SELECT currency,
               COUNT(DISTINCT tracking_id) AS tracking_count,
               COALESCE(SUM(clicks), 0)          AS clicks,
               COALESCE(SUM(items_ordered), 0)   AS items_ordered,
               COALESCE(SUM(items_shipped), 0)   AS items_shipped,
               COALESCE(SUM(items_returned), 0)  AS items_returned,
               COALESCE(SUM(total_earnings), 0)  AS total_earnings,
               COALESCE(SUM(bonus), 0)           AS bonus,
               COALESCE(SUM(total_earnings * rate_to_usd), 0) AS gross_usd,
               COALESCE(SUM(total_earnings * rate_to_usd * share_percent / 100), 0) AS payable_usd
        FROM tracking_report_monthly" . $reportFilterSql . "
        GROUP BY currency
        ORDER BY payable_usd DESC
    ");
    $currencyStatement->execute($reportFilterParams);

    foreach ($currencyStatement->fetchAll(PDO::FETCH_ASSOC) as $currencyRow) {
        if ((string) $currencyRow['currency'] === '') {
            // Rows imported before the rebuild carry no currency at all. They are
            // shown separately rather than folded into a total that would be wrong.
            $unsetCurrencyRows = (int) $currencyRow['tracking_count'];
        } else {
            $payableUsdTotal += (float) $currencyRow['payable_usd'];
        }
        $currencyTotals[] = $currencyRow;
    }

    $countrySettings = $pdo->query(
        'SELECT marketplace, country_label, currency, share_percent, is_active
         FROM marketplace_settings ORDER BY is_active DESC, country_label ASC'
    )->fetchAll(PDO::FETCH_ASSOC);

    $rateSettings = $pdo->query(
        'SELECT currency, rate_to_usd, quoted_for, is_confirmed
         FROM exchange_rates ORDER BY currency ASC'
    )->fetchAll(PDO::FETCH_ASSOC);

    $settingsReady = !empty($countrySettings);

    // Lifetime figure, converted properly. The old card summed total_earnings across
    // every currency, so it was never a real amount in any of them.
    $lifetimePayableUsd = (float) $pdo->query(
        'SELECT COALESCE(SUM(total_earnings * rate_to_usd * share_percent / 100), 0)
         FROM tracking_report_monthly WHERE currency <> \'\''
    )->fetchColumn();
} catch (Throwable $error) {
    error_log('admin index currency totals error: ' . $error->getMessage());
}

if (!isset($lifetimePayableUsd)) {
    $lifetimePayableUsd = 0.0;
}

// Tracking IDs present in the reports that nobody holds — the Reports tab links
// straight to assigning them.
$unassignedTagList = array();
try {
    $assignedTags = array();
    foreach ($pdo->query("SELECT DISTINCT tracking_id FROM user_tags WHERE tracking_id IS NOT NULL AND tracking_id <> ''") as $row) {
        $assignedTags[$row['tracking_id']] = true;
    }
    foreach ($pdo->query('SELECT DISTINCT tracking_id FROM tracking_report_monthly') as $row) {
        if (!isset($assignedTags[$row['tracking_id']])) {
            $unassignedTagList[] = $row['tracking_id'];
        }
    }
} catch (Throwable $error) {
    error_log('admin index unassigned tags error: ' . $error->getMessage());
}

if (!isset($_SESSION['settings_csrf_token'])) {
    $_SESSION['settings_csrf_token'] = bin2hex(random_bytes(32));
}

$currencySymbols = array(
    'USD' => '$', 'EUR' => '&euro;', 'GBP' => '&pound;', 'CAD' => 'C$', 'JPY' => '&yen;',
    'AUD' => 'A$', 'INR' => '&#8377;', 'MXN' => 'Mex$', 'BRL' => 'R$', 'SEK' => 'kr',
    'PLN' => 'zl', 'AED' => 'AED ', 'SAR' => 'SAR ', 'SGD' => 'S$',
);

function adminMoney($currency, $amount)
{
    global $currencySymbols;
    $symbol = isset($currencySymbols[$currency]) ? $currencySymbols[$currency] : ($currency . ' ');
    return $symbol . number_format((float) $amount, 2);
}

// How many tags sit on each marketplace — shown beside each country.
$tagCountByMarket = array();
foreach ($allTags as $tagRow) {
    $key = $tagRow["marketplace"];
    $tagCountByMarket[$key] = isset($tagCountByMarket[$key]) ? $tagCountByMarket[$key] + 1 : 1;
}

// Which countries are on offer when assigning a tag. Read from the settings table
// so a tag can never be attached to a marketplace that has no currency or share.
$assignableCountries = !empty($countrySettings) ? $countrySettings : array();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Pickwell Admin Dashboard</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&family=IBM+Plex+Mono:wght@400;500&display=swap">
  
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f5faf6; color: #172019; line-height: 1.5; }
    .header { position: sticky; top: 0; z-index: 1000; height: 70px; background: #0f1412; color: white; box-shadow: 0 4px 14px rgba(0,0,0,.23); }
    .header-inner { max-width: 1400px; height: 100%; margin: auto; padding: 0 24px; display: flex; align-items: center; justify-content: space-between; gap: 20px; }
    .brand { display: flex; align-items: center; gap: 10px; font-size: 1.35rem; font-weight: 750; }
    .brand-mark { width: 38px; height: 38px; display: grid; place-items: center; background: #1f5a2c; border-radius: 50%; font-size: 1.5rem; }
    .header-actions { display: flex; align-items: center; gap: 14px; }
    .admin-badge { color: #c8dda9; background: rgba(31, 122, 77,.13); padding: 6px 13px; border-radius: 25px; font-size: .85rem; }
    .logout { color: white; text-decoration: none; border: 1px solid #1f7a4d; border-radius: 25px; padding: 7px 15px; }
    .page { max-width: 1400px; margin: 30px auto; padding: 0 24px 60px; }
    .tabs { display: flex; flex-wrap: wrap; gap: 8px; border-bottom: 2px solid #e2e6df; margin-bottom: 30px; }
    .tab-button { border: 0; background: transparent; padding: 12px 25px; border-radius: 24px 24px 0 0; color: #44534a; font: inherit; font-weight: 700; cursor: pointer; }
    .tab-button.active { background: #1f7a4d; color: white; }
    .tab-pane { display: none; }
    .tab-pane.active { display: block; animation: fade .18s ease; }
    @keyframes fade { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: none; } }
    .card { background: white; border-radius: 26px; padding: 26px; margin-bottom: 24px; box-shadow: 0 10px 28px rgba(18,68,30,.06); }
    .card-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px; margin-bottom: 20px; }
    h2 { font-size: 1.5rem; display: flex; align-items: center; gap: 9px; }
    .subtext { color: #5d6a64; font-size: .87rem; }
    .table-wrap { overflow-x: auto; border-radius: 16px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; padding: 13px 11px; border-bottom: 1px solid #e4e0d6; vertical-align: middle; }
    th { background: #f7faf7; color: #526058; font-size: .78rem; text-transform: uppercase; letter-spacing: .035em; }
    .tag { display: inline-flex; align-items: center; gap: 5px; background: #e8f3e9; color: #17603f; padding: 5px 10px; border-radius: 25px; margin: 3px; font-size: .78rem; font-weight: 700; }
    .button { border: 0; border-radius: 28px; padding: 9px 16px; font: inherit; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 7px; white-space: nowrap; }
    .button:disabled { opacity: .5; cursor: not-allowed; }
    .button-primary { background: #1f7a4d; color: white; }
    .button-primary:hover { background: #2e7d31; }
    .button-secondary { background: #e7ece8; color: #27332a; }
    .button-danger { background: #fbeceb; color: #c83232; border: 1px solid #efc9c3; }
    .button-danger:hover:not(:disabled) { color: white; background: #d53838; }
    .icon-button { border: 0; background: transparent; width: 29px; height: 29px; border-radius: 50%; cursor: pointer; }
    .edit-button { color: #3274d9; }
    .delete-button { color: #d43c3c; }
    .action-row { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
    .field { margin-bottom: 16px; }
    label { display: block; font-weight: 700; margin-bottom: 6px; }
    input, select { width: 100%; padding: 11px 14px; border: 1px solid #cfd8d2; border-radius: 16px; font: inherit; }
    input:focus, select:focus { outline: 0; border-color: #1f7a4d; box-shadow: 0 0 0 3px rgba(31, 122, 77,.14); }
    .status { display: none; margin-top: 14px; padding: 13px 15px; border-radius: 15px; white-space: pre-wrap; overflow-wrap: anywhere; }
    .status.success { display: block; background: #e8f5ec; color: #22632c; }
    .status.error { display: block; background: #fbe9e7; color: #922a2a; }
    .warning { background: #fbf2dc; color: #715800; border: 1px solid #ecd9a8; padding: 13px 15px; border-radius: 15px; margin-bottom: 16px; }
    .badge { display: inline-block; padding: 4px 9px; border-radius: 20px; font-size: .72rem; font-weight: 800; white-space: nowrap; }
    .badge-exact { background: #e5f5e5; color: #24672d; }
    .badge-legacy { background: #fbf2dc; color: #786000; }
    .badge-locked { background: #eeeee8; color: #66726a; }
    .filename { max-width: 280px; overflow-wrap: anywhere; font-weight: 700; }
    .report-filter { display: flex; align-items: flex-end; flex-wrap: wrap; gap: 9px; }
    .report-filter .field { margin: 0; min-width: 190px; }
    .report-filter select { min-width: 190px; }
    .analytics-context { margin: -7px 0 18px; color: #5d6a64; font-size: .9rem; }
    /* ---- rebuilt reports + countries tab ---- */
    .section-title { font-size: 1.05rem; font-weight: 750; margin: 26px 0 4px; }
    /* ---- users tab ---- */
    .users-table td { vertical-align: top; padding-top: 16px; padding-bottom: 16px; }
    .user-ident { display: flex; align-items: center; gap: 11px; }
    .user-avatar { width: 36px; height: 36px; border-radius: 50%; background: #e8f3e9; color: #17603f;
                   display: grid; place-items: center; font-weight: 800; font-size: .82rem; flex: 0 0 auto; }
    .user-name { font-weight: 750; color: #1a4a3c; }
    .user-email { color: #5d6a64; font-size: .8rem; }
    .tag-grid { display: flex; flex-wrap: wrap; gap: 7px; }
    .tag { display: inline-flex; align-items: center; gap: 7px; background: #e8f3e9; color: #17603f;
           padding: 6px 8px 6px 11px; border-radius: 25px; margin: 0; font-size: .8rem; font-weight: 700;
           border: 1px solid #d3e6d7; }
    .tag-country { color: #4a7a62; font-weight: 650; font-size: .74rem; text-transform: uppercase;
                   letter-spacing: .03em; border-right: 1px solid #c9dfcf; padding-right: 7px; }
    .tag-code { font-weight: 750; }
    .tag-shared { background: #fbf3df; color: #6f5410; border-color: #ecd9a8; }
    .tag-shared .tag-country { color: #8f7434; border-right-color: #e4cf96; }
    .tag-shared > i { color: #a87d12; font-size: .75rem; }
    .tag .icon-button { width: 22px; height: 22px; border-radius: 50%; font-size: .68rem;
                        background: rgba(255,255,255,.75); color: #3f6446; }
    .tag .icon-button:hover { background: #fff; color: #14421c; }
    .tag-shared .icon-button { color: #6f5410; }
    /* The actions cell is shrink-to-fit, so its buttons must never wrap. */
    .users-table td:last-child { white-space: nowrap; }
    .users-table .action-row { flex-wrap: nowrap; gap: 6px; }
    .icon-button.danger { width: 36px; height: 36px; color: #b3352f; background: #fbeae7; flex: 0 0 auto; }
    .icon-button.danger:hover { background: #d93636; color: #fff; }
    .commission-btn { display: inline-flex; align-items: center; gap: 7px; border: 1px solid #d3e6d7;
                      background: #e8f3e9; color: #17603f; border-radius: 25px; padding: 7px 13px;
                      font: inherit; font-weight: 800; font-size: .88rem; cursor: pointer; white-space: nowrap; }
    .commission-btn:hover { background: #1f7a4d; color: #fff; border-color: #1f7a4d; }
    .commission-btn i { font-size: .68rem; opacity: .6; }
    .commission-btn:hover i { opacity: 1; }
    /* The read-only twin, on the Users tab: same shape, deliberately not a button, so
       it does not invite a click that goes nowhere. */
    .commission-read { display: inline-flex; align-items: baseline; gap: 5px; white-space: nowrap;
      border: 1px solid var(--line); border-radius: 25px; padding: 5px 12px;
      font-size: .82rem; font-weight: 750; color: var(--muted); background: var(--head); }
    .commission-read small { font-size: .68rem; font-weight: 600; opacity: .75; }
    .user-row.hidden, .taglist-row.hidden { display: none; }
    .taglist-table td:last-child { white-space: nowrap; }
    .taglist-table .subtext { font-size: .78rem; }
    .taglist-table td { vertical-align: top; }

    .orphan-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
    .assign-orphan { border: 1px dashed #bcd6b8; cursor: pointer; font: inherit; }
    .assign-orphan:hover { background: #d9efd4; border-style: solid; }
    .row-warning { background: #fbf5e8; }
    .row-warning td { border-bottom-color: #efe0bd; }
    .row-muted td:not(:last-child) { opacity: .55; }
    .settings-input { width: 108px; padding: 8px 10px; border: 1px solid #c8d8cc; border-radius: 11px; font: inherit; font-weight: 700; text-align: right; background: #fff; }
    .settings-input[type=date] { width: 152px; text-align: left; font-weight: 500; }
    .active-input { width: 17px; height: 17px; accent-color: #2f7a4d; cursor: pointer; }
    .info-note { display: flex; gap: 12px; align-items: flex-start; background: #eef2f5; border: 1px solid #cadcf5; color: #1c4a80; border-radius: 15px; padding: 15px 17px; font-size: .92rem; }
    .analytics-grid { display: grid; grid-template-columns: repeat(4, minmax(150px, 1fr)); gap: 13px; margin-bottom: 24px; }
    .metric-card { min-height: 112px; border: 1px solid #e0e9e2; border-radius: 18px; padding: 17px; background: linear-gradient(145deg, #fbfaf7, #f4f3ee); }
    .metric-card.primary { color: white; border-color: #1f7a4d; background: linear-gradient(135deg, #1f7a4d, #55ad42); }
    .metric-label { display: block; margin-bottom: 8px; color: #66736b; font-size: .76rem; font-weight: 800; letter-spacing: .045em; text-transform: uppercase; }
    .metric-card.primary .metric-label { color: rgba(255,255,255,.82); }
    .metric-value { display: block; font-size: 1.55rem; line-height: 1.2; font-weight: 850; font-variant-numeric: tabular-nums; }
    .metric-note { display: block; margin-top: 6px; color: #708078; font-size: .75rem; }
    .metric-card.primary .metric-note { color: rgba(255,255,255,.82); }
    .analytics-table th, .analytics-table td { white-space: nowrap; }
    .analytics-table .identity-cell { white-space: normal; min-width: 165px; }
    .analytics-table .money-cell { text-align: right; font-variant-numeric: tabular-nums; }
    .analytics-table .earning-cell { color: #24732c; font-weight: 850; }
    .products-table td { vertical-align: top; }
    .admin-product { display: flex; align-items: center; gap: 12px; min-width: 220px; }
    .admin-product img { width: 52px; height: 52px; object-fit: contain; border: 1px solid #e4e0d6; border-radius: 12px; background: #fff; flex: 0 0 auto; }
    .admin-product-title { font-weight: 750; max-width: 380px; overflow-wrap: anywhere; }
    .admin-product-meta { color: #5d6a64; font-size: .78rem; overflow-wrap: anywhere; }
    .product-actions { display: flex; gap: 6px; white-space: nowrap; }
    .product-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 14px; }
    .product-form-grid .field-full { grid-column: 1 / -1; }
    .product-description { min-height: 140px; width: 100%; padding: 11px 14px; border: 1px solid #cfd8d2; border-radius: 16px; font: inherit; resize: vertical; }
    .product-description:focus { outline: 0; border-color: #1f7a4d; box-shadow: 0 0 0 3px rgba(31, 122, 77,.14); }
    .tracking-picker { position: relative; width: 100%; }
    .tracking-picker-trigger { width: 100%; min-height: 46px; display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 13px; border: 1px solid #cfd8d2; border-radius: 14px; background: #fff; color: #22403a; font: inherit; text-align: left; cursor: pointer; }
    .tracking-picker-trigger:focus { outline: 0; border-color: #1f7a4d; box-shadow: 0 0 0 3px rgba(31, 122, 77,.14); }
    .tracking-picker-trigger span { min-width: 0; overflow-wrap: anywhere; }
    .tracking-picker-panel { position: relative; z-index: 2; width: 100%; max-height: 220px; overflow: auto; overscroll-behavior: contain; margin-top: 6px; padding: 8px; border: 1px solid #ddd9d0; border-radius: 12px; background: #fff; box-shadow: 0 10px 24px rgba(20,32,48,.12); }
    .tracking-picker-panel[hidden] { display: none; }
    .tracking-picker-search { position: sticky; top: 0; z-index: 1; margin-bottom: 6px; background: #fff !important; }
    .tracking-picker-option { display: block; width: 100%; padding: 9px 10px; border: 0; border-radius: 8px; background: #fff; color: #22403a; font: inherit; text-align: left; overflow-wrap: anywhere; cursor: pointer; }
    .tracking-picker-option:hover:not(:disabled), .tracking-picker-option:focus-visible { background: #f4f3ee; outline: 0; }
    .tracking-picker-option:disabled { color: #82938c; cursor: not-allowed; }
    .tracking-picker-option[hidden] { display: none; }
    .catalog-search-row { display: flex; gap: 8px; align-items: stretch; }
    .catalog-search-row input { flex: 1 1 auto; min-width: 0; }
    .catalog-search-results { display: grid; gap: 8px; max-height: 280px; overflow: auto; margin: 12px 0; }
    .catalog-product-option { width: 100%; display: flex; align-items: center; gap: 12px; padding: 10px; border: 1px solid #e4e0d6; border-radius: 12px; background: #fff; color: #22403a; text-align: left; cursor: pointer; }
    .catalog-product-option:hover, .catalog-product-option[aria-pressed="true"] { border-color: #b07a2b; background: #fbf5ea; }
    .catalog-product-option img { width: 56px; height: 56px; flex: 0 0 56px; object-fit: contain; border-radius: 8px; background: #f7f6f1; }
    .catalog-product-copy { min-width: 0; display: grid; gap: 3px; }
    .catalog-product-copy strong { overflow-wrap: anywhere; }
    .catalog-product-copy small { color: #5d6a64; }
    .catalog-selected, .catalog-post-result { padding: 12px; border: 1px solid #dfe6e0; border-radius: 12px; background: #f5faf6; overflow-wrap: anywhere; }
    .catalog-post-actions { margin-top: 12px; }
    .modal-card:has(#productPostBuilder) { max-width: 650px; }
    @media (max-width: 600px) { .catalog-search-row { flex-direction: column; } .catalog-search-row .button { width: 100%; } .catalog-post-actions .button { width: 100%; } }
    @media (max-width: 600px) { .product-form-grid { grid-template-columns: 1fr; } .product-form-grid .field-full { grid-column: auto; } .products-table { min-width: 760px; } }
    .pagination { display: flex; align-items: center; justify-content: center; flex-wrap: wrap; gap: 7px; margin-top: 18px; }
    .pagination-link { min-width: 38px; padding: 7px 11px; border-radius: 12px; background: #f0efe8; color: #25423a; text-align: center; text-decoration: none; font-weight: 750; }
    .pagination-link.active { background: #1f7a4d; color: white; }
    .pagination-link.disabled { opacity: .45; pointer-events: none; }
    .modal { display: none; position: fixed; inset: 0; z-index: 2000; background: rgba(0,0,0,.5); align-items: center; justify-content: center; padding: 20px; }
    .modal.open { display: flex; }
    .modal-card { width: 100%; max-width: 510px; max-height: 90vh; overflow-y: auto; background: white; border-radius: 26px; padding: 25px; box-shadow: 0 25px 50px rgba(0,0,0,.28); }
    .modal-card h3 { font-size: 1.35rem; margin-bottom: 18px; }
    .file-input { padding: 13px; background: #fbfaf7; }
    @media (max-width: 980px) { .analytics-grid { grid-template-columns: repeat(2, minmax(145px, 1fr)); } }
    @media (max-width: 760px) { .page { padding-left: 14px; padding-right: 14px; } .card { padding: 18px; } th,td { font-size: .84rem; } .admin-badge { display: none; } .analytics-grid { grid-template-columns: 1fr 1fr; } .metric-value { font-size: 1.25rem; } }
    @media (max-width: 430px) { .analytics-grid { grid-template-columns: 1fr; } .report-filter, .report-filter .field, .report-filter select, .report-filter .button { width: 100%; } }
  </style>
    <link rel="stylesheet" href="assets/app.css?v=<?= rawurlencode((string) filemtime(__DIR__ . '/assets/app.css')) ?>">
    <link rel="stylesheet" href="assets/premium-refresh.css?v=<?= rawurlencode((string) filemtime(__DIR__ . '/assets/premium-refresh.css')) ?>">
    <link rel="stylesheet" href="assets/pickwell-admin.css?v=<?= rawurlencode((string) filemtime(__DIR__ . '/assets/pickwell-admin.css')) ?>">
    <?php $brandPrefix = '../'; require __DIR__ . '/partials/brand_icons.php'; ?>
</head>
<body class="pickwell-admin">
<header class="header">
  <div class="header-inner">
    <button class="mobile-nav-toggle" id="adminNavToggle" type="button" aria-label="Open admin navigation" aria-controls="adminSidebar" aria-expanded="false"><i class="fas fa-bars"></i></button>
    <span class="admin-topbar-title">Operations dashboard</span>
    <div class="header-actions"><span class="admin-badge"><i class="fas fa-shield-halved"></i> <?= adminEscape(isset($_SESSION['admin_name']) ? $_SESSION['admin_name'] : 'Admin') ?></span><a class="logout" href="logout.php"><i class="fas fa-right-from-bracket"></i> Logout</a></div>
  </div>
</header>

<aside class="tabs admin-workspace-nav" id="adminSidebar" aria-label="Admin workspace navigation">
    <a class="rail-brand" href="index.php"><span class="brand-mark"><img src="../assets/brand/pickwell-mark-light.svg" alt=""></span><span><b>Pickwell</b><small>CONTROL CENTER</small></span></a>
    <span class="nav-group-label">PEOPLE</span>
    <button class="tab-button active" type="button" aria-pressed="true" aria-controls="usersTab" data-tab="usersTab"><i class="fas fa-users"></i> Users &amp; tags</button>
    <button class="tab-button" type="button" aria-pressed="false" aria-controls="productsTab" data-tab="productsTab"><i class="fas fa-box-open"></i> Products</button>
    <button class="tab-button" type="button" aria-pressed="false" aria-controls="createTagTab" data-tab="createTagTab"><i class="fas fa-tag"></i> Tracking tags</button>
    <span class="nav-group-label nav-group-spaced">PERFORMANCE</span>
    <button class="tab-button" type="button" aria-pressed="false" aria-controls="reportsTab" data-tab="reportsTab"><i class="fas fa-chart-column"></i> Reports &amp; earnings</button>
    <a class="tab-button" href="upload-month.php"><i class="fas fa-arrow-up-from-bracket"></i> Import reports</a>
    <a class="tab-button" href="user-commission.php"><i class="fas fa-file-invoice-dollar"></i> Payouts</a>
    <span class="nav-group-label nav-group-spaced">CONFIGURATION</span>
    <button class="tab-button" type="button" aria-pressed="false" aria-controls="apiTab" data-tab="apiTab"><i class="fas fa-plug"></i> API connections</button>
    <button class="tab-button" type="button" aria-pressed="false" aria-controls="countriesTab" data-tab="countriesTab"><i class="fas fa-earth-americas"></i> Markets &amp; rates</button>
    <span class="nav-group-label nav-group-spaced">SITE</span>
    <a class="tab-button" href="../products.php"><i class="fas fa-box-open"></i> View catalog</a>
    <a class="tab-button" href="../Home.php"><i class="fas fa-arrow-up-right-from-square"></i> Public homepage</a>
    <div class="nav-rail-foot"><span class="status-dot"></span><span>Workspace protected</span></div>
  </aside>
<button class="admin-nav-backdrop" id="adminNavBackdrop" type="button" aria-label="Close admin navigation"></button>

<main class="page">
  <div class="admin-welcome"><div><span class="admin-overline">PICKWELL <i>/</i> OPERATIONS</span><h1>Good day, <?= adminEscape(isset($_SESSION['admin_name']) ? $_SESSION['admin_name'] : 'Admin') ?>.</h1><p>Everything your team needs to manage contributors and reporting.</p></div><div class="admin-welcome-badge"><span class="status-dot"></span> All systems operational</div></div>

  <section id="usersTab" class="tab-pane active">
      <?php
      // Friendly country names instead of raw marketplace hosts.
      $countryLabels = array();
      foreach ($countrySettings as $countryRow) {
          $countryLabels[$countryRow['marketplace']] = $countryRow['country_label'];
      }

      $usersWithTags = 0;
      $usersSharing  = 0;
      foreach ($users as $userRow) {
          $theirTags = isset($tagsByUser[$userRow['email']]) ? $tagsByUser[$userRow['email']] : array();
          if (!empty($theirTags)) { $usersWithTags++; }
          foreach ($theirTags as $tagRow) {
              if (isset($tagHolders[$tagRow['tracking_id']]) && count($tagHolders[$tagRow['tracking_id']]) > 1) {
                  $usersSharing++;
                  break;
              }
          }
      }
      ?>
      <div class="card">
        <div class="card-header">
          <div>
            <h2><i class="fas fa-users"></i> Registered Users</h2>
            <p class="subtext">Everybody with an account, and the tracking IDs assigned to them.</p>
          </div>
          <div class="action-row">
            <input type="search" id="userSearch" class="settings-input" style="width:250px;text-align:left;font-weight:500" placeholder="Search name, email or tag">
            <span class="subtext" id="userCount"></span>
          </div>
        </div>

        <div class="analytics-grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr))">
          <div class="metric-card primary">
            <span class="metric-label">Users</span>
            <span class="metric-value"><?= number_format(count($users)) ?></span>
            <span class="metric-note"><?= number_format($usersWithTags) ?> hold at least one tag</span>
          </div>
          <div class="metric-card">
            <span class="metric-label">No tag yet</span>
            <span class="metric-value"><?= number_format(count($users) - $usersWithTags) ?></span>
            <span class="metric-note">they cannot earn until one is assigned</span>
          </div>
          <div class="metric-card">
            <span class="metric-label">Sharing a tag</span>
            <span class="metric-value"><?= number_format($usersSharing) ?></span>
            <span class="metric-note"><?= $usersSharing > 0 ? 'each holder is credited in full' : 'no duplicates' ?></span>
          </div>
          <div class="metric-card">
            <span class="metric-label">Tags assigned</span>
            <span class="metric-value"><?= number_format(count($allTags)) ?></span>
            <span class="metric-note">across <?= number_format(count($tagHolders)) ?> tracking IDs</span>
          </div>
        </div>

        <p class="subtext" style="margin-bottom:14px">
          Commission is set per tag on the <a href="#createTagTab" class="open-create-tag-tab">Create TAG</a> screen.
          The figure below is the combined rate across everything a person holds, so it is shown here but not changed here.
        </p>

        <div class="table-wrap"><table class="users-table">
          <thead><tr><th style="min-width:230px">User</th><th style="width:1%">Commission</th><th>Assigned Tags</th><th style="width:1%">Actions</th></tr></thead>
          <tbody>
          <?php if (empty($users)): ?><tr><td colspan="4" class="subtext">No users found.</td></tr><?php endif; ?>
          <?php foreach ($users as $user): ?>
            <?php
            $isSelf = $adminEmail !== '' && strcasecmp($user['email'], $adminEmail) === 0;
            $userTags = isset($tagsByUser[$user['email']]) ? $tagsByUser[$user['email']] : array();
            $initials = '';
            foreach (preg_split('/\s+/', trim($user['name'])) as $part) {
                if ($part !== '' && preg_match('/[A-Za-z]/', $part[0])) { $initials .= strtoupper($part[0]); }
            }
            $initials = substr($initials, 0, 2) !== '' ? substr($initials, 0, 2) : '?';
            ?>
            <tr class="user-row">
              <td>
                <div class="user-ident">
                  <span class="user-avatar"><?= adminEscape($initials) ?></span>
                  <span>
                    <span class="user-name"><?= adminEscape($user['name']) ?><?= $isSelf ? ' <span class="badge badge-locked">You</span>' : '' ?></span><br>
                    <span class="user-email"><?= adminEscape($user['email']) ?></span>
                  </span>
                </div>
              </td>
              <td>
                <span class="commission-read"><?= adminEscape(ddTrimPercent(isset($user['dummy_commission_percent']) ? $user['dummy_commission_percent'] : 70)) ?>%</span>
                <button type="button" class="icon-button edit-button edit-dummy-commission"
                        title="Set the commission percentage shown to this user"
                        data-id="<?= (int) $user['id'] ?>"
                        data-name="<?= adminEscape($user['name']) ?>"
                        data-value="<?= adminEscape(number_format((float)(isset($user['dummy_commission_percent']) ? $user['dummy_commission_percent'] : 70), 2, '.', '')) ?>"><i class="fas fa-pen"></i></button>
              </td>
              <td>
                <?php if (empty($userTags)): ?>
                  <span class="subtext"><i class="fas fa-circle-minus"></i> No tag assigned &mdash; this person cannot earn yet</span>
                <?php else: ?>
                  <div class="tag-grid">
                  <?php foreach ($userTags as $tag): ?>
                    <?php
                    $shared = isset($tagHolders[$tag['tracking_id']]) ? count($tagHolders[$tag['tracking_id']]) : 1;
                    $countryLabel = isset($countryLabels[$tag['marketplace']])
                        ? $countryLabels[$tag['marketplace']] : $tag['marketplace'];
                    ?>
                    <span class="tag<?= $shared > 1 ? ' tag-shared' : '' ?>"<?= $shared > 1 ? ' title="Also assigned to ' . ($shared - 1) . ' other user' . ($shared > 2 ? 's' : '') . '"' : '' ?>>
                      <span class="tag-country"><?= adminEscape($countryLabel) ?></span>
                      <span class="tag-code"><?= adminEscape($tag['tracking_id']) ?></span>
                      <?php if ($shared > 1): ?><i class="fas fa-triangle-exclamation"></i><?php endif; ?>
                      <button class="icon-button edit-tag" data-tag="<?= adminEscape(json_encode($tag)) ?>" title="Rename this tag"><i class="fas fa-pen"></i></button>
                      <button class="icon-button delete-tag" data-id="<?= (int) $tag['id'] ?>" title="Remove this tag"><i class="fas fa-xmark"></i></button>
                    </span>
                  <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </td>
              <td>
                <div class="action-row" style="flex-wrap:nowrap">
                  <button class="button button-secondary assign-user-tag" data-email="<?= adminEscape($user['email']) ?>"><i class="fas fa-plus"></i> Add Tag</button>
                  <?php if (!$isSelf): ?>
                    <button class="icon-button danger delete-user" data-id="<?= (int) $user['id'] ?>" data-email="<?= adminEscape($user['email']) ?>" title="Delete this user"><i class="fas fa-user-minus"></i></button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>

        <?php if ($usersSharing > 0): ?>
          <div class="warning" style="margin-top:18px">
            <i class="fas fa-triangle-exclamation"></i>
            <strong><?= number_format($usersSharing) ?> users hold a tracking ID that somebody else also holds.</strong>
            Those tags are marked in amber above. At payout each holder is credited the full amount, so the
            total comes out higher than the money actually earned. Remove the tag from whoever should not have it.
          </div>
        <?php endif; ?>
      </div>
    </section>


  <section id="createTagTab" class="tab-pane">
    <div class="card">
      <div class="card-header"><h2><i class="fas fa-plus-circle"></i> Assign New Tag</h2></div>
      <form id="assignTagForm" class="admin-form-grid">
        <div class="field"><label for="assignEmail">User Email</label><input type="email" id="assignEmail" required></div>
        <div class="field"><label for="assignTag">Tracking ID</label><input type="text" id="assignTag" required></div>
        <div class="field"><label for="assignMarketplace">Marketplace</label>
          <select id="assignMarketplace">
            <?php if (!empty($assignableCountries)): ?>
              <?php foreach ($assignableCountries as $country): ?>
                <option value="<?= adminEscape($country['marketplace']) ?>"><?= adminEscape($country['country_label']) ?> (<?= adminEscape(preg_replace('/^www\./', '', $country['marketplace'])) ?>) &mdash; <?= adminEscape($country['currency']) ?><?= (int) $country['is_active'] === 1 ? '' : ' &middot; inactive' ?></option>
              <?php endforeach; ?>
            <?php else: ?>
              <?php /* Fallback for before the migration has been run, so this form never breaks. */ ?>
              <option value="www.amazon.com">USA (amazon.com)</option><option value="www.amazon.co.uk">UK (amazon.co.uk)</option><option value="www.amazon.de">Germany (amazon.de)</option><option value="www.amazon.fr">France (amazon.fr)</option><option value="www.amazon.es">Spain (amazon.es)</option><option value="www.amazon.ca">Canada (amazon.ca)</option><option value="www.amazon.it">Italy (amazon.it)</option>
            <?php endif; ?>
          </select>
          <p class="subtext" style="margin-top:6px">The currency comes from the country. Manage countries and rates under <a href="#countriesTab" class="open-countries-tab">Countries &amp; Rates</a>.</p>
        </div>
        <div class="field">
          <label for="assignShare">Commission %</label>
          <input type="number" id="assignShare" step="0.01" min="0" max="100" style="max-width:160px"
                 value="<?= number_format($defaultTagShare, 2, '.', '') ?>" required>
          <p class="subtext" style="margin-top:6px">What this person earns on this tag. Each tag is set on its own, so the same person can be on a different rate elsewhere.</p>
        </div>
        <button class="button button-primary" type="submit"><i class="fas fa-save"></i> Assign Tag</button>
      </form>
      <div id="tagStatus" class="status"></div>
    </div>
        <?php
      /* Every tag in one flat list. The Users tab answers "what does this person
         hold"; this answers "who holds this tracking ID" — the question you have when
         a name turns up in a report and you do not know whose it is. */
      $sortedTags = $allTags;
      usort($sortedTags, function ($a, $b) {
          $byCountry = strcmp($a['marketplace'], $b['marketplace']);
          return $byCountry !== 0 ? $byCountry : strcasecmp($a['tracking_id'], $b['tracking_id']);
      });

      $sharedCount = 0;
      $unusedCount = 0;
      foreach ($sortedTags as $tagRow) {
          if (isset($tagHolders[$tagRow['tracking_id']]) && count($tagHolders[$tagRow['tracking_id']]) > 1) { $sharedCount++; }
          if (!isset($tagsSeenInReports[$tagRow['tracking_id']])) { $unusedCount++; }
      }
      ?>
      <div class="card">
        <div class="card-header">
          <div>
            <h2><i class="fas fa-tags"></i> All Assigned Tags</h2>
            <p class="subtext">
              <?= number_format(count($sortedTags)) ?> assignments across <?= number_format(count($tagHolders)) ?> tracking IDs.
              <?php if ($sharedCount > 0): ?><strong><?= number_format($sharedCount) ?></strong> are held by more than one person. <?php endif; ?>
              <?php if ($unusedCount > 0): ?><strong><?= number_format($unusedCount) ?></strong> have never appeared in an uploaded report.<?php endif; ?>
            </p>
          </div>
          <div class="action-row">
            <input type="search" id="tagListSearch" class="settings-input" style="width:250px;text-align:left;font-weight:500" placeholder="Search tag, user or country">
            <span class="subtext" id="tagListCount"></span>
          </div>
        </div>

        <div class="table-wrap"><table class="taglist-table">
          <thead><tr><th>Tracking ID</th><th>Country</th><th>Assigned to</th><th style="width:1%">Commission</th><th>Status</th><th style="width:1%">Actions</th></tr></thead>
          <tbody>
          <?php if (empty($sortedTags)): ?>
            <tr><td colspan="6" class="subtext">No tags have been assigned yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($sortedTags as $tagRow): ?>
            <?php
            $holders = isset($tagHolders[$tagRow['tracking_id']]) ? array_keys($tagHolders[$tagRow['tracking_id']]) : array();
            $isShared = count($holders) > 1;
            $seen = isset($tagsSeenInReports[$tagRow['tracking_id']]);
            $holderEmail = strtolower((string) $tagRow['user_email']);
            $holderName = isset($userNameByEmail[$holderEmail]) ? $userNameByEmail[$holderEmail] : null;
            $label = isset($countryLabels[$tagRow['marketplace']])
                ? $countryLabels[$tagRow['marketplace']] : $tagRow['marketplace'];
            ?>
            <tr class="taglist-row<?= $isShared ? ' row-warning' : '' ?>">
              <td class="identity-cell"><strong><?= adminEscape($tagRow['tracking_id']) ?></strong></td>
              <td>
                <?= adminEscape($label) ?>
                <div class="subtext"><?= adminEscape($tagRow['marketplace']) ?></div>
              </td>
              <td class="identity-cell">
                <?php if ($holderName !== null): ?>
                  <?= adminEscape($holderName) ?>
                  <div class="subtext"><?= adminEscape($tagRow['user_email']) ?></div>
                <?php else: ?>
                  <span class="subtext"><?= adminEscape($tagRow['user_email']) ?> &mdash; no account with this email</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($hasTagShare): ?>
                  <button class="commission-btn set-tag-commission"
                          data-id="<?= (int) $tagRow['id'] ?>"
                          data-tag="<?= adminEscape($tagRow['tracking_id']) ?>"
                          data-country="<?= adminEscape($label) ?>"
                          data-who="<?= adminEscape($holderName !== null ? $holderName : $tagRow['user_email']) ?>"
                          data-share="<?= adminEscape(number_format((float) $tagRow['share_percent'], 2, '.', '')) ?>"
                          title="Set the commission for this tag">
                    <?= adminEscape(ddTrimPercent($tagRow['share_percent'])) ?>%
                  </button>
                <?php else: ?>
                  <span class="subtext">&mdash;</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($isShared): ?>
                  <span class="badge badge-legacy" title="<?= adminEscape(implode(', ', $holders)) ?>">Shared with <?= count($holders) - 1 ?> other<?= count($holders) > 2 ? 's' : '' ?></span>
                <?php endif; ?>
                <?php if ($seen): ?>
                  <span class="badge badge-exact">In reports</span>
                <?php else: ?>
                  <span class="badge badge-locked">Never seen</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="action-row" style="flex-wrap:nowrap">
                  <button class="icon-button edit-tag" data-tag="<?= adminEscape(json_encode($tagRow)) ?>" title="Rename this tag"><i class="fas fa-pen"></i></button>
                  <button class="icon-button danger delete-tag" data-id="<?= (int) $tagRow['id'] ?>" title="Remove this tag"><i class="fas fa-xmark"></i></button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      </div>
    </section>


  <section id="apiTab" class="tab-pane">
    <div class="card">
      <div class="card-header"><h2><i class="fas fa-database"></i> Amazon API Credentials</h2><button class="button button-primary" id="addApi"><i class="fas fa-plus"></i> Add New API</button></div>
      <div class="table-wrap"><table>
        <thead><tr><th>Credential ID</th><th>Secret</th><th>Partner Tag</th><th>Marketplace</th><th>Version</th><th>Actions</th></tr></thead>
        <tbody>
          <?php if (empty($apiCredentials)): ?><tr><td colspan="6" class="subtext">No API credentials saved.</td></tr><?php endif; ?>
          <?php foreach ($apiCredentials as $api): ?>
            <tr><td><?= adminEscape($api['credential_id']) ?></td><td><?= adminEscape(substr($api['credential_secret'], 0, 10)) ?>...</td><td><?= adminEscape($api['partner_tag']) ?></td><td><?= adminEscape($api['marketplace']) ?></td><td><?= adminEscape($api['version']) ?></td><td><button class="icon-button edit-button edit-api" data-api="<?= adminEscape(json_encode($api)) ?>"><i class="fas fa-pen"></i></button><button class="icon-button delete-button delete-api" data-id="<?= (int) $api['id'] ?>"><i class="fas fa-trash"></i></button></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  </section>

  <section id="productsTab" class="tab-pane">
    <div class="card">
      <div class="card-header">
        <div><h2><i class="fas fa-box-open"></i> Product catalog</h2><p class="subtext">Manage products stored in the catalog. Amazon image and price data are refreshed from the connected API.</p></div>
        <div class="action-row"><button class="button button-primary" id="addProduct" type="button"><i class="fas fa-plus"></i> Add catalog product</button><button class="button button-secondary" id="addManualProduct" type="button"><i class="fas fa-pen-to-square"></i> Add manual product</button></div>
      </div>
      <?php if (!$productSchemaReady): ?><div class="warning"><?= adminEscape($productSchemaMessage ?: 'Product catalog is unavailable.') ?></div><?php else: ?>
        <div class="action-row" style="margin-bottom:16px"><input id="productSearch" type="search" placeholder="Search title, ASIN, marketplace…" style="max-width:380px"><span class="subtext" id="productCount"></span></div>
        <div class="table-wrap"><table class="products-table">
          <thead><tr><th>Product</th><th>Marketplace</th><th>Price from Amazon API</th><th>Shared links</th><th>Actions</th></tr></thead>
          <tbody>
            <?php if (!$adminProductRows): ?><tr><td colspan="5" class="subtext">No products are stored yet.</td></tr><?php endif; ?>
            <?php foreach ($adminProductRows as $product): ?>
              <tr class="product-row">
                <td><div class="admin-product"><?php if (!empty($product['image'])): ?><img src="<?= adminEscape($product['image']) ?>" alt="" loading="lazy"><?php endif; ?><div><div class="admin-product-title"><?= adminEscape($product['title'] ?: 'Amazon product ' . $product['asin']) ?></div><div class="admin-product-meta"><?= $product['product_source'] === 'manual' ? 'Manual product' : 'ASIN ' . adminEscape($product['asin']) ?></div></div></div></td>
                <td><?= adminEscape($product['marketplace']) ?></td>
                <td><?= $product['product_source'] === 'manual' ? 'Manual entry · check Amazon' : adminEscape($product['price'] ?: 'Price unavailable') ?><?php if (!empty($product['price_refreshed_at'])): ?><div class="admin-product-meta">Fetched <?= adminEscape($product['price_refreshed_at']) ?> UTC</div><?php endif; ?></td>
                <td><?= (int) $product['share_count'] ?></td>
                <td><div class="product-actions"><?php if ($product['product_source'] === 'manual'): ?><button type="button" class="icon-button edit-manual-product" title="Edit manual product" data-product="<?= adminEscape(json_encode($product, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>"><i class="fas fa-pen"></i></button><button type="button" class="icon-button danger delete-manual-product" title="Delete manual product" data-id="<?= (int) $product['id'] ?>" data-title="<?= adminEscape($product['title']) ?>"><i class="fas fa-trash"></i></button><a class="button button-secondary" href="<?= adminEscape('../product.php?manual=' . rawurlencode($product['manual_slug'])) ?>" target="_blank" rel="noopener">Open ↗</a><?php else: ?><button type="button" class="icon-button edit-product" title="Edit product" data-product="<?= adminEscape(json_encode($product, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>"><i class="fas fa-pen"></i></button><button type="button" class="icon-button danger delete-product" title="Delete product" data-id="<?= (int) $product['id'] ?>" data-title="<?= adminEscape($product['title']) ?>"><i class="fas fa-trash"></i></button><?php endif; ?></div></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
        <p class="subtext" style="margin-top:12px">Product image and price are read-only and come from the connected Amazon API.</p>
      <?php endif; ?>
      <?php if (!$manualProductSchemaReady): ?><div class="warning">Run database migration <code>sql/012_manual_products.sql</code> once to enable manual product posts.</div><?php endif; ?>
      <div id="productStatus" class="status"></div>
    </div>
  </section>

  <section id="reportsTab" class="tab-pane">
    <div class="card">
      <div class="card-header">
        <div><h2><i class="fas fa-chart-line"></i> Earnings Analytics</h2><p class="subtext">Complete month-wise and tracking-wise report for Admin.</p></div>
        <form class="report-filter" method="get" action="index.php">
          <input type="hidden" name="tab" value="reportsTab">
          <div class="field">
            <label for="reportMonth">Report Month</label>
            <select id="reportMonth" name="report_month">
              <option value="all" <?= $selectedReportMonth === 'all' ? 'selected' : '' ?>>All Months</option>
              <?php foreach ($reportMonths as $reportMonth): ?>
                <option value="<?= adminEscape($reportMonth) ?>" <?= $selectedReportMonth === $reportMonth ? 'selected' : '' ?>><?= adminEscape(date('F Y', strtotime($reportMonth . '-01'))) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="button button-primary" type="submit"><i class="fas fa-filter"></i> Show Data</button>
        </form>
      </div>

      <?php if ($reportAnalyticsMessage !== ''): ?>
        <div class="warning"><i class="fas fa-triangle-exclamation"></i> <?= adminEscape($reportAnalyticsMessage) ?></div>
      <?php else: ?>
        <?php $reportPeriodLabel = $selectedReportMonth === 'all' ? 'All months combined' : ($selectedReportMonth !== '' ? date('F Y', strtotime($selectedReportMonth . '-01')) : 'No report data'); ?>
        <p class="analytics-context"><i class="fas fa-calendar-days"></i> Showing: <strong><?= adminEscape($reportPeriodLabel) ?></strong></p>

        <div class="analytics-grid">
          <div class="metric-card primary"><span class="metric-label">Payable to everyone (USD)</span><span class="metric-value">$<?= number_format($payableUsdTotal, 2) ?></span><span class="metric-note">Converted at the rate frozen on each month, then the country's share applied</span></div>
          <div class="metric-card"><span class="metric-label">Lifetime Payable (USD)</span><span class="metric-value">$<?= number_format($lifetimePayableUsd, 2) ?></span><span class="metric-note">Every month that has a currency recorded</span></div>
          <div class="metric-card"><span class="metric-label">Tracking IDs</span><span class="metric-value"><?= number_format((int) $reportAnalytics['tracking_count']) ?></span><span class="metric-note"><?= number_format((int) $reportAnalytics['record_count']) ?> monthly records<?= !empty($unassignedTagList) ? ' · <strong>' . count($unassignedTagList) . '</strong> unassigned' : '' ?></span></div>
          <div class="metric-card"><span class="metric-label">Clicks</span><span class="metric-value"><?= number_format((int) $reportAnalytics['clicks']) ?></span><span class="metric-note">Counts are currency-neutral, so they add up safely</span></div>
          <div class="metric-card"><span class="metric-label">Items Ordered</span><span class="metric-value"><?= number_format((int) $reportAnalytics['items_ordered']) ?></span><span class="metric-note"><?= number_format((int) $reportAnalytics['items_shipped']) ?> shipped &middot; <?= number_format((int) $reportAnalytics['items_returned']) ?> returned</span></div>
        </div>

        <h3 class="section-title"><i class="fas fa-coins"></i> Earnings by currency</h3>
        <p class="subtext" style="margin-bottom:14px">Each currency is kept in its own bucket. Dollars, euros and pounds are never added together &mdash; only the converted USD column can be summed.</p>

        <div class="table-wrap"><table>
          <thead><tr><th>Currency</th><th>Tracking IDs</th><th>Clicks</th><th>Shipped</th><th>Earnings in that currency</th><th>Converted (USD)</th><th>Payable after share (USD)</th></tr></thead>
          <tbody>
          <?php if (empty($currencyTotals)): ?>
            <tr><td colspan="7" class="subtext">No report data for this period.</td></tr>
          <?php endif; ?>
          <?php foreach ($currencyTotals as $currencyRow): ?>
            <?php $code = (string) $currencyRow['currency']; ?>
            <tr<?= $code === '' ? ' class="row-warning"' : '' ?>>
              <td class="identity-cell">
                <?php if ($code === ''): ?>
                  <strong>Not set</strong> <span class="badge badge-legacy">imported before the rebuild</span>
                <?php else: ?>
                  <strong><?= adminEscape($code) ?></strong>
                <?php endif; ?>
              </td>
              <td><?= number_format((int) $currencyRow['tracking_count']) ?></td>
              <td><?= number_format((int) $currencyRow['clicks']) ?></td>
              <td><?= number_format((int) $currencyRow['items_shipped']) ?></td>
              <td class="money-cell"><?= $code === '' ? number_format((float) $currencyRow['total_earnings'], 2) : adminMoney($code, $currencyRow['total_earnings']) ?></td>
              <td class="money-cell"><?= $code === '' ? '<span class="subtext">cannot convert</span>' : '$' . number_format((float) $currencyRow['gross_usd'], 2) ?></td>
              <td class="money-cell earning-cell"><?= $code === '' ? '<span class="subtext">&mdash;</span>' : '$' . number_format((float) $currencyRow['payable_usd'], 2) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <?php if (!empty($currencyTotals)): ?>
          <tfoot><tr>
            <td colspan="6"><strong>Total payable in USD</strong></td>
            <td class="money-cell earning-cell"><strong>$<?= number_format($payableUsdTotal, 2) ?></strong></td>
          </tr></tfoot>
          <?php endif; ?>
        </table></div>

        <?php if (!empty($unassignedTagList)): ?>
          <h3 class="section-title"><i class="fas fa-user-slash"></i> Tracking IDs nobody holds</h3>
          <p class="subtext" style="margin-bottom:12px">These appear in the uploaded reports but are not assigned to any user, so their earnings cannot reach anybody's report. Click one to assign it.</p>
          <div class="orphan-tags">
            <?php foreach ($unassignedTagList as $orphanTag): ?>
              <button type="button" class="tag assign-orphan" data-tag="<?= adminEscape($orphanTag) ?>" title="Assign this tracking ID">
                <i class="fas fa-plus"></i> <?= adminEscape($orphanTag) ?>
              </button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if ($unsetCurrencyRows > 0): ?>
          <div class="warning" style="margin-top:16px">
            <i class="fas fa-triangle-exclamation"></i>
            <strong><?= number_format($unsetCurrencyRows) ?> tracking ID<?= $unsetCurrencyRows === 1 ? '' : 's' ?> have no currency attached.</strong>
            These were imported by the old uploader, which recorded no country and no currency, so their earnings cannot be converted or paid.
            Re-upload those months through <a href="upload-month.php">Upload a month</a> and they will be replaced with proper figures.
          </div>
        <?php endif; ?>

        <div class="card-header"><div><h2><i class="fas fa-list"></i> Tracking-wise Details</h2><p class="subtext">Assignment, activity, revenue, and earnings for each tracking ID.</p></div><div class="action-row"><input type="search" id="trackingSearch" class="settings-input" style="width:230px;text-align:left;font-weight:500" placeholder="Filter by tag, user or country"><span class="subtext" id="trackingCount"></span><a href="user-commission.php?month=<?= urlencode($selectedReportMonth) ?>" class="button button-secondary"><i class="fas fa-users"></i> View user-wise instead</a></div></div>
        <div class="table-wrap"><table class="analytics-table">
          <thead><tr><th>Month</th><th>Tracking ID</th><th>Assigned User</th><th>Country</th><th>Cur.</th><th>Clicks</th><th>Ordered</th><th>Shipped</th><th>Returned</th><th>Ordered Revenue</th><th>Shipped Earnings</th><th>Returned Earnings</th><th>Bonus</th><th>Total Earnings</th><th>Rate</th><th>Share</th><th>Payable (USD)</th></tr></thead>
          <tbody>
          <?php if (empty($reportTrackingRows)): ?><tr><td colspan="17" class="subtext">No report data was found for this period.</td></tr><?php endif; ?>
          <?php foreach ($reportTrackingRows as $reportRow): ?>
            <?php
            $rowCurrency = (string) $reportRow['currency'];
            $rowCountry = (string) $reportRow['marketplace'];
            $hasCurrency = $rowCurrency !== '';
            ?>
            <tr<?= $hasCurrency ? '' : ' class="row-warning"' ?>>
              <td><?= adminEscape($reportRow['report_month']) ?></td>
              <td class="identity-cell"><strong><?= adminEscape($reportRow['tracking_id']) ?></strong></td>
              <td class="identity-cell"><?= $reportRow['assigned_users'] !== null && $reportRow['assigned_users'] !== '' ? adminEscape($reportRow['assigned_users']) : '<span class="subtext">Not assigned</span>' ?></td>
              <td class="identity-cell"><?= $rowCountry !== '' ? adminEscape($rowCountry) : '<span class="subtext">not recorded</span>' ?></td>
              <td><?= $hasCurrency ? adminEscape($rowCurrency) : '<span class="subtext">&mdash;</span>' ?></td>
              <td><?= number_format((int) $reportRow['clicks']) ?></td>
              <td><?= number_format((int) $reportRow['items_ordered']) ?></td>
              <td><?= number_format((int) $reportRow['items_shipped']) ?></td>
              <td><?= number_format((int) $reportRow['items_returned']) ?></td>
              <td class="money-cell"><?= $hasCurrency ? adminMoney($rowCurrency, $reportRow['ordered_revenue']) : number_format((float) $reportRow['ordered_revenue'], 2) ?></td>
              <td class="money-cell"><?= $hasCurrency ? adminMoney($rowCurrency, $reportRow['items_shipped_earnings']) : number_format((float) $reportRow['items_shipped_earnings'], 2) ?></td>
              <td class="money-cell"><?= $hasCurrency ? adminMoney($rowCurrency, $reportRow['items_returned_earnings']) : number_format((float) $reportRow['items_returned_earnings'], 2) ?></td>
              <td class="money-cell"><?= $hasCurrency ? adminMoney($rowCurrency, $reportRow['bonus']) : number_format((float) $reportRow['bonus'], 2) ?></td>
              <td class="money-cell"><?= $hasCurrency ? adminMoney($rowCurrency, $reportRow['total_earnings']) : number_format((float) $reportRow['total_earnings'], 2) ?></td>
              <td><?= $hasCurrency ? adminEscape(rtrim(rtrim(number_format((float) $reportRow['rate_to_usd'], 6, '.', ''), '0'), '.')) : '<span class="subtext">&mdash;</span>' ?></td>
              <td><?= $hasCurrency ? adminEscape(rtrim(rtrim(number_format((float) $reportRow['share_percent'], 2, '.', ''), '0'), '.')) . '%' : '<span class="subtext">&mdash;</span>' ?></td>
              <td class="money-cell earning-cell"><?= $hasCurrency ? '$' . number_format((float) $reportRow['payable_usd'], 2) : '<span class="subtext">no currency</span>' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>

        <?php if ($reportAnalyticsPageCount > 1): ?>
          <nav class="pagination" aria-label="Report pages">
            <?php $previousPage = max(1, $reportAnalyticsPage - 1); $nextPage = min($reportAnalyticsPageCount, $reportAnalyticsPage + 1); ?>
            <a class="pagination-link <?= $reportAnalyticsPage <= 1 ? 'disabled' : '' ?>" href="?<?= adminEscape(http_build_query(array('report_month' => $selectedReportMonth, 'report_page' => $previousPage, 'tab' => 'reportsTab'))) ?>" aria-label="Previous page"><i class="fas fa-chevron-left"></i></a>
            <?php $pageStart = max(1, $reportAnalyticsPage - 2); $pageEnd = min($reportAnalyticsPageCount, $reportAnalyticsPage + 2); ?>
            <?php for ($pageNumber = $pageStart; $pageNumber <= $pageEnd; $pageNumber++): ?>
              <a class="pagination-link <?= $pageNumber === $reportAnalyticsPage ? 'active' : '' ?>" href="?<?= adminEscape(http_build_query(array('report_month' => $selectedReportMonth, 'report_page' => $pageNumber, 'tab' => 'reportsTab'))) ?>"><?= $pageNumber ?></a>
            <?php endfor; ?>
            <a class="pagination-link <?= $reportAnalyticsPage >= $reportAnalyticsPageCount ? 'disabled' : '' ?>" href="?<?= adminEscape(http_build_query(array('report_month' => $selectedReportMonth, 'report_page' => $nextPage, 'tab' => 'reportsTab'))) ?>" aria-label="Next page"><i class="fas fa-chevron-right"></i></a>
          </nav>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <div class="card">
      <div class="card-header"><div><h2><i class="fas fa-clock-rotate-left"></i> Uploaded Reports</h2><p class="subtext">When a report is deleted, both its monthly data and the related user earnings will be reversed.</p></div></div>
      <div class="table-wrap"><table>
        <thead><tr><th>ID</th><th>File</th><th>Month</th><th>Country</th><th>Cur.</th><th>Rate</th><th>Share</th><th>Rows</th><th>Uploaded By</th><th>Uploaded At</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php if (empty($reportUploads)): ?><tr><td colspan="12" class="subtext">No uploaded reports found.</td></tr><?php endif; ?>
        <?php foreach ($reportUploads as $upload): ?>
          <?php
          $itemCount = (int) $upload['item_count'];
          $legacySingleMonth = $itemCount === 0 && !empty($upload['report_month']) && (int) $upload['month_upload_count'] === 1;
          $canDelete = $itemCount > 0 || $legacySingleMonth;
          $uploadCurrency = (string) $upload['currency'];
          $uploadHasCurrency = $uploadCurrency !== '';
          ?>
          <tr<?= $uploadHasCurrency ? '' : ' class="row-warning"' ?>>
            <td>#<?= (int) $upload['id'] ?></td><td class="filename"><?= adminEscape($upload['filename']) ?></td><td><?= adminEscape($upload['report_month'] ?: 'Unknown') ?></td>
            <td class="identity-cell"><?= $upload['marketplace'] !== '' ? adminEscape($upload['marketplace']) : '<span class="subtext">not recorded</span>' ?></td>
            <td><?= $uploadHasCurrency ? adminEscape($uploadCurrency) : '<span class="subtext">&mdash;</span>' ?></td>
            <td><?= $uploadHasCurrency ? adminEscape(rtrim(rtrim(number_format((float) $upload['rate_to_usd'], 6, '.', ''), '0'), '.')) : '<span class="subtext">&mdash;</span>' ?></td>
            <td><?= $uploadHasCurrency ? adminEscape(rtrim(rtrim(number_format((float) $upload['share_percent'], 2, '.', ''), '0'), '.')) . '%' : '<span class="subtext">&mdash;</span>' ?><?= $upload['rate_source'] === 'overridden' ? ' <span class="badge badge-legacy">overridden</span>' : '' ?></td>
            <td><?= number_format((int) $upload['rows_processed']) ?></td><td><?= adminEscape($upload['uploaded_by']) ?></td><td><?= adminEscape($upload['uploaded_at']) ?></td>
            <td><?php if (!$uploadHasCurrency): ?><span class="badge badge-legacy">No currency &mdash; re-upload</span><?php elseif ($itemCount > 0): ?><span class="badge badge-exact">Exactly reversible</span><?php elseif ($legacySingleMonth): ?><span class="badge badge-legacy">Legacy month</span><?php else: ?><span class="badge badge-locked">Legacy / locked</span><?php endif; ?></td>
            <td><button class="button button-danger report-delete" data-id="<?= (int) $upload['id'] ?>" data-filename="<?= adminEscape($upload['filename']) ?>" <?= $canDelete ? '' : 'disabled title="Multiple legacy uploads in this month cannot be separated safely."' ?>><i class="fas fa-trash"></i> Delete</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <div id="reportDeleteStatus" class="status"></div>
    </div>
  </section>

  <section id="countriesTab" class="tab-pane">
    <?php if (!$settingsReady): ?>
      <div class="card"><div class="warning"><i class="fas fa-triangle-exclamation"></i> The market settings tables are missing. Initialize them here to manage marketplace availability. <div class="action-row" style="margin-top:14px"><button class="button button-primary" id="initializeMarketplaceSettings" type="button">Initialize market settings</button><span id="marketSetupStatus" class="subtext"></span></div></div></div>
    <?php else: ?>
      <?php $out = "adminEscape"; require __DIR__ . "/partials/countries_rates.php"; ?>
    <?php endif; ?>
  </section>
</main>

<div id="editTagModal" class="modal"><div class="modal-card"><h3>Edit Tag</h3><input type="hidden" id="editTagId"><div class="field"><label>Tracking ID</label><input type="text" id="editTrackingId"></div><div class="field"><label>Marketplace</label><input type="text" id="editMarketplace"></div><div class="field"><label>User Email</label><input type="email" id="editUserEmail" readonly></div><div class="action-row"><button class="button button-primary" id="saveTagEdit">Update Tag</button><button class="button button-secondary close-modal">Cancel</button></div></div></div>

<div id="tagCommissionModal" class="modal">
  <div class="modal-card">
    <h3><i class="fas fa-percent"></i> Set commission</h3>
    <p style="margin-bottom:2px;font-weight:750" id="tagCommissionTag"></p>
    <p class="subtext" id="tagCommissionWho" style="margin-bottom:16px"></p>
    <div class="field">
      <label for="tagCommissionValue">Commission on this tag</label>
      <div style="display:flex;align-items:center;gap:8px">
        <input type="number" id="tagCommissionValue" step="0.01" min="0" max="100" style="max-width:140px">
        <span style="font-weight:750">%</span>
      </div>
      <p class="subtext" style="margin-top:8px">
        This applies to this tracking ID in this country only &mdash; the same person can be on a
        different rate for their other tags. Changing it updates every month not yet paid. Months
        already paid keep the amount that was transferred, and the payout sheet points out the difference.
      </p>
    </div>
    <div class="action-row">
      <button class="button button-primary" id="saveTagCommission"><i class="fas fa-save"></i> Save Commission</button>
      <button class="button button-secondary close-modal">Cancel</button>
    </div>
    <div id="tagCommissionStatus" class="status"></div>
  </div>
</div>

<div id="apiModal" class="modal"><div class="modal-card"><h3 id="apiModalTitle">Add API Credential</h3><input type="hidden" id="apiId"><div class="field"><label>Credential ID</label><input type="text" id="apiCredId"></div><div class="field"><label>Credential Secret</label><input type="text" id="apiCredSecret"></div><div class="field"><label>Partner Tag</label><input type="text" id="apiPartnerTag"></div><div class="field"><label>Marketplace</label><input type="text" id="apiMarketplace"></div><div class="field"><label>Version</label><input type="text" id="apiVersion" value="3.1"></div><div class="action-row"><button class="button button-primary" id="saveApi">Save</button><button class="button button-secondary close-modal">Cancel</button></div></div></div>

<div id="productModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="productModalTitle">
  <div class="modal-card">
    <h3 id="productModalTitle">Add product</h3>
    <p class="subtext" style="margin:-8px 0 18px">Search saved catalog products, choose an assigned tracking ID, and create a public product post.</p>
    <input type="hidden" id="productId">
    <div id="productPostBuilder">
      <div class="product-form-grid">
        <div class="field field-full" id="catalogSearchControls"><label for="catalogQuery">Find a saved product</label><div class="action-row catalog-search-row"><input id="catalogQuery" type="search" maxlength="500" placeholder="Paste Amazon link, ASIN, or enter keywords"><button class="button button-secondary" id="searchCatalogProducts" type="button"><i class="fas fa-search"></i> Search catalog</button></div><p class="subtext">Searches products already saved in the catalog for the selected marketplace.</p></div>
        <div class="field"><label for="productMarketplace">Marketplace filter</label><select id="productMarketplace">
          <option value="">All marketplaces</option>
          <?php foreach ($countrySettings as $country): if (empty($country['is_active'])) continue; ?><option value="<?= adminEscape($country['marketplace']) ?>"><?= adminEscape($country['country_label']) ?> · <?= adminEscape($country['marketplace']) ?></option><?php endforeach; ?>
        </select></div>
        <div class="field field-full"><label for="productTrackingPickerToggle">Affiliate tracking ID</label><input type="hidden" id="productTrackingId" value="">
          <div class="tracking-picker" id="productTrackingPicker">
            <button type="button" class="tracking-picker-trigger" id="productTrackingPickerToggle" aria-expanded="false" aria-controls="productTrackingPickerPanel"><span id="productTrackingSelection">Select an assigned tracking ID</span><i class="fas fa-chevron-down" aria-hidden="true"></i></button>
            <div class="tracking-picker-panel" id="productTrackingPickerPanel" hidden>
              <input class="tracking-picker-search" id="productTrackingSearch" type="search" placeholder="Search tag, marketplace, or user">
              <div id="productTrackingOptions">
                <?php foreach ($allTags as $trackingTag): if (empty($trackingTag['tracking_id']) || empty($trackingTag['marketplace'])) continue; $tagOwner = isset($userNameByEmail[strtolower($trackingTag['user_email'])]) ? $userNameByEmail[strtolower($trackingTag['user_email'])] : $trackingTag['user_email']; ?>
                  <button type="button" class="tracking-picker-option" data-tracking-id="<?= adminEscape($trackingTag['tracking_id']) ?>" data-marketplace="<?= adminEscape(strtolower(trim($trackingTag['marketplace']))) ?>"><?= adminEscape($trackingTag['tracking_id']) ?> · <?= adminEscape($trackingTag['marketplace']) ?> · <?= adminEscape($tagOwner) ?></button>
                <?php endforeach; ?>
              </div>
              <p class="subtext" id="productTrackingNoResults" hidden style="padding:8px">No matching tracking IDs.</p>
            </div>
          </div>
          <p class="subtext" id="productTrackingHint" style="margin-top:6px">Choose a tracking ID assigned for the selected product marketplace.</p></div>
      </div>
      <div id="catalogSearchStatus" class="status" role="status"></div>
      <div id="catalogSearchResults" class="catalog-search-results" aria-live="polite"></div>
      <div id="selectedCatalogProduct" class="catalog-selected" hidden></div>
      <div class="action-row catalog-post-actions"><button class="button button-primary" id="generateProductPost" type="button" disabled><i class="fas fa-link"></i> Generate link &amp; product post</button><button class="button button-secondary close-modal" type="button">Cancel</button></div>
      <div id="productPostResult" class="catalog-post-result" hidden></div>
    </div>
    <div id="productLegacyForm">
    <div class="product-form-grid">
      <div class="field"><label for="productAsin">ASIN</label><input id="productAsin" type="text" maxlength="10" placeholder="B0XXXXXXXX" autocomplete="off"></div>
      <div class="field field-full"><label for="productTitle">Display title <span class="subtext">(optional)</span></label><input id="productTitle" type="text" maxlength="500" placeholder="Uses the Amazon title when blank"></div>
      <div class="field field-full"><label for="productDescription">Description <span class="subtext">(optional)</span></label><textarea id="productDescription" class="product-description" maxlength="20000" placeholder="Uses Amazon product features when blank"></textarea></div>
    </div>
    <div class="warning">Save refreshes the Amazon sourced image, current price, and affiliate product URL for this marketplace.</div>
    <div class="action-row"><button class="button button-primary" id="saveProduct" type="button"><i class="fas fa-rotate"></i> Save &amp; refresh from Amazon</button><button class="button button-secondary close-modal" type="button">Cancel</button></div>
    </div>
    <div id="productModalStatus" class="status"></div>
  </div>
</div>

<div id="manualProductModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="manualProductTitle">
  <div class="modal-card">
    <h3 id="manualProductTitle">Add manual product</h3>
    <input type="hidden" id="manualProductId" value="">
    <p class="subtext" style="margin:-8px 0 18px">Create a public product page with a title and Amazon affiliate link. Image and description are optional.</p>
    <div class="field"><label for="manualTitle">Product title</label><input id="manualTitle" type="text" maxlength="500" placeholder="Enter the product title"></div>
    <div class="field"><label for="manualImageFile">Upload product image (optional)</label><input id="manualImageFile" type="file" accept="image/jpeg,image/png,image/webp"><p class="subtext">JPEG, PNG, or WebP up to 5 MB. Use an image from a permitted source and keep it accurate and unaltered.</p></div>
    <div class="field"><label for="manualImage">Or product image URL (optional)</label><input id="manualImage" type="url" maxlength="2048" placeholder="https://…" autocomplete="url"><p class="subtext">Leave blank to use the default image placeholder.</p></div>
    <div class="field"><label for="manualDescription">Description</label><textarea id="manualDescription" maxlength="20000" rows="5" placeholder="Add a clear, accurate product description"></textarea></div>
    <div class="field"><label for="manualAffiliateUrl">Amazon product / affiliate URL</label><input id="manualAffiliateUrl" type="url" maxlength="2048" placeholder="Paste an Amazon product link or Amazon short link" autocomplete="url"><p class="subtext">Full Amazon product URLs and supported Amazon short links are accepted. Keep your approved Associate tag in the URL when available.</p></div>
    <div class="action-row"><button class="button button-primary" id="saveManualProduct" type="button"><i class="fas fa-plus"></i> Add product</button><button class="button button-secondary close-modal" type="button">Cancel</button></div>
    <div id="manualProductStatus" class="status" role="status"></div>
  </div>
</div>

<div id="dummyCommissionModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="dummyCommissionTitle">
  <div class="modal-card">
    <h3 id="dummyCommissionTitle">Displayed commission</h3>
    <p class="subtext" id="dummyCommissionUser" style="margin:-8px 0 18px"></p>
    <input type="hidden" id="dummyCommissionUserId">
    <div class="field">
      <label for="dummyCommissionValue">Commission shown to this user</label>
      <div style="display:flex;align-items:center;gap:8px">
        <input type="number" id="dummyCommissionValue" step="0.01" min="0" max="100" style="max-width:140px">
        <strong>%</strong>
      </div>
      <p class="subtext" style="margin-top:8px">Display-only. It does not change the actual per-tag commission or any payout calculation.</p>
    </div>
    <div class="action-row"><button class="button button-primary" id="saveDummyCommission" type="button"><i class="fas fa-save"></i> Save displayed rate</button><button class="button button-secondary close-modal" type="button">Cancel</button></div>
    <div id="dummyCommissionStatus" class="status"></div>
  </div>
</div>

<script>
const reportCsrfToken = <?= json_encode($_SESSION['report_csrf_token']) ?>;

function showStatus(element, message, success) {
  element.className = 'status ' + (success ? 'success' : 'error');
  element.textContent = message;
}

async function requestJson(url, options) {
  const response = await fetch(url, options);
  const raw = await response.text();
  let data;
  try { data = JSON.parse(raw); } catch (error) { throw new Error('Server returned an invalid response: ' + raw.slice(0, 200)); }
  if (!response.ok || !data.success) throw new Error(data.message || 'Request failed.');
  return data;
}

/* Every guarded action goes through here so the token cannot be forgotten at one call
   site and quietly left unprotected. Pass the payload; the token is added. */
function postGuarded(url, payload) {
  return requestJson(url, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify(Object.assign({csrf_token: reportCsrfToken}, payload))
  });
}

function openTab(tabId, updateHash) {
  var pane = document.getElementById(tabId);
  if (!pane || !pane.classList.contains('tab-pane')) return;

  document.querySelectorAll('.admin-workspace-nav button[data-tab]').forEach(function (button) {
    var selected = button.dataset.tab === tabId;
    button.classList.toggle('active', selected);
    button.setAttribute('aria-pressed', selected ? 'true' : 'false');
    button.tabIndex = selected ? 0 : -1;
  });
  document.querySelectorAll('.tab-pane').forEach(function (tabPane) {
    var selected = tabPane === pane;
    tabPane.classList.toggle('active', selected);
    tabPane.hidden = !selected;
    tabPane.setAttribute('aria-hidden', selected ? 'false' : 'true');
  });
  if (updateHash) {
    var url = new URL(window.location.href);
    url.searchParams.set('tab', tabId);
    url.hash = '';
    history.pushState({ adminTab: tabId }, '', url.pathname + url.search);
  }
}

document.querySelectorAll('.admin-workspace-nav button[data-tab]').forEach(function (button) {
  button.addEventListener('click', function () { openTab(this.dataset.tab, true); });
  button.addEventListener('keydown', function (event) {
    var buttons = Array.from(document.querySelectorAll('.admin-workspace-nav button[data-tab]'));
    var index = buttons.indexOf(this);
    var next = event.key === 'ArrowDown' || event.key === 'ArrowRight' ? (index + 1) % buttons.length :
      event.key === 'ArrowUp' || event.key === 'ArrowLeft' ? (index - 1 + buttons.length) % buttons.length : -1;
    if (next >= 0) { event.preventDefault(); buttons[next].focus(); buttons[next].click(); }
  });
});
function syncAdminTabFromLocation() {
  var url = new URL(window.location.href);
  var requestedTab = url.searchParams.get('tab') || window.location.hash.slice(1);
  var fallbackTab = document.querySelector('.admin-workspace-nav button[data-tab]');
  openTab(requestedTab || (fallbackTab ? fallbackTab.dataset.tab : ''), false);
  // Old bookmarks used #tabId. Convert those in place and clear the fragment so the
  // browser cannot scroll the page down to the hidden tab's DOM position.
  if (window.location.hash && document.getElementById(window.location.hash.slice(1))?.classList.contains('tab-pane')) {
    url.searchParams.set('tab', window.location.hash.slice(1));
    url.hash = '';
    history.replaceState({ adminTab: requestedTab }, '', url.pathname + url.search);
    window.scrollTo(0, 0);
  }
}
if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
{
  var navToggle = document.getElementById('adminNavToggle');
  var navRail = document.getElementById('adminSidebar');
  var navBackdrop = document.getElementById('adminNavBackdrop');
  if (navToggle && navRail && navBackdrop) {
    function closeAdminNav() {
      var wasOpen = document.body.classList.contains('admin-nav-open');
      document.body.classList.remove('admin-nav-open');
      navToggle.setAttribute('aria-expanded', 'false');
      navToggle.setAttribute('aria-label', 'Open admin navigation');
      if (wasOpen && window.matchMedia('(max-width: 700px)').matches) navToggle.focus();
    }
    navToggle.addEventListener('click', function () {
      var navOpen = document.body.classList.toggle('admin-nav-open');
      navToggle.setAttribute('aria-expanded', navOpen ? 'true' : 'false');
      navToggle.setAttribute('aria-label', navOpen ? 'Close admin navigation' : 'Open admin navigation');
      if (navOpen && window.matchMedia('(max-width: 700px)').matches) {
        var firstNavItem = navRail.querySelector('.tab-button');
        if (firstNavItem) firstNavItem.focus();
      }
    });
    navBackdrop.addEventListener('click', closeAdminNav);
    navRail.addEventListener('click', function (event) {
      if (event.target.closest('.tab-button')) closeAdminNav();
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') closeAdminNav();
    });
  }
}
function reloadAdminTab(tabId) {
  var url = new URL(window.location.href);
  url.searchParams.set('tab', tabId);
  url.hash = '';
  history.replaceState({ adminTab: tabId }, '', url.pathname + url.search);
  window.scrollTo(0, 0);
  window.location.reload();
}
window.addEventListener('popstate', syncAdminTabFromLocation);
window.addEventListener('hashchange', syncAdminTabFromLocation);
syncAdminTabFromLocation();

document.getElementById('assignTagForm').addEventListener('submit', async function (event) {
  event.preventDefault();
  const status = document.getElementById('tagStatus');
  try {
    const data = await postGuarded('actions/assign_tag.php', { email: document.getElementById('assignEmail').value, tag: document.getElementById('assignTag').value, marketplace: document.getElementById('assignMarketplace').value, share: document.getElementById('assignShare').value });
    showStatus(status, data.message, true); setTimeout(function () { window.location.reload(); }, 700);
  } catch (error) { showStatus(status, error.message, false); }
});

document.querySelectorAll('.assign-user-tag').forEach(function (button) { button.addEventListener('click', function () { document.getElementById('assignEmail').value = this.dataset.email; openTab('createTagTab', true); }); });
document.querySelectorAll('.edit-tag').forEach(function (button) { button.addEventListener('click', function () { const tag=JSON.parse(this.dataset.tag); document.getElementById('editTagId').value=tag.id; document.getElementById('editTrackingId').value=tag.tracking_id; document.getElementById('editMarketplace').value=tag.marketplace; document.getElementById('editUserEmail').value=tag.user_email; document.getElementById('editTagModal').classList.add('open'); }); });
document.querySelectorAll('.edit-dummy-commission').forEach(function (button) {
  button.addEventListener('click', function () {
    document.getElementById('dummyCommissionUserId').value = this.dataset.id;
    document.getElementById('dummyCommissionUser').textContent = this.dataset.name;
    document.getElementById('dummyCommissionValue').value = this.dataset.value;
    document.getElementById('dummyCommissionStatus').className = 'status';
    document.getElementById('dummyCommissionStatus').textContent = '';
    document.getElementById('dummyCommissionModal').classList.add('open');
    document.getElementById('dummyCommissionValue').focus();
    document.getElementById('dummyCommissionValue').select();
  });
});
document.getElementById('saveDummyCommission').addEventListener('click', async function () {
  const status = document.getElementById('dummyCommissionStatus');
  try {
    const result = await postGuarded('actions/save_user_rate_visibility.php', {
      id: Number(document.getElementById('dummyCommissionUserId').value),
      dummy: document.getElementById('dummyCommissionValue').value
    });
    showStatus(status, result.message, true);
    setTimeout(function () { reloadAdminTab('usersTab'); }, 700);
  } catch (error) { showStatus(status, error.message, false); }
});
document.getElementById('saveTagEdit').addEventListener('click', async function () { try { await postGuarded('actions/update_tag.php',{id:document.getElementById('editTagId').value,tracking_id:document.getElementById('editTrackingId').value,marketplace:document.getElementById('editMarketplace').value}); window.location.reload(); } catch(error){ alert(error.message); } });
document.querySelectorAll('.delete-tag').forEach(function(button){button.addEventListener('click',async function(){if(!confirm('Delete this tag permanently?'))return;try{await postGuarded('actions/delete_tag.php',{id:Number(this.dataset.id)});window.location.reload();}catch(error){alert(error.message);}});});
document.querySelectorAll('.delete-user').forEach(function(button){button.addEventListener('click',async function(){if(!confirm('Delete user "'+this.dataset.email+'" and all assigned tags?'))return;try{await postGuarded('actions/delete_user.php',{id:Number(this.dataset.id)});window.location.reload();}catch(error){alert(error.message);}});});

function resetApiModal(){document.getElementById('apiModalTitle').textContent='Add API Credential';['apiId','apiCredId','apiCredSecret','apiPartnerTag','apiMarketplace'].forEach(function(id){document.getElementById(id).value='';});document.getElementById('apiVersion').value='3.1';document.getElementById('apiModal').classList.add('open');}
document.getElementById('addApi').addEventListener('click',resetApiModal);
document.querySelectorAll('.edit-api').forEach(function(button){button.addEventListener('click',function(){const api=JSON.parse(this.dataset.api);document.getElementById('apiModalTitle').textContent='Edit API Credential';document.getElementById('apiId').value=api.id;document.getElementById('apiCredId').value=api.credential_id;document.getElementById('apiCredSecret').value=api.credential_secret;document.getElementById('apiPartnerTag').value=api.partner_tag;document.getElementById('apiMarketplace').value=api.marketplace;document.getElementById('apiVersion').value=api.version||'3.1';document.getElementById('apiModal').classList.add('open');});});
document.getElementById('saveApi').addEventListener('click',async function(){const id=document.getElementById('apiId').value;try{await postGuarded(id?'actions/update_api.php':'actions/save_api.php',{id:id,credential_id:document.getElementById('apiCredId').value,credential_secret:document.getElementById('apiCredSecret').value,partner_tag:document.getElementById('apiPartnerTag').value,marketplace:document.getElementById('apiMarketplace').value,version:document.getElementById('apiVersion').value});window.location.reload();}catch(error){alert(error.message);}});
document.querySelectorAll('.delete-api').forEach(function(button){button.addEventListener('click',async function(){if(!confirm('Delete these API credentials?'))return;try{await postGuarded('actions/delete_api.php',{id:Number(this.dataset.id)});window.location.reload();}catch(error){alert(error.message);}});});

document.querySelectorAll('.close-modal').forEach(function(button){button.addEventListener('click',function(){this.closest('.modal').classList.remove('open');});});
const addManualProductButton = document.getElementById('addManualProduct');
if (addManualProductButton) addManualProductButton.addEventListener('click', function () {
  ['manualTitle','manualImage','manualDescription','manualAffiliateUrl','manualImageFile'].forEach(function (id) { document.getElementById(id).value = ''; });
  document.getElementById('manualProductId').value = '';
  document.getElementById('manualProductTitle').textContent = 'Add manual product';
  document.getElementById('saveManualProduct').innerHTML = '<i class="fas fa-plus"></i> Add product';
  const status = document.getElementById('manualProductStatus'); status.textContent = ''; status.className = 'status';
  document.getElementById('manualProductModal').classList.add('open');
  document.getElementById('manualTitle').focus();
});
document.getElementById('saveManualProduct').addEventListener('click', async function () {
  const button = this;
  const status = document.getElementById('manualProductStatus');
  button.disabled = true;
  try {
    const form = new FormData();
    form.append('csrf_token', reportCsrfToken);
    form.append('id', document.getElementById('manualProductId').value);
    form.append('title', document.getElementById('manualTitle').value);
    form.append('image_url', document.getElementById('manualImage').value);
    form.append('description', document.getElementById('manualDescription').value);
    form.append('affiliate_url', document.getElementById('manualAffiliateUrl').value);
    if (document.getElementById('manualImageFile').files[0]) form.append('image_file', document.getElementById('manualImageFile').files[0]);
    const isEdit = document.getElementById('manualProductId').value !== '';
    const result = await requestJson(isEdit ? 'actions/update_manual_product.php' : 'actions/save_manual_product.php', {method:'POST', body:form});
    status.className = 'status success';
    status.textContent = result.message + ' Public page: ' + result.product_url;
    window.setTimeout(function () { window.location.reload(); }, 1000);
  } catch (error) {
    status.className = 'status error';
    status.textContent = error.message;
  } finally { button.disabled = false; }
});
document.querySelectorAll('.edit-manual-product').forEach(function (button) {
  button.addEventListener('click', function () {
    const product = JSON.parse(this.dataset.product);
    document.getElementById('manualProductId').value = product.id;
    document.getElementById('manualProductTitle').textContent = 'Edit manual product';
    document.getElementById('manualTitle').value = product.title || '';
    document.getElementById('manualImage').value = product.image || '';
    document.getElementById('manualDescription').value = product.description || '';
    document.getElementById('manualAffiliateUrl').value = product.affiliate_link || '';
    document.getElementById('manualImageFile').value = '';
    document.getElementById('saveManualProduct').innerHTML = '<i class="fas fa-save"></i> Save changes';
    const status = document.getElementById('manualProductStatus'); status.textContent = ''; status.className = 'status';
    document.getElementById('manualProductModal').classList.add('open');
    document.getElementById('manualTitle').focus();
  });
});
document.querySelectorAll('.delete-manual-product').forEach(function (button) {
  button.addEventListener('click', async function () {
    if (!confirm('Delete manual product "' + this.dataset.title + '"? This cannot be undone.')) return;
    try { await postGuarded('actions/delete_manual_product.php', {id: Number(this.dataset.id)}); window.location.reload(); }
    catch (error) { alert(error.message); }
  });
});
document.querySelectorAll('.modal').forEach(function(modal){modal.addEventListener('click',function(event){if(event.target===this)this.classList.remove('open');});});

/* The one-click upload form was removed: an import now needs a month, a country, a
   rate and a share, which upload-month.php collects. */

/* ---- per-tag commission ----
   Commission belongs to the assignment and is set here, on the Create TAG screen. The
   Users & Tags tab only reports the combined figure, so it has no editor of its own. */
let commissionTagId = null;

document.querySelectorAll('.set-tag-commission').forEach(function (button) {
  button.addEventListener('click', function () {
    commissionTagId = Number(this.dataset.id);
    document.getElementById('tagCommissionTag').textContent = this.dataset.tag;
    document.getElementById('tagCommissionWho').textContent = this.dataset.who + ' · ' + this.dataset.country;
    document.getElementById('tagCommissionValue').value = this.dataset.share;
    document.getElementById('tagCommissionStatus').className = 'status';
    document.getElementById('tagCommissionStatus').textContent = '';
    document.getElementById('tagCommissionModal').classList.add('open');
    document.getElementById('tagCommissionValue').focus();
    document.getElementById('tagCommissionValue').select();
  });
});

document.getElementById('saveTagCommission').addEventListener('click', async function () {
  const status = document.getElementById('tagCommissionStatus');
  const value = document.getElementById('tagCommissionValue').value;
  const original = this.innerHTML;
  this.disabled = true;
  this.textContent = 'Saving...';
  try {
    const data = await postGuarded('actions/save_tag_commission.php', {id: commissionTagId, share: value});
    showStatus(status, data.message, true);
    // The reply spells out what changed month by month — long enough to want reading
    // before the page reloads under it.
    setTimeout(function () { reloadAdminTab('createTagTab'); }, 2800);
  } catch (error) {
    showStatus(status, error.message, false);
    this.disabled = false;
    this.innerHTML = original;
  }
});

/* Shared by the searchable tables: hide rows that do not match, keep a count. */
function wireTableSearch(inputId, rowSelector, countId, noun) {
  const input = document.getElementById(inputId);
  if (!input) return;
  const rows = document.querySelectorAll(rowSelector);
  const counter = document.getElementById(countId);
  const setCount = function (shown) {
    counter.textContent = shown === rows.length
      ? rows.length + ' ' + noun
      : shown + ' of ' + rows.length + ' ' + noun;
  };
  setCount(rows.length);
  input.addEventListener('input', function () {
    const needle = this.value.trim().toLowerCase();
    let shown = 0;
    rows.forEach(function (row) {
      const match = needle === '' || row.textContent.toLowerCase().indexOf(needle) !== -1;
      row.classList.toggle('hidden', !match);
      if (match) shown++;
    });
    setCount(shown);
  });
}

wireTableSearch('tagListSearch', '.taglist-table tbody tr.taglist-row', 'tagListCount', 'tags');
wireTableSearch('productSearch', '.products-table tbody tr.product-row', 'productCount', 'products');

const productModal = document.getElementById('productModal');
function resetProductModal(product) {
  document.getElementById('productModalTitle').textContent = product ? 'Edit product' : 'Add product';
  document.getElementById('productPostBuilder').hidden = false;
  document.getElementById('catalogSearchControls').hidden = !!product;
  document.getElementById('productLegacyForm').hidden = !product;
  document.getElementById('catalogSearchStatus').textContent = '';
  document.getElementById('catalogSearchStatus').className = 'status';
  document.getElementById('catalogSearchResults').replaceChildren();
  document.getElementById('selectedCatalogProduct').hidden = true;
  document.getElementById('selectedCatalogProduct').textContent = '';
  document.getElementById('productPostResult').hidden = true;
  document.getElementById('productPostResult').replaceChildren();
  document.getElementById('generateProductPost').hidden = !product;
  document.getElementById('generateProductPost').disabled = true;
  document.getElementById('catalogQuery').value = '';
  window.selectedCatalogProduct = null;
  document.getElementById('productId').value = product ? product.id : '';
  document.getElementById('productAsin').value = product ? product.asin : '';
  document.getElementById('productMarketplace').value = product ? product.marketplace : '';
  document.getElementById('productTitle').value = product ? product.title : '';
  document.getElementById('productDescription').value = product ? product.description : '';
  let selectedTag = '';
  if (product && product.affiliate_link) {
    try { selectedTag = new URL(product.affiliate_link).searchParams.get('tag') || ''; } catch (error) {}
  }
  document.getElementById('productTrackingId').value = selectedTag;
  document.getElementById('productTrackingSearch').value = '';
  document.getElementById('productTrackingPickerPanel').hidden = true;
  document.getElementById('productTrackingPickerToggle').setAttribute('aria-expanded', 'false');
  filterProductTrackingIds();
  const status = document.getElementById('productModalStatus'); status.className = 'status'; status.textContent = '';
  productModal.classList.add('open');
  (product ? document.getElementById('productAsin') : document.getElementById('catalogQuery')).focus();
}
function filterProductTrackingIds() {
  const marketplace = (document.getElementById('productMarketplace').value || '').trim().toLowerCase();
  const selectedInput = document.getElementById('productTrackingId');
  const hint = document.getElementById('productTrackingHint');
  const options = Array.from(document.querySelectorAll('.tracking-picker-option'));
  let matchCount = 0;
  let currentStillValid = false;
  options.forEach(function (option) {
    const matches = option.dataset.marketplace === marketplace;
    option.disabled = !matches;
    if (matches) matchCount++;
    if (matches && option.dataset.trackingId === selectedInput.value) currentStillValid = true;
  });
  if (!currentStillValid) selectedInput.value = '';
  const selectedOption = options.find(function (option) { return option.dataset.trackingId === selectedInput.value && option.dataset.marketplace === marketplace; });
  document.getElementById('productTrackingSelection').textContent = selectedOption ? selectedOption.textContent.trim() : 'Select an assigned tracking ID';
  hint.textContent = matchCount
    ? matchCount + ' tracking ID option' + (matchCount === 1 ? '' : 's') + ' available for this marketplace. Other users’ IDs remain visible but cannot be selected.'
    : 'No tracking IDs are assigned for this marketplace yet. Assign a matching ID under Users & tags first.';
  hint.classList.toggle('warning', matchCount === 0);
  filterProductTrackingSearch();
}
function filterProductTrackingSearch() {
  const query = document.getElementById('productTrackingSearch').value.trim().toLowerCase();
  let visible = 0;
  document.querySelectorAll('.tracking-picker-option').forEach(function (option) {
    const matches = !query || option.textContent.toLowerCase().includes(query);
    option.hidden = !matches;
    if (matches) visible++;
  });
  document.getElementById('productTrackingNoResults').hidden = visible > 0;
}
document.getElementById('productMarketplace').addEventListener('change', filterProductTrackingIds);
document.getElementById('productTrackingPickerToggle').addEventListener('click', function () {
  const panel = document.getElementById('productTrackingPickerPanel');
  panel.hidden = !panel.hidden;
  this.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
  if (!panel.hidden) document.getElementById('productTrackingSearch').focus();
});
document.getElementById('productTrackingSearch').addEventListener('input', filterProductTrackingSearch);
document.querySelectorAll('.tracking-picker-option').forEach(function (option) {
  option.addEventListener('click', function () {
    if (this.disabled) return;
    document.getElementById('productTrackingId').value = this.dataset.trackingId;
    document.getElementById('productTrackingSelection').textContent = this.textContent.trim();
    document.getElementById('productTrackingPickerPanel').hidden = true;
    document.getElementById('productTrackingPickerToggle').setAttribute('aria-expanded', 'false');
    const generateButton = document.getElementById('generateProductPost');
    if (window.selectedCatalogProduct) generateButton.disabled = false;
  });
});
const addProductButton = document.getElementById('addProduct');
if (addProductButton) addProductButton.addEventListener('click', function () { resetProductModal(null); });
document.querySelectorAll('.edit-product').forEach(function (button) {
  button.addEventListener('click', function () {
    try { resetProductModal(JSON.parse(this.dataset.product)); } catch (error) { alert('Could not read this product. Refresh the page and try again.'); }
  });
});
const saveProductButton = document.getElementById('saveProduct');
if (saveProductButton) saveProductButton.addEventListener('click', async function () {
  const status = document.getElementById('productModalStatus');
  this.disabled = true;
  try {
    const result = await postGuarded('actions/save_product.php', {
      id: document.getElementById('productId').value,
      asin: document.getElementById('productAsin').value,
      marketplace: document.getElementById('productMarketplace').value,
      tracking_id: document.getElementById('productTrackingId').value,
      title: document.getElementById('productTitle').value,
      description: document.getElementById('productDescription').value
    });
    showStatus(status, result.message, true);
    setTimeout(function () { reloadAdminTab('productsTab'); }, 800);
  } catch (error) { showStatus(status, error.message, false); this.disabled = false; }
});
let catalogSearchSequence = 0;
function renderCatalogProducts(products) {
  const container = document.getElementById('catalogSearchResults');
  container.replaceChildren();
  products.forEach(function (product) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'catalog-product-option';
    button.setAttribute('aria-pressed', 'false');
    if (product.image) {
      const image = document.createElement('img'); image.src = product.image; image.alt = ''; image.loading = 'lazy'; button.appendChild(image);
    }
    const copy = document.createElement('span'); copy.className = 'catalog-product-copy';
    const title = document.createElement('strong'); title.textContent = product.title || 'Untitled product';
    const meta = document.createElement('small'); meta.textContent = 'ASIN ' + product.asin + ' · ' + product.marketplace;
    const price = document.createElement('small'); price.textContent = product.price ? String(product.price) + (product.price_refreshed_at ? ' · fetched ' + product.price_refreshed_at + ' UTC' : '') : 'No current API price';
    copy.append(title, meta, price); button.appendChild(copy);
    button.addEventListener('click', function () {
      document.querySelectorAll('.catalog-product-option').forEach(function (item) { item.setAttribute('aria-pressed', 'false'); });
      button.setAttribute('aria-pressed', 'true');
      window.selectedCatalogProduct = product;
      const marketplace = document.getElementById('productMarketplace');
      marketplace.value = product.marketplace;
      filterProductTrackingIds();
      const selected = document.getElementById('selectedCatalogProduct');
      selected.textContent = 'Selected: ' + (product.title || product.asin) + ' · ASIN ' + product.asin + ' · ' + product.marketplace;
      selected.hidden = false;
      document.getElementById('generateProductPost').disabled = !document.getElementById('productTrackingId').value;
    });
    container.appendChild(button);
  });
}
async function searchCatalogProducts() {
  const query = document.getElementById('catalogQuery').value.trim();
  const status = document.getElementById('catalogSearchStatus');
  if (!query) { showStatus(status, 'Enter an Amazon link, ASIN, or keywords.', false); return; }
  const sequence = ++catalogSearchSequence;
  const button = document.getElementById('searchCatalogProducts'); button.disabled = true;
  document.getElementById('catalogSearchResults').replaceChildren();
  document.getElementById('selectedCatalogProduct').hidden = true;
  document.getElementById('generateProductPost').disabled = true;
  window.selectedCatalogProduct = null;
  showStatus(status, 'Searching saved products…', true);
  try {
    const result = await postGuarded('actions/search_catalog_products.php', {query: query, marketplace: document.getElementById('productMarketplace').value});
    if (sequence !== catalogSearchSequence) return;
    renderCatalogProducts(result.products || []);
    if (!result.products || !result.products.length) showStatus(status, 'No matching saved products for this marketplace. Add the product to the catalog first, then search again.', false);
    else { status.className = 'status'; status.textContent = result.products.length + ' saved product' + (result.products.length === 1 ? '' : 's') + ' found. Select one to continue.'; }
  } catch (error) { if (sequence === catalogSearchSequence) showStatus(status, error.message, false); }
  finally { button.disabled = false; }
}
document.getElementById('searchCatalogProducts').addEventListener('click', searchCatalogProducts);
document.getElementById('catalogQuery').addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); searchCatalogProducts(); } });
document.getElementById('generateProductPost').addEventListener('click', async function () {
  const product = window.selectedCatalogProduct;
  const trackingId = document.getElementById('productTrackingId').value;
  const status = document.getElementById('catalogSearchStatus');
  if (!product || !trackingId) { showStatus(status, 'Select a product and its marketplace tracking ID first.', false); return; }
  this.disabled = true;
  try {
    const result = await postGuarded('actions/create_product_post.php', {product_id: product.id, tracking_id: trackingId});
    const panel = document.getElementById('productPostResult'); panel.replaceChildren(); panel.hidden = false;
    const heading = document.createElement('strong'); heading.textContent = result.message;
    const url = document.createElement('input'); url.type = 'url'; url.readOnly = true; url.value = result.post_url; url.setAttribute('aria-label', 'Generated product post URL');
    const links = document.createElement('div'); links.className = 'action-row';
    const copy = document.createElement('button'); copy.type = 'button'; copy.className = 'button button-secondary'; copy.textContent = 'Copy product page link';
    copy.addEventListener('click', async function () { try { await navigator.clipboard.writeText(result.post_url); copy.textContent = 'Copied'; } catch (error) { url.select(); document.execCommand('copy'); copy.textContent = 'Copied'; } });
    const open = document.createElement('a'); open.className = 'button button-primary'; open.href = result.post_url; open.target = '_blank'; open.rel = 'noopener'; open.textContent = 'Open product post';
    links.append(copy, open); panel.append(heading, url, links);
    showStatus(status, result.message, true);
    this.hidden = true;
  } catch (error) { showStatus(status, error.message, false); this.disabled = false; }
});
document.querySelectorAll('.delete-product').forEach(function (button) {
  button.addEventListener('click', async function () {
    const sharedCount = Number(this.closest('tr').children[3].textContent.trim());
    const prompt = sharedCount > 0
      ? 'Delete this product and all ' + sharedCount + ' associated tracked link(s)? This cannot be undone.'
      : 'Delete “' + this.dataset.title + '” from the catalog? This cannot be undone.';
    if (!confirm(prompt)) return;
    this.disabled = true;
    try { await postGuarded('actions/delete_product.php', {id: Number(this.dataset.id)}); reloadAdminTab('productsTab'); }
    catch (error) { alert(error.message); this.disabled = false; }
  });
});

/* live filter over the user list — 88 people is a lot to scroll for one name */
const userSearch = document.getElementById('userSearch');
if (userSearch) {
  const userRows = document.querySelectorAll('.users-table tbody tr.user-row');
  const userCount = document.getElementById('userCount');
  const setUserCount = function (shown) {
    userCount.textContent = shown === userRows.length
      ? userRows.length + ' users'
      : shown + ' of ' + userRows.length + ' users';
  };
  setUserCount(userRows.length);
  userSearch.addEventListener('input', function () {
    const needle = this.value.trim().toLowerCase();
    let shown = 0;
    userRows.forEach(function (row) {
      const match = needle === '' || row.textContent.toLowerCase().indexOf(needle) !== -1;
      row.classList.toggle('hidden', !match);
      if (match) shown++;
    });
    setUserCount(shown);
  });
}

/* live filter over the tracking table — it can run to 100 rows a page */
const trackingSearch = document.getElementById('trackingSearch');
if (trackingSearch) {
  const trackingRows = document.querySelectorAll('.analytics-table tbody tr');
  const trackingCount = document.getElementById('trackingCount');
  const updateCount = function (shown) {
    trackingCount.textContent = shown === trackingRows.length
      ? trackingRows.length + ' rows'
      : shown + ' of ' + trackingRows.length + ' rows';
  };
  updateCount(trackingRows.length);
  trackingSearch.addEventListener('input', function () {
    const needle = this.value.trim().toLowerCase();
    let shown = 0;
    trackingRows.forEach(function (row) {
      const match = needle === '' || row.textContent.toLowerCase().indexOf(needle) !== -1;
      row.style.display = match ? '' : 'none';
      if (match) shown++;
    });
    updateCount(shown);
  });
}

document.querySelectorAll('.assign-orphan').forEach(function (button) {
  button.addEventListener('click', function () {
    openTab('createTagTab', true);
    const field = document.getElementById('assignTag');
    field.value = this.dataset.tag;
    document.getElementById('assignEmail').focus();
  });
});

/* the payout sheet links here with ?assign_tag=... so the field arrives filled in */
const assignParam = new URLSearchParams(window.location.search).get('assign_tag');
if (assignParam) {
  openTab('createTagTab', false);
  document.getElementById('assignTag').value = assignParam;
  document.getElementById('assignEmail').focus();
}

document.querySelectorAll('.open-countries-tab').forEach(function (link) {
  link.addEventListener('click', function (event) { event.preventDefault(); openTab('countriesTab', true); });
});
document.querySelectorAll('.open-create-tag-tab').forEach(function (link) {
  link.addEventListener('click', function (event) { event.preventDefault(); openTab('createTagTab', true); });
});

/* ---- countries & rates ---- */
const settingsCsrfToken = <?= json_encode($_SESSION['settings_csrf_token']) ?>;

const initializeMarketsButton = document.getElementById('initializeMarketplaceSettings');
if (initializeMarketsButton) {
  initializeMarketsButton.addEventListener('click', async function () {
    this.disabled = true;
    this.textContent = 'Setting up…';
    const status = document.getElementById('marketSetupStatus');
    try {
      const response = await fetch('actions/initialize_market_settings.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({csrf_token: settingsCsrfToken})
      });
      const data = await response.json();
      if (!response.ok || !data.success) throw new Error(data.message || 'Setup failed.');
      status.textContent = data.message;
      reloadAdminTab('countriesTab');
    } catch (error) {
      status.textContent = error.message;
      this.disabled = false;
      this.textContent = 'Try setup again';
    }
  });
}

async function saveSettings(payload, statusId, button) {
  const status = document.getElementById(statusId);
  const original = button.innerHTML;
  button.disabled = true; button.textContent = 'Saving...';
  try {
    const data = await requestJson('actions/save_settings.php', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify(Object.assign({csrf_token: settingsCsrfToken}, payload))
    });
    showStatus(status, data.message, true);
    setTimeout(function () { reloadAdminTab('countriesTab'); }, 1100);
  } catch (error) {
    showStatus(status, error.message, false);
    button.disabled = false; button.innerHTML = original;
  }
}

const saveDefaultButton = document.getElementById('saveDefaultCommission');
if (saveDefaultButton) {
  saveDefaultButton.addEventListener('click', function () {
    saveSettings({default_commission: document.getElementById('defaultCommission').value},
                 'defaultCommissionStatus', this);
  });
}

const saveCountriesButton = document.getElementById('saveCountries');
if (saveCountriesButton) {
  saveCountriesButton.addEventListener('click', function () {
    const countries = Array.prototype.map.call(document.querySelectorAll('tr[data-marketplace]'), function (row) {
      return {
        marketplace: row.dataset.marketplace,
        share_percent: row.querySelector('.share-input').value,
        is_active: row.querySelector('.active-input').checked ? 1 : 0
      };
    });
    saveSettings({countries: countries}, 'countryStatus', this);
  });
}

const saveRatesButton = document.getElementById('saveRates');
if (saveRatesButton) {
  saveRatesButton.addEventListener('click', function () {
    const rates = Array.prototype.filter.call(document.querySelectorAll('tr[data-currency]'), function (row) {
      return row.querySelector('.rate-input');
    }).map(function (row) {
      return {
        currency: row.dataset.currency,
        rate_to_usd: row.querySelector('.rate-input').value,
        quoted_for: row.querySelector('.date-input').value
      };
    });
    saveSettings({rates: rates}, 'rateStatus', this);
  });
}

document.querySelectorAll('.report-delete').forEach(function (button) {
  button.addEventListener('click', function () {
    if (!confirm('Delete "' + this.dataset.filename + '"?\n\nThe rows this file added will be removed from the reports and from every affected user\'s earnings.')) return;
    deleteUpload(this, {});
  });
});

async function deleteUpload(button, confirmations) {
  const status = document.getElementById('reportDeleteStatus');
  button.disabled = true;
  button.textContent = 'Deleting...';
  const payload = Object.assign({upload_id: Number(button.dataset.id), csrf_token: reportCsrfToken}, confirmations);

  try {
    const response = await fetch('actions/delete_report.php', {
      method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)
    });
    const raw = await response.text();
    let data;
    try { data = JSON.parse(raw); }
    catch (error) { throw new Error('Server returned an invalid response: ' + raw.slice(0, 200)); }

    // A month somebody has already been paid for needs saying yes twice.
    if (response.status === 409 && data.needs_confirm) {
      if (window.confirm(data.message)) {
        button.disabled = false;
        return deleteUpload(button, {confirm_paid: 1});
      }
      throw new Error('Delete cancelled — nothing was changed.');
    }

    if (!response.ok || !data.success) throw new Error(data.message || 'Delete failed.');
    showStatus(status, data.message, true);
    setTimeout(function () { reloadAdminTab('reportsTab'); }, 900);
  } catch (error) {
    showStatus(status, error.message, false);
    button.disabled = false;
    button.innerHTML = '<i class="fas fa-trash"></i> Delete';
  }
}
</script>
</body>
</html>
