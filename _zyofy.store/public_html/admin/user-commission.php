<?php
/**
 * Payout sheet — the owner's view of what to pay this month.
 *
 * Replaces the old user-commission report, which added USD, EUR, GBP and CAD into a
 * single total. The exchange rate is the one frozen onto each row when its month was
 * imported and never changes; the percentage is the commission currently set on the tag
 * that earned it, so an unpaid month follows a rate change and a paid one does not.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/lib/payout.php';

if (!isset($_SESSION['settings_csrf_token'])) {
    $_SESSION['settings_csrf_token'] = bin2hex(random_bytes(32));
}

$setupError   = '';
$reportMonths = array();
$selectedMonth = '';
$payout = null;

$searchTerm = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$sortMode   = (isset($_GET['sort']) && $_GET['sort'] === 'name') ? 'name' : 'payable';
$exportCsv  = isset($_GET['export']) && $_GET['export'] === 'csv';

try {
    $reportMonths = $pdo->query(
        "SELECT DISTINCT report_month FROM tracking_report_monthly
         WHERE report_month IS NOT NULL AND report_month <> '' ORDER BY report_month DESC"
    )->fetchAll(PDO::FETCH_COLUMN);

    $requested = isset($_GET['month']) ? trim((string) $_GET['month']) : '';
    if ($requested === 'all') {
        $selectedMonth = 'all';
    } elseif (preg_match('/^\d{4}-\d{2}$/', $requested) && in_array($requested, $reportMonths, true)) {
        $selectedMonth = $requested;
    } elseif (!empty($reportMonths)) {
        $selectedMonth = (string) $reportMonths[0];
    } else {
        $selectedMonth = 'all';
    }

    $payout = dd_payout_month($pdo, $selectedMonth);
} catch (Throwable $error) {
    error_log('user-commission.php: ' . $error->getMessage());
    $setupError = 'The payout sheet could not be built. If this is the first run, apply migrations/001_currency_and_share.php once from the command line.';
}

$contributors = $payout ? $payout['contributors'] : array();
$unassigned   = $payout ? $payout['unassigned']   : array('payable_usd' => 0, 'tag_count' => 0, 'countries' => array(), 'unpriced' => 0);
$totals       = $payout ? $payout['totals']       : array('total_to_pay' => 0, 'unassigned_usd' => 0, 'unpriced' => 0, 'unpriced_rows' => 0, 'people' => 0);
$issues       = $payout ? $payout['issues']       : array('shared_tags' => array(), 'unpriced_rows' => 0, 'unassigned' => 0);

// Search filters the list on screen; the totals above always cover everybody.
$visible = $contributors;
if ($searchTerm !== '') {
    $needle = mb_strtolower($searchTerm);
    $visible = array();
    foreach ($contributors as $email => $contributor) {
        $haystack = mb_strtolower($contributor['name'] . ' ' . $contributor['email'] . ' ' . implode(' ', array_keys($contributor['tags'])));
        if (mb_strpos($haystack, $needle) !== false) {
            $visible[$email] = $contributor;
        }
    }
}
if ($sortMode === 'name') {
    uasort($visible, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
}

$monthLabel = $selectedMonth === 'all'
    ? 'All months'
    : ($selectedMonth !== '' ? date('F Y', strtotime($selectedMonth . '-01')) : 'No data');

/* ---------------- CSV export ---------------- */
if ($exportCsv && $payout) {
    $filename = 'payout-sheet-' . ($selectedMonth === 'all' ? 'all-months' : $selectedMonth) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fputcsv($out, array('Type', 'Contributor', 'Email', 'Country', 'Currency', 'Tags',
                        'Clicks', 'Shipped', 'Returned', 'Earnings in currency',
                        'Rate to USD', 'Share %', 'Payable USD'));

    foreach ($visible as $contributor) {
        fputcsv($out, array('CONTRIBUTOR', $contributor['name'], $contributor['email'], '',
            implode(' ', $contributor['currencies']), $contributor['tag_count'],
            $contributor['clicks'], $contributor['items_shipped'], $contributor['items_returned'],
            '', '', $contributor['effective_share'] === null ? '' : number_format($contributor['effective_share'], 2, '.', ''),
            number_format($contributor['payable_usd'], 2, '.', '')));

        foreach ($contributor['countries'] as $country) {
            fputcsv($out, array('  country', $contributor['name'], $contributor['email'],
                dd_country_label($pdo, $country['marketplace']), $country['currency'], count($country['tags']),
                $country['clicks'], $country['items_shipped'], $country['items_returned'],
                number_format($country['earnings'], 2, '.', ''),
                rtrim(rtrim(number_format($country['rate'], 6, '.', ''), '0'), '.'),
                number_format($country['share'], 2, '.', ''),
                number_format($country['payable_usd'], 2, '.', '')));
        }
    }

    if ($unassigned['tag_count'] > 0) {
        /* The gross, matching what the screen shows. These tags have no holder, so there
           is no commission to take off — putting a share-reduced figure in the "Payable"
           column made the export contradict the page it came from. */
        fputcsv($out, array('UNASSIGNED (gross, nobody holds these)', 'Nobody yet', '', '', '',
            $unassigned['tag_count'],
            $unassigned['clicks'], $unassigned['items_shipped'], $unassigned['items_returned'],
            '', '', '', number_format($unassigned['gross_usd'], 2, '.', '')));
    }

    fputcsv($out, array('TOTAL TO PAY', '', '', '', '', '', '', '', '', '', '', '',
        number_format($totals['total_to_pay'], 2, '.', '')));
    fclose($out);
    exit;
}

