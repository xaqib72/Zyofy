<?php
/**
 * Upload a month — one Amazon Associates file, for one country, for one month.
 *
 * The country and currency are read out of the file where that is possible. The
 * rate and share shown are the ones about to be frozen onto every row.
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

require_once __DIR__ . '/lib/countries.php';

$setupError = '';
$countries  = array();

try {
    $countries = dd_active_countries($pdo);
} catch (Throwable $error) {
    error_log('upload-month.php: ' . $error->getMessage());
    $setupError = 'The countries table is missing. Run migrations/001_currency_and_share.php once, then reload.';
}

/* Year and month are picked separately — it is how people say a date, and it keeps the
 * list short instead of scrolling eighteen "August 2026" rows.
 *
 * The month we are in has to be available: reports are pulled mid-month, and a file
 * downloaded on the 4th holds the 1st to the 3rd. The default is still last month,
 * because that is what gets uploaded the day a month closes. */
$thisYear  = (int) date('Y');
$thisMonth = (int) date('n');

$years = array();
for ($year = $thisYear; $year >= $thisYear - 3; $year--) {
    $years[] = $year;
}

$monthNames = array();
for ($m = 1; $m <= 12; $m++) {
    $monthNames[$m] = date('F', mktime(0, 0, 0, $m, 1, 2000));
}

$previous     = new DateTime('first day of last month');
$defaultYear  = (int) $previous->format('Y');
$defaultMonth = (int) $previous->format('n');

$currencyDefaults = array();
foreach ($countries as $marketplace => $country) {
    $rate = dd_default_rate($pdo, $country['currency']);
    // No share here: commission is the person's, set on Users & Tags, so the upload
    // screen has nothing to say about it.
    $currencyDefaults[$marketplace] = array(
        'country'   => $country['country_label'],
        'currency'  => $country['currency'],
        'rate'      => $rate['rate_to_usd'] !== null ? (float) $rate['rate_to_usd'] : null,
        'confirmed' => (int) $rate['is_confirmed'] === 1,
    );
}

/* Every currency with a stored rate. The currency is chosen on this screen, and
   picking one fills in its rate on its own — so nobody has to look a rate up. */
$currencyNames = array(
    'USD' => 'US dollar',        'EUR' => 'Euro',             'GBP' => 'Pound sterling',
    'CAD' => 'Canadian dollar',  'JPY' => 'Japanese yen',     'INR' => 'Indian rupee',
    'AUD' => 'Australian dollar','BRL' => 'Brazilian real',   'MXN' => 'Mexican peso',
    'SEK' => 'Swedish krona',    'PLN' => 'Polish zloty',     'AED' => 'UAE dirham',
    'SAR' => 'Saudi riyal',      'SGD' => 'Singapore dollar',
);

$currencyOptions = array();
try {
    foreach ($pdo->query('SELECT currency, rate_to_usd, is_confirmed FROM exchange_rates ORDER BY currency') as $row) {
        $code = (string) $row['currency'];
        $currencyOptions[$code] = array(
            'code'      => $code,
            'name'      => isset($currencyNames[$code]) ? $currencyNames[$code] : $code,
            'rate'      => (float) $row['rate_to_usd'],
            'confirmed' => (int) $row['is_confirmed'] === 1,
        );
    }
} catch (Throwable $error) {
    error_log('upload-month.php currencies: ' . $error->getMessage());
}

$pageTitle   = 'Upload a month';
$currentPage = 'upload';
require __DIR__ . '/partials/admin_head.php';
?>

