<?php
/**
 * Countries & rates — the standalone page.
 *
 * The screen itself lives in partials/countries_rates.php, which the dashboard tab
 * also includes. One implementation, two ways in, so a change made here shows up in
 * both and the two can never disagree.
 *
 * What is set here are only the DEFAULTS offered at import. The rate and share a
 * month actually used are copied onto its rows and never read from these tables
 * again — that is what stops a paid month from restating itself.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

if (!isset($_SESSION['settings_csrf_token'])) {
    $_SESSION['settings_csrf_token'] = bin2hex(random_bytes(32));
}

$setupError       = '';
$countrySettings  = array();
$rateSettings     = array();
$tagCountByMarket = array();

try {
    $countrySettings = $pdo->query(
        'SELECT marketplace, country_label, currency, share_percent, is_active
         FROM marketplace_settings ORDER BY is_active DESC, country_label ASC'
    )->fetchAll(PDO::FETCH_ASSOC);

    $rateSettings = $pdo->query(
        'SELECT currency, rate_to_usd, quoted_for, is_confirmed
         FROM exchange_rates ORDER BY currency ASC'
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pdo->query(
        "SELECT marketplace, COUNT(*) AS tags FROM user_tags
         WHERE tracking_id IS NOT NULL AND tracking_id <> '' GROUP BY marketplace"
    ) as $row) {
        $tagCountByMarket[$row['marketplace']] = (int) $row['tags'];
    }
} catch (Throwable $error) {
    error_log('exchange-rates.php: ' . $error->getMessage());
    $setupError = 'Market settings are not initialized yet. Initialize the marketplace and exchange-rate tables here to continue.';
}

$pageTitle   = 'Countries & rates';
$currentPage = 'settings';
require __DIR__ . '/partials/admin_head.php';

$out = 'adminOut';
?>

<div class="page-title">
    <h1><i class="fas fa-earth-americas"></i>Countries &amp; rates</h1>
  <p class="lede">You set both of these yourself. Nothing is pulled from a live feed, so no report ever changes its numbers on its own.</p>
  </div>

  <?php if ($setupError !== ''): ?>
    <div class="note note-red"><i class="fas fa-triangle-exclamation"></i><div><?= adminOut($setupError) ?><div class="action-row" style="margin-top:14px"><button class="btn btn-dark" id="initializeMarketplaceSettings" type="button">Initialize market settings</button><span id="marketSetupStatus" class="sub"></span></div></div></div>
  <?php else: ?>
    <?php require __DIR__ . '/partials/countries_rates.php'; ?>
    <p class="sub" style="margin-top:18px">
      The same two tables sit inside the dashboard under
      <a href="index.php?tab=countriesTab">Countries &amp; Rates</a> — it is the same screen, not a second copy of it.
    </p>
  <?php endif; ?>
</main>

<script>
const CSRF = <?= json_encode($_SESSION['settings_csrf_token']) ?>;

const initializeMarketsButton = document.getElementById('initializeMarketplaceSettings');
if (initializeMarketsButton) {
  initializeMarketsButton.addEventListener('click', async function () {
    this.disabled = true;
    this.textContent = 'Setting up…';
    const status = document.getElementById('marketSetupStatus');
    try {
      const response = await fetch('actions/initialize_market_settings.php', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({csrf_token: CSRF})
      });
      const data = await response.json();
      if (!response.ok || !data.success) throw new Error(data.message || 'Setup failed.');
      status.textContent = data.message;
      window.location.reload();
    } catch (error) {
      status.textContent = error.message;
      this.disabled = false;
      this.textContent = 'Try setup again';
    }
  });
}

function showSettingsStatus(el, message, ok) {
  el.className = 'status ' + (ok ? 'ok' : 'bad');
  el.textContent = message;
}

async function saveSettings(payload, statusId, button) {
  const status = document.getElementById(statusId);
  const original = button.innerHTML;
  button.disabled = true;
  button.textContent = 'Saving...';
  try {
    const response = await fetch('actions/save_settings.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(Object.assign({csrf_token: CSRF}, payload))
    });
    const raw = await response.text();
    let data;
    try { data = JSON.parse(raw); }
    catch (e) { throw new Error('Server returned an unexpected response: ' + raw.slice(0, 160)); }
    if (!response.ok || !data.success) throw new Error(data.message || 'Save failed.');
    showSettingsStatus(status, data.message, true);
    setTimeout(function () { window.location.reload(); }, 1000);
  } catch (error) {
    showSettingsStatus(status, error.message, false);
    button.disabled = false;
    button.innerHTML = original;
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
</script>
</body>
</html>