$pageTitle   = 'Payout sheet';
$currentPage = 'payout';
require __DIR__ . '/partials/admin_head.php';

function payoutQuery($overrides = array())
{
    $base = array(
        'month' => isset($_GET['month']) ? $_GET['month'] : '',
        'q'     => isset($_GET['q']) ? $_GET['q'] : '',
        'sort'  => isset($_GET['sort']) ? $_GET['sort'] : '',
    );
    return http_build_query(array_filter(array_merge($base, $overrides), function ($v) { return $v !== '' && $v !== null; }));
}
?>

<div class="page-title">
    <h1><i class="fas fa-hand-holding-dollar"></i>Payout sheet &mdash; <?= adminOut($monthLabel) ?></h1>
  <p class="lede">The owner's view. Each contributor's report opens on its own, in exactly the layout they see when they log in. Contributors never reach this screen.</p>
  </div>

  <?php if ($setupError !== ''): ?>
    <div class="note note-red"><i class="fas fa-triangle-exclamation"></i><div><?= adminOut($setupError) ?></div></div>
  <?php else: ?>

  <section class="card">
    <div class="row">
      <div>
        <h2><i class="fas fa-money-check-dollar"></i> What to pay this month</h2>
        <p class="sub">Every person, every currency they earned in, and the one figure to transfer.</p>
      </div>
      <div class="spacer"></div>
      <form method="get" action="user-commission.php" class="row" style="gap:12px">
        <div class="fld">
          <label for="month">Month</label>
          <select id="month" name="month" onchange="this.form.submit()">
            <?php foreach ($reportMonths as $month): ?>
              <option value="<?= adminOut($month) ?>"<?= $selectedMonth === $month ? ' selected' : '' ?>><?= adminOut(date('F Y', strtotime($month . '-01'))) ?></option>
            <?php endforeach; ?>
            <option value="all"<?= $selectedMonth === 'all' ? ' selected' : '' ?>>All months</option>
          </select>
        </div>
        <div class="fld">
          <label for="q">Search</label>
          <input type="search" id="q" name="q" value="<?= adminOut($searchTerm) ?>" placeholder="name, email or tag">
        </div>
        <div class="fld">
          <label for="sort">Order</label>
          <select id="sort" name="sort" onchange="this.form.submit()">
            <option value="payable"<?= $sortMode === 'payable' ? ' selected' : '' ?>>Highest payable</option>
            <option value="name"<?= $sortMode === 'name' ? ' selected' : '' ?>>Name (A&ndash;Z)</option>
          </select>
        </div>
        <div class="fld"><label>&nbsp;</label><button class="btn" type="submit">Apply</button></div>
      </form>
      <div class="fld"><label>&nbsp;</label><a class="btn btn-dark" href="user-commission.php?<?= adminOut(payoutQuery(array('export' => 'csv'))) ?>">Download CSV</a></div>
    </div>

    <?php if ($searchTerm !== ''): ?>
      <p class="sub" style="margin-top:14px">Showing <?= count($visible) ?> of <?= count($contributors) ?> contributors matching &ldquo;<?= adminOut($searchTerm) ?>&rdquo;. The total below always covers everybody.</p>
    <?php endif; ?>

    <?php $canPay = $selectedMonth !== 'all' && $totals['total_to_pay'] > 0; ?>
    <div class="metrics">
      <div class="metric lead">
        <div class="l">Total to pay</div>
        <div class="v"><?= dd_usd($totals['total_to_pay']) ?></div>
        <div class="n"><?= (int) $totals['people'] ?> contributor<?= (int) $totals['people'] === 1 ? '' : 's' ?> · <?= adminOut($monthLabel) ?></div>
      </div>
      <div class="metric">
        <div class="l">Already paid</div>
        <div class="v"><?= dd_usd($totals['paid_total']) ?></div>
        <div class="n"><?= (int) $totals['paid_people'] ?> marked paid</div>
      </div>
      <div class="metric">
        <div class="l">Still owed</div>
        <?php
        /* A negative figure means more was transferred than the sheet now calculates —
           somebody was overpaid, usually because a commission moved after the month was
           paid. Clamping it to zero and calling that "settled" hid exactly the case the
           admin most needs to see. */
        $owed = (float) $totals['still_owed'];
        ?>
        <div class="v"><?= $owed < -0.005 ? dd_usd(abs($owed)) : dd_usd(max(0, $owed)) ?></div>
        <div class="n"><?php
          if ($owed > 0.005) {
              echo 'not transferred yet';
          } elseif ($owed < -0.005) {
              echo '<b class="partial">paid out more than is owed</b>';
          } else {
              echo 'everyone is settled';
          }
        ?></div>
      </div>
      <div class="metric">
        <div class="l">Unassigned tags</div>
        <div class="v"><?= dd_usd($unassigned['gross_usd']) ?></div>
        <div class="n"><?= (int) $unassigned['tag_count'] ?> tracking ID<?= (int) $unassigned['tag_count'] === 1 ? '' : 's' ?> with no owner</div>
      </div>
    </div>

    <?php if ($canPay): ?>
      <div class="row" style="margin-top:18px">
        <div class="fld">
          <label for="paidOn">Payment date</label>
          <input type="date" id="paidOn" value="<?= adminOut(date('Y-m-d')) ?>">
        </div>
        <div class="fld">
          <label>&nbsp;</label>
          <button class="btn btn-dark" id="payAll"><i class="fas fa-circle-check"></i> Mark everyone paid for <?= adminOut($monthLabel) ?></button>
        </div>
        <?php if ($totals['paid_people'] > 0): ?>
          <div class="fld"><label>&nbsp;</label>
            <button class="btn btn-ghost" id="unpayAll"><i class="fas fa-rotate-left"></i> Undo all payments for this month</button>
          </div>
        <?php endif; ?>
      </div>
    <?php elseif ($selectedMonth === 'all'): ?>
      <div class="note note-blue"><i class="fas fa-circle-info"></i><div>Payments are recorded one month at a time. Pick a single month above to mark anybody paid.</div></div>
    <?php endif; ?>
    <div class="status" id="payStatus" role="status"></div>

    <div class="tw">
      <table>
        <thead>
          <tr>
            <th style="min-width:230px">Contributor</th>
            <th>Tags</th>
            <th>Earned in</th>
            <th class="r">Share</th>
            <th class="r">Payable (USD)</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($visible)): ?>
          <tr><td colspan="6" class="dim" style="text-align:center;padding:34px">No contributor matches this filter.</td></tr>
        <?php endif; ?>

        <?php
        /* Anyone who earned nothing is still on the sheet, but folded away — with 88
           people holding tags, eighty rows of $0.00 bury the two that need paying. */
        $earning = array();
        $idle = array();
        foreach ($visible as $email => $contributor) {
            if ($contributor['payable_usd'] > 0 || $contributor['unpriced'] > 0) {
                $earning[$email] = $contributor;
            } else {
                $idle[$email] = $contributor;
            }
        }
        $ordered = $earning + $idle;
        $idleHeaderShown = false;
        $rowIndex = 0;

        /* The unassigned row is captured here so it can be printed straight after the
           people who earned, and never below the folded-away zero rows. */
        $orphanTagNames = array_keys($unassigned['tags']);
        $firstOrphanTag = !empty($orphanTagNames) ? $orphanTagNames[0] : '';

        ob_start();
        if ($unassigned['tag_count'] > 0):
        ?>
          <tr class="person" data-row="0">
            <td>
              <div class="person-cell">
                <span class="chev">&#9656;</span>
                <span class="avatar avatar-warn">!</span>
                <span>
                  <span class="pname">Nobody yet</span><br>
                  <span class="pill pill-amber" style="margin-top:4px">needs assigning</span>
                </span>
              </div>
            </td>
            <td><span class="pill pill-slate"><?= (int) $unassigned['tag_count'] ?></span></td>
            <td><?= !empty($unassigned['countries']) ? adminOut(implode(' · ', array_unique(array_column($unassigned['countries'], 'currency')))) : '<span class="dim">&mdash;</span>' ?></td>
            <td class="r dim">&mdash;</td>
            <td class="r num" style="font-weight:700"><?= dd_usd($unassigned['gross_usd']) ?></td>
            <td class="r"><a class="btn btn-sm" href="index.php?tab=createTagTab&amp;assign_tag=<?= adminOut($firstOrphanTag) ?>"><i class="fas fa-plus"></i> Assign tags</a></td>
          </tr>
          <?php foreach ($unassigned['countries'] as $country): ?>
            <tr class="detail" data-parent="0">
              <td class="indent"><?= adminOut(dd_country_label($pdo, $country['marketplace'])) ?></td>
              <td><?= count($country['tags']) ?></td>
              <td><?= adminOut(dd_money($country['currency'], $country['earnings'])) ?></td>
              <td class="r dim">&mdash;</td>
              <td class="r num"><?= dd_usd($country['gross_usd']) ?></td>
              <td></td>
            </tr>
          <?php endforeach; ?>
        <?php
        endif;
        $unassignedMarkup = ob_get_clean();
        $unassignedPrinted = false;
        ?>

        <?php foreach ($ordered as $email => $contributor): $rowIndex++; ?>
          <?php
          $isIdle = isset($idle[$email]);
          if ($isIdle && !$idleHeaderShown):
              $idleHeaderShown = true;
              echo $unassignedMarkup;
              $unassignedPrinted = true;
          ?>
            <tr class="idle-header">
              <td colspan="6">
                <button type="button" class="btn btn-sm" id="toggleIdle">
                  Show <?= count($idle) ?> contributor<?= count($idle) === 1 ? '' : 's' ?> with nothing this month
                </button>
                <span class="dim" style="margin-left:10px;font-size:.86rem">They hold tags but no sales were reported against them.</span>
              </td>
            </tr>
          <?php endif; ?>
          <?php
          $initials = '';
          foreach (preg_split('/\s+/', trim($contributor['name'])) as $part) {
              if ($part !== '' && preg_match('/[A-Za-z]/', $part[0])) { $initials .= strtoupper($part[0]); }
          }
          $initials = substr($initials, 0, 2) !== '' ? substr($initials, 0, 2) : '?';
          $shares = array();
          foreach ($contributor['countries'] as $country) {
              $shares[(string) $country['share']] = true;
          }
          ?>
          <tr class="person<?= $isIdle ? ' idle' : '' ?>" data-row="<?= $rowIndex ?>">
            <td>
              <div class="person-cell">
                <span class="chev">&#9656;</span>
                <span class="avatar"><?= adminOut($initials) ?></span>
                <span>
                  <span class="pname"><?= adminOut($contributor['name']) ?></span><br>
                  <span class="pmail"><?= adminOut($contributor['email']) ?></span>
                </span>
              </div>
            </td>
            <td><span class="pill <?= $contributor['tag_count'] > 1 ? 'pill-slate' : 'pill-slate' ?>"><?= (int) $contributor['tag_count'] ?></span></td>
            <td><?= !empty($contributor['currencies']) ? implode(' &middot; ', array_map('adminOut', $contributor['currencies'])) : '<span class="dim">&mdash;</span>' ?></td>
            <td class="r num">
              <?php if ($contributor['effective_share'] === null): ?>
                <span class="dim">&mdash;</span>
              <?php else: ?>
                <?= adminOut(dd_pct($contributor['effective_share'], 1)) ?>
                <?php if (count($shares) > 1): ?>
                  <div class="calc">combined from <?= adminOut(implode(' / ', array_map('dd_pct', array_keys($shares)))) ?></div>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td class="r num" style="font-weight:700">
              <?= dd_usd($contributor['payable_usd']) ?>
              <?php if (!empty($contributor['payment'])): ?>
                <?php if ($contributor['payment']['drifted']): ?>
                  <div class="calc" style="color:var(--red-tx)">paid <?= dd_usd($contributor['payment']['paid_amount']) ?> — no longer matches</div>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td class="r">
              <div style="display:flex;gap:7px;justify-content:flex-end;align-items:center;flex-wrap:wrap">
                <?php if (!empty($contributor['payment'])): ?>
                  <span class="pill pill-green"><i class="fas fa-check"></i> Paid <?= adminOut(date('j M Y', strtotime($contributor['payment']['paid_on']))) ?></span>
                  <?php if ($selectedMonth !== 'all'): ?>
                    <button class="btn btn-sm unpay-one" data-email="<?= adminOut($contributor['email']) ?>" title="Undo this payment"><i class="fas fa-rotate-left"></i></button>
                  <?php endif; ?>
                <?php elseif ($selectedMonth !== 'all' && $contributor['payable_usd'] > 0): ?>
                  <button class="btn btn-sm pay-one" data-email="<?= adminOut($contributor['email']) ?>"><i class="fas fa-circle-check"></i> Mark paid</button>
                <?php endif; ?>
                <a class="btn btn-sm btn-ghost" href="contributor-report.php?<?= adminOut(http_build_query(array('email' => $contributor['email'], 'month' => $selectedMonth))) ?>">Open report</a>
              </div>
            </td>
          </tr>

          <?php foreach ($contributor['countries'] as $country): ?>
            <tr class="detail" data-parent="<?= $rowIndex ?>">
              <td class="indent"><?= adminOut(dd_country_label($pdo, $country['marketplace'])) ?></td>
              <td><?= count($country['tags']) ?></td>
              <td>
                <?= adminOut(dd_money($country['currency'], $country['earnings'])) ?>
                <span class="calc">&times; <?= adminOut(dd_rate($country['rate'])) ?> rate</span>
              </td>
              <td class="r num"><?= adminOut(dd_pct($country['share'])) ?></td>
              <td class="r num"><?= dd_usd($country['payable_usd']) ?></td>
              <td></td>
            </tr>
          <?php endforeach; ?>

          <?php if ($contributor['unpriced'] > 0): ?>
            <tr class="detail warn-row" data-parent="<?= $rowIndex ?>">
              <td class="indent">No currency recorded</td>
              <td><?= (int) $contributor['unpriced_rows'] ?></td>
              <td><?= adminOut(number_format($contributor['unpriced'], 2)) ?> <span class="calc">mixed currencies &mdash; cannot be converted</span></td>
              <td class="r dim">&mdash;</td>
              <td class="r dim">not payable</td>
              <td></td>
            </tr>
          <?php endif; ?>
        <?php endforeach; ?>

        <?php if (!$unassignedPrinted) { echo $unassignedMarkup; } ?>
        </tbody>
        <tfoot>
          <tr>
            <td>Total to pay<?= $searchTerm !== '' ? ' (everybody, not just the search)' : '' ?></td>
            <td></td><td></td>
            <td class="r dim">&mdash;</td>
            <td class="r num"><?= dd_usd($totals['total_to_pay']) ?></td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <?php if ($unassigned['gross_usd'] > 0): ?>
      <div class="note note-amber">
        <i class="fas fa-triangle-exclamation"></i>
        <div><b><?= dd_usd($unassigned['gross_usd']) ?> earned on tags nobody holds.</b> That money is real, but until those tracking IDs are assigned to a person it cannot appear on anybody's report. This row is the month's to-do list, so it is deliberately left out of the total above.</div>
      </div>
    <?php endif; ?>

    <?php if ($totals['unpriced'] > 0): ?>
      <div class="note note-red">
        <i class="fas fa-triangle-exclamation"></i>
        <div><b><?= adminOut(number_format($totals['unpriced'], 2)) ?> across <?= (int) $totals['unpriced_rows'] ?> rows has no currency attached, so none of it is in the total.</b>
        These were imported by the old uploader, which recorded no country, no currency and no rate — their earnings are dollars, euros, pounds and Canadian dollars added together, which is not a real figure in any currency.
        Re-import those months through <a href="upload-month.php">Upload a month</a> and they will be replaced with proper figures.</div>
      </div>
    <?php endif; ?>

    <?php if (!empty($issues['shared_tags'])): ?>
      <div class="note note-amber">
        <i class="fas fa-triangle-exclamation"></i>
        <div><b><?= count($issues['shared_tags']) ?> tracking ID<?= count($issues['shared_tags']) === 1 ? ' is' : 's are' ?> assigned to more than one person</b>
        (<?= adminOut(implode(', ', array_slice(array_keys($issues['shared_tags']), 0, 6))) ?><?= count($issues['shared_tags']) > 6 ? ', …' : '' ?>).
        Each holder is being credited the full amount<?php if (!empty($issues['shared_overcredit']) && $issues['shared_overcredit'] > 0.005): ?>,
        so <b><?= dd_usd($issues['shared_overcredit']) ?></b> more is promised than came in<?php endif; ?>.
        Fix the assignments under <a href="index.php?tab=createTagTab">Create TAG</a>.</div>
      </div>
    <?php endif; ?>
  </section>

  <div class="note note-blue">
    <i class="fas fa-circle-info"></i>
    <div><b>Where these figures come from.</b> Each row was converted with the exchange rate frozen onto it the day its month was imported &mdash; never today's rate &mdash; and then multiplied by the commission currently set on the tag that earned it. Rates are fixed history; commission is live, so changing a percentage does restate a month that has not been paid. A month marked paid keeps the amount that was transferred, and any row that no longer agrees with it is flagged above.</div>
  </div>

  <?php endif; ?>