<div class="page-title">
    <h1><i class="fas fa-file-arrow-up"></i>Upload a month</h1>
  <p class="lede">The same Amazon Associates export you download today. Pick the region, the month it is for and the currency, then choose the file.</p>
  </div>

  <?php if ($setupError !== ''): ?>
    <div class="note note-red"><i class="fas fa-triangle-exclamation"></i><div><?= adminOut($setupError) ?></div></div>
  <?php else: ?>

  <section class="card">
    <h2><i class="fas fa-file-import"></i> Monthly data import</h2>
    <p class="sub">Upload the latest Amazon report whenever you refresh it — daily is fine. If this region and month already exists, the newest file automatically replaces that snapshot; it is never added on top, so repeated uploads cannot double earnings.</p>

    <div class="upload-form">
      <div class="fld wide">
        <label for="marketplace">Region</label>
        <select id="marketplace">
          <option value="">Select account / region</option>
          <?php foreach ($countries as $marketplace => $country): ?>
            <option value="<?= adminOut($marketplace) ?>"><?= adminOut($country['country_label']) ?> &mdash; <?= adminOut($marketplace) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="fld">
        <label for="year">Year</label>
        <select id="year">
          <?php foreach ($years as $year): ?>
            <option value="<?= (int) $year ?>"<?= $year === $defaultYear ? ' selected' : '' ?>><?= (int) $year ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fld">
        <label for="month">Month</label>
        <select id="month">
          <?php foreach ($monthNames as $number => $name): ?>
            <option value="<?= (int) $number ?>"<?= $number === $defaultMonth ? ' selected' : '' ?>><?= adminOut($name) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="fld">
        <label for="currency">Currency</label>
        <select id="currency">
          <?php foreach ($currencyOptions as $code => $option): ?>
            <option value="<?= adminOut($code) ?>"><?= adminOut($option['name']) ?> (<?= adminOut($code) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fld">
        <label for="rate">Rate to USD</label>
        <input type="number" id="rate" step="0.000001" min="0.000001">
      </div>

      <div class="fld wide">
        <label for="file">Report file</label>
        <input type="file" id="file" accept=".csv,.xlsx">
      </div>

      <div class="fld wide">
        <button class="btn btn-dark btn-block" id="importBtn">Import monthly report</button>
      </div>
    </div>

    <p class="sub" style="margin-top:14px">
      Choosing a region fills in the currency it normally pays in, and choosing a currency fills in its rate &mdash;
      both can still be typed over for this one file. Whatever is used here is written onto every row of this import
      and never changes again. Rates live on <a href="exchange-rates.php">Countries &amp; rates</a>.
    </p>

    <div id="reading" class="note note-blue" style="display:none"><i class="fas fa-spinner fa-spin"></i><div>Reading the file…</div></div>
    <div id="detect" class="note" style="display:none"></div>
    <div id="currencyWarn" class="note note-amber" style="display:none"></div>
    <div id="rateWarn" class="note note-amber" style="display:none"></div>
    <div id="replaceWarn" class="note note-amber" style="display:none"></div>
    <div class="dim" id="fileInfo" style="font-size:.86rem;margin-top:12px"></div>

    <?php /* A month takes one file per country, so the screen shows how far through it you are. */ ?>
    <div id="progress" class="month-progress" style="display:none"></div>

    <div class="status" id="status" role="status"></div>
    <div id="result" style="display:none"></div>
  </section>

  <div class="note note-blue">
    <i class="fas fa-circle-info"></i>
    <div><b>Daily refresh behaviour.</b> The latest file is the active snapshot for that region and month. Uploading tomorrow replaces today&rsquo;s snapshot instead of adding it, so month-to-date reports stay accurate without duplicates. How far into a running month the file reaches is worked out from the upload date (up to yesterday); a finished month is treated as complete. A month already marked paid still asks for confirmation before it can be refreshed.</div>
  </div>

  <?php endif; ?>
</main>

<script>
const CSRF = <?= json_encode($_SESSION['settings_csrf_token']) ?>;
const COUNTRY = <?= json_encode($currencyDefaults, JSON_UNESCAPED_SLASHES) ?>;
const ACTIVE_COUNTRIES = <?= json_encode(array_map(function ($c) {
    return array('label' => $c['country_label'], 'currency' => $c['currency']);
}, $countries), JSON_UNESCAPED_SLASHES) ?>;
const CURRENCIES = <?= json_encode($currencyOptions, JSON_UNESCAPED_SLASHES) ?>;

const $ = id => document.getElementById(id);
let analysis = null;

function setNote(el, kind, html){
  el.className = 'note note-' + kind;
  el.innerHTML = html;
  el.style.display = 'flex';
}
function hide(el){ el.style.display = 'none'; }

/* The status line is shown by its class, but hide() leaves an inline display:none
   behind — and inline beats the stylesheet, so the class alone would never bring it
   back. Clearing the inline value is what actually makes the message appear. */
function showStatus(kind, text){
  const box = $('status');
  box.className = 'status ' + kind;
  box.textContent = text;
  box.style.display = '';
}
function money(n){ return Number(n).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }

/* Year and month are two fields on screen but one value everywhere else — the month a
   report belongs to. Built here once so nothing has to remember the format. */
function reportMonth(){
  return $('year').value + '-' + String($('month').value).padStart(2, '0');
}
function monthText(){
  return $('month').selectedOptions[0].textContent + ' ' + $('year').value;
}

/* A month that has not started has no report. Rather than let it be picked and refused
   on import, the months ahead of today are switched off in the list. */
function limitMonths(){
  const now = new Date();
  const isThisYear = Number($('year').value) === now.getFullYear();
  const latest = now.getMonth() + 1;
  let moved = false;

  Array.from($('month').options).forEach(option => {
    const ahead = isThisYear && Number(option.value) > latest;
    option.disabled = ahead;
    if (ahead && option.selected) moved = true;
  });

  // The year changed under a month that is now in the future — fall back to the newest
  // month that does exist rather than leaving an impossible one selected.
  if (moved) $('month').value = String(latest);
}

/* Region chosen -> the currency it normally pays in drops in.
   Currency chosen -> its rate drops in.
   Both can still be typed over for this one file. */
function applyCountry(){
  const info = COUNTRY[$('marketplace').value];
  if (!info) { hide($('currencyWarn')); refreshReplaceWarning(); return; }

  if ($('currency').dataset.auto !== '0' && CURRENCIES[info.currency]) {
    $('currency').value = info.currency;
    $('currency').dataset.auto = '1';
  }
  applyCurrency();
}

function applyCurrency(){
  const code = $('currency').value;
  const money = CURRENCIES[code];
  const country = COUNTRY[$('marketplace').value];

  if (money && ($('rate').value === '' || $('rate').dataset.auto === '1')) {
    $('rate').value = money.rate;
    $('rate').dataset.auto = '1';
  }

  if (!money) {
    setNote($('rateWarn'), 'red', '<i class="fas fa-triangle-exclamation"></i><div><b>No rate is stored for ' + code + '.</b> Add one on the Countries &amp; rates screen, or type it here for this file.</div>');
  } else if (!money.confirmed) {
    setNote($('rateWarn'), 'amber', '<i class="fas fa-triangle-exclamation"></i><div><b>The ' + code + ' rate has not been confirmed yet.</b> ' + money.rate + ' is an indicative starting figure. Check it before importing — a wrong rate mis-pays everybody in this currency.</div>');
  } else {
    hide($('rateWarn'));
  }

  // Choosing a currency the country does not normally pay in is allowed, but it is
  // the kind of slip that quietly mis-prices a whole month, so it gets said out loud.
  if (country && code && code !== country.currency) {
    setNote($('currencyWarn'), 'amber',
      '<i class="fas fa-triangle-exclamation"></i><div><b>' + country.country + ' normally pays in ' + country.currency
      + ', but ' + code + ' is selected.</b> The import will go ahead with ' + code
      + ' — check this is really what the file contains.</div>');
  } else {
    hide($('currencyWarn'));
  }

  refreshReplaceWarning();
}

$('rate').addEventListener('input', () => { $('rate').dataset.auto = '0'; });
$('currency').addEventListener('change', () => { $('currency').dataset.auto = '0'; $('rate').dataset.auto = '1'; applyCurrency(); });
$('marketplace').addEventListener('change', () => { $('currency').dataset.auto = '1'; applyCountry(); analyseAgain(); });
$('year').addEventListener('change', () => { limitMonths(); monthChanged(); });
$('month').addEventListener('change', monthChanged);

function monthChanged(){
  refreshReplaceWarning();
  analyseAgain();
}

limitMonths();

/* Drops trailing zeros only after a decimal point. Trimming them unconditionally turns
   70 into 7 whenever the value arrives without decimals — the same rule as dd_pct() and
   dd_rate() on the server, kept identical so the two cannot disagree. */
function trimZeros(value){
  const text = String(value);
  return text.indexOf('.') === -1 ? text : text.replace(/0+$/, '').replace(/\.$/, '');
}

/* One month needs one file per country, so this shows which have arrived and which
   are still missing. Without it, six files in and you are counting on memory. */
function renderProgress(progress){
  const box = $('progress');
  const entries = Object.entries(ACTIVE_COUNTRIES);
  if (!entries.length) { hide(box); return; }

  // The last day of the month being uploaded — anything short of it is partial. Built
  // from local calendar parts, since day 0 of the next month is the last of this one.
  const year = Number($('year').value), mon = Number($('month').value);
  const last = new Date(year, mon, 0);
  const monthEnd = last.getFullYear() + '-' + String(last.getMonth() + 1).padStart(2,'0')
                 + '-' + String(last.getDate()).padStart(2,'0');

  const done = entries.filter(([mk]) => progress[mk]).length;
  const items = entries.map(function([mk, info]){
    const row = progress[mk];
    if (row) {
      const rate = trimZeros(row.rate_to_usd);
      const partial = row.covers_to && row.covers_to < monthEnd;
      const cover = row.covers_to
        ? (partial
            ? ' &middot; <b class="partial">only to ' + new Date(row.covers_to + 'T00:00:00Z')
                .toLocaleDateString('en-GB',{day:'numeric',month:'short',timeZone:'UTC'}) + '</b>'
            : ' &middot; full month')
        : '';
      return '<li class="' + (partial ? 'in partial-row' : 'in') + '">'
           + '<i class="fas ' + (partial ? 'fa-circle-half-stroke' : 'fa-circle-check') + '"></i> <b>' + info.label + '</b>'
           + ' <span>' + row.currency + ' &middot; rate ' + rate
           + ' &middot; ' + Number(row.rows_processed).toLocaleString() + ' rows' + cover + '</span></li>';
    }
    return '<li class="out"><i class="far fa-circle"></i> <b>' + info.label + '</b>'
         + ' <span>' + info.currency + ' &middot; not uploaded yet</span></li>';
  }).join('');

  box.innerHTML = '<div class="mp-head">' + monthText() + ' &mdash; <b>' + done + ' of ' + entries.length
                + '</b> countries imported</div><ul>' + items + '</ul>';
  box.style.display = 'block';
}

function refreshReplaceWarning(){
  const prev = analysis && analysis.already_imported;
  const country = COUNTRY[$('marketplace').value];

  // Nothing useful to say until a region is picked — the warning is about one country.
  if (!country) { hide($('replaceWarn')); return; }

  if (prev) {
    setNote($('replaceWarn'), 'blue',
      '<i class="fas fa-rotate"></i><div><b>Daily refresh ready.</b> ' + monthText() + ' · ' + country.country +
      ' currently uses "' + prev.filename + '" uploaded on ' + prev.uploaded_at + ' (' +
      Number(prev.rows_processed).toLocaleString() + ' rows). ' +
      'Importing this file will automatically replace that snapshot — it will not add the figures again. ' +
      'Other regions in ' + monthText() + ' stay untouched.' +
      '<br><br>The previous rate is kept by default so a routine refresh does not accidentally re-price the month.</div>');
  } else {
    setNote($('replaceWarn'), 'blue',
      '<i class="fas fa-circle-info"></i><div>' + monthText() + ' · ' + country.country + ' has not been imported yet. This will be a fresh import.</div>');
  }
}

/* ---- read the file ---- */
$('file').addEventListener('change', analyse);
function analyseAgain(){ if ($('file').files.length) analyse(); }

async function analyse(){
  const file = $('file').files[0];
  if (!file) return;
  hide($('detect')); hide($('status'));
  $('reading').style.display = 'flex';

  // A region already chosen is the one being asked about — the "already imported"
  // answer has to be about that country, not whatever the file looks like.
  const chosen = $('marketplace').value;

  const body = new FormData();
  body.append('report_file', file);
  body.append('report_month', reportMonth());
  if (chosen) body.append('marketplace', chosen);

  try {
    const response = await fetch('actions/analyze_report.php', {method:'POST', body});
    const raw = await response.text();
    let data;
    try { data = JSON.parse(raw); }
    catch(e){ throw new Error('Server returned an unexpected response: ' + raw.slice(0,160)); }
    if (!response.ok || !data.success) throw new Error(data.message || 'The file could not be read.');

    analysis = data;
    const s = data.suggestion;

    let headline, kind;
    if (s.conflict) {
      kind = 'red';
      headline = 'The file is named after ' + s.conflict + ', but its headings are ' + s.language + '.';
    } else if (s.certain) {
      kind = 'green';
      headline = 'Read from the file: ' + s.country + ', in ' + s.currency + '.';
    } else if (s.from_filename) {
      kind = 'green';
      headline = 'From the file name: ' + s.country + ', in ' + s.currency + '.';
    } else if (s.language) {
      kind = 'amber';
      headline = s.language + ' headings — ' + (s.country || 'unknown country') + ' assumed.';
    } else {
      kind = 'amber';
      headline = 'The country could not be worked out from this file.';
    }
    setNote($('detect'), kind,
      '<i class="fas ' + (kind === 'green' ? 'fa-circle-check' : 'fa-triangle-exclamation') + '"></i>' +
      '<div><b>' + headline + '</b> ' + data.reason + '</div>');

    // A region picked by hand must survive re-reading the file — the file is only
    // consulted to fill a region that is still empty.
    if (!chosen && s.marketplace && COUNTRY[s.marketplace]) {
      $('marketplace').value = s.marketplace;
    }
    renderProgress(data.month_progress || {});
    $('rate').dataset.auto = '1';
    applyCountry();

    // A re-import defaults to whatever the first import used.
    if (data.already_imported) {
      $('rate').value = data.already_imported.rate_to_usd;
    } else if (data.rate_to_usd !== null) {
      $('rate').value = data.rate_to_usd;
    }

    $('fileInfo').textContent = data.filename + ' · ' + Math.round(data.size_bytes/1024) + ' KB · '
                              + Number(data.data_rows).toLocaleString() + ' tracking IDs';
    refreshReplaceWarning();
  } catch (error) {
    setNote($('detect'), 'red', '<i class="fas fa-triangle-exclamation"></i><div>' + error.message + '</div>');
  } finally {
    hide($('reading'));
  }
}

/* ---- import ---- */
$('importBtn').addEventListener('click', () => runImport({}));

/* Says which field is still empty rather than failing silently, because the button is
   live from the moment the page loads now. */
function whatIsMissing(){
  if (!$('marketplace').value) return 'Choose which region this report is from.';
  if (!$('file').files.length) return 'Choose the report file to import.';
  if (!$('rate').value)        return 'Enter the rate to USD for this file.';
  return '';
}

async function runImport(confirmations){
  const missing = whatIsMissing();
  if (missing) {
    showStatus('bad', missing);
    return;
  }

  const file = $('file').files[0];
  const button = $('importBtn');
  button.disabled = true;
  button.textContent = (analysis && analysis.already_imported) ? 'Refreshing...' : 'Importing...';
  hide($('status')); $('result').style.display = 'none';

  const body = new FormData();
  body.append('csrf_token', CSRF);
  body.append('report_file', file);
  body.append('report_month', reportMonth());
  body.append('marketplace', $('marketplace').value);
  body.append('currency', $('currency').value);
  body.append('rate_to_usd', $('rate').value);
  Object.entries(confirmations).forEach(([k,v]) => body.append(k, v));

  try {
    const response = await fetch('actions/import_report.php', {method:'POST', body});
    const raw = await response.text();
    let data;
    try { data = JSON.parse(raw); }
    catch(e){ throw new Error('Server returned an unexpected response: ' + raw.slice(0,200)); }

    if (response.status === 409 && data.needs_confirm === 'paid') {
      if (window.confirm(data.message)) {
        const next = Object.assign({}, confirmations, {confirm_paid: '1'});
        button.disabled = false;
        return runImport(next);
      }
      throw new Error('Import cancelled — nothing was changed.');
    }

    if (!response.ok || !data.success) throw new Error(data.message || 'The import failed.');

    showStatus('ok', data.message);
    $('result').style.display = 'block';
    $('result').innerHTML =
      '<div class="tw"><table><thead><tr><th>Month</th><th>Region</th><th>Currency</th>' +
      '<th class="r">Rate frozen</th><th class="r">Covers to</th><th class="r">Rows</th>' +
      '<th class="r">Earnings in ' + data.currency + '</th>' +
      '<th class="r">Gross (USD)</th></tr></thead><tbody><tr>' +
      '<td class="name">' + data.report_month + '</td><td>' + data.country + '</td><td>' + data.currency + '</td>' +
      '<td class="r num">' + data.rate_to_usd + (data.rate_source === 'overridden' ? ' <span class="pill pill-amber">overridden</span>' : '') + '</td>' +
      '<td class="r num">' + data.covers_to + (data.partial ? ' <span class="pill pill-amber">partial</span>' : '') + '</td>' +
      '<td class="r num">' + Number(data.rows_processed).toLocaleString() + '</td>' +
      '<td class="r num">' + money(data.earnings_total) + '</td>' +
      '<td class="r num" style="font-weight:700">$' + money(data.gross_usd) + '</td>' +
      '</tr></tbody></table></div>' +
      '<p class="sub" style="margin-top:14px">Gross is the whole file converted to USD. It is not split here &mdash; ' +
      'commission belongs to the person, so what each contributor is paid is worked out from their own rate on the ' +
      '<a href="user-commission.php?month=' + encodeURIComponent(data.report_month) + '">payout sheet</a>.</p>';
    analyseAgain();
  } catch (error) {
    showStatus('bad', error.message);
  } finally {
    button.disabled = false;
    button.textContent = 'Import monthly report';
  }
}
</script>
</body>
</html>