</main>

<style>
  .person { cursor: pointer; }
  .person:hover { background: #f5faf6; }
  .person-cell { display: flex; align-items: center; gap: 11px; }
  .chev { color: #9db3a3; display: inline-block; width: 12px; transition: transform .18s; }
  .person.open .chev { transform: rotate(90deg); }
  .avatar { width: 36px; height: 36px; border-radius: 50%; background: var(--brand-soft);
            color: var(--brand-soft-ink); display: grid; place-items: center;
            font-weight: 800; font-size: .82rem; flex: 0 0 auto; }
  .avatar-warn { background: var(--amber-bg); color: var(--amber-tx); }
  .pname { font-weight: 750; color: #1a4a3c; }
  .pmail { color: var(--muted); font-size: .8rem; }
  .detail { display: none; background: #fafbf8; }
  .detail.show { display: table-row; }
  .detail td { color: #43554a; font-size: .89rem; }
  .detail .indent { padding-left: 63px !important; font-weight: 700; color: var(--brand); }
  .detail.warn-row { background: var(--amber-bg); }
  .calc { color: var(--faint); font-size: .78rem; font-weight: 500; }
  .person.idle { display: none; }
  .person.idle.show { display: table-row; }
  .person.idle td { color: var(--faint); }
  .idle-header td { background: #f4f3ee; }
</style>
<link rel="stylesheet" href="assets/pickwell-admin.css?v=<?= rawurlencode((string) filemtime(__DIR__ . '/assets/pickwell-admin.css')) ?>">

<script>
/* ---- recording payments ---- */
const PAY_CSRF = <?= json_encode($_SESSION['settings_csrf_token']) ?>;
const PAY_MONTH = <?= json_encode($selectedMonth) ?>;

async function recordPayment(action, emails, button) {
  const status = document.getElementById('payStatus');
  const original = button ? button.innerHTML : '';
  if (button) { button.disabled = true; button.textContent = 'Saving...'; }

  const paidOnField = document.getElementById('paidOn');
  try {
    const response = await fetch('actions/mark_paid.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        csrf_token: PAY_CSRF,
        month: PAY_MONTH,
        action: action,
        emails: emails,
        paid_on: paidOnField ? paidOnField.value : ''
      })
    });
    const raw = await response.text();
    let data;
    try { data = JSON.parse(raw); }
    catch (e) { throw new Error('Server returned an unexpected response: ' + raw.slice(0, 160)); }
    if (!response.ok || !data.success) throw new Error(data.message || 'Could not save.');

    status.className = 'status ok';
    status.textContent = data.message;
    setTimeout(function () { window.location.reload(); }, 1100);
  } catch (error) {
    status.className = 'status bad';
    status.textContent = error.message;
    if (button) { button.disabled = false; button.innerHTML = original; }
  }
}

document.querySelectorAll('.pay-one').forEach(function (button) {
  button.addEventListener('click', function (event) {
    event.stopPropagation();
    recordPayment('pay', [this.dataset.email], this);
  });
});

document.querySelectorAll('.unpay-one').forEach(function (button) {
  button.addEventListener('click', function (event) {
    event.stopPropagation();
    if (!window.confirm('Undo the recorded payment for ' + this.dataset.email + '?\n\nThis only removes the record — it does not move any money.')) return;
    recordPayment('unpay', [this.dataset.email], this);
  });
});

const payAllButton = document.getElementById('payAll');
if (payAllButton) {
  payAllButton.addEventListener('click', function () {
    if (!window.confirm('Mark every contributor owed something this month as paid?\n\nThe amounts are fixed at what the sheet says right now.')) return;
    recordPayment('pay', [], this);
  });
}

const unpayAllButton = document.getElementById('unpayAll');
if (unpayAllButton) {
  unpayAllButton.addEventListener('click', function () {
    if (!window.confirm('Remove every payment record for this month?\n\nThis only removes the records — it does not move any money.')) return;
    recordPayment('unpay', [], this);
  });
}

document.querySelectorAll('.person').forEach(function (row) {
  row.addEventListener('click', function (event) {
    if (event.target.closest('a') || event.target.closest('button')) return;  // let the links through
    row.classList.toggle('open');
    document.querySelectorAll('.detail[data-parent="' + row.dataset.row + '"]').forEach(function (detail) {
      detail.classList.toggle('show');
    });
  });
});

const toggleIdle = document.getElementById('toggleIdle');
if (toggleIdle) {
  const idleRows = document.querySelectorAll('.person.idle');
  toggleIdle.addEventListener('click', function () {
    const showing = idleRows.length && idleRows[0].classList.contains('show');
    idleRows.forEach(function (row) { row.classList.toggle('show', !showing); });
    this.textContent = showing
      ? 'Show ' + idleRows.length + ' contributor' + (idleRows.length === 1 ? '' : 's') + ' with nothing this month'
      : 'Hide them again';
  });
}
</script>
</body>
</html>
