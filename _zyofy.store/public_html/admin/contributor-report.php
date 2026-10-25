<?php
/**
 * One contributor's report, opened from the payout sheet.
 *
 * Deliberately the same layout the contributor sees when they log in, built from the
 * same dd_payout_month() figures — so the owner is never looking at a different
 * arithmetic from the person being paid.
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

$email = isset($_GET['email']) ? trim((string) $_GET['email']) : '';
$requestedMonth = isset($_GET['month']) ? trim((string) $_GET['month']) : '';

$setupError = '';
$reportMonths = array();
$selectedMonth = '';
$contributor = null;

try {
    $reportMonths = $pdo->query(
        "SELECT DISTINCT report_month FROM tracking_report_monthly
         WHERE report_month IS NOT NULL AND report_month <> '' ORDER BY report_month DESC"
    )->fetchAll(PDO::FETCH_COLUMN);

    if ($requestedMonth === 'all') {
        $selectedMonth = 'all';
    } elseif (preg_match('/^\d{4}-\d{2}$/', $requestedMonth) && in_array($requestedMonth, $reportMonths, true)) {
        $selectedMonth = $requestedMonth;
    } elseif (!empty($reportMonths)) {
        $selectedMonth = (string) $reportMonths[0];
    } else {
        $selectedMonth = 'all';
    }

    $payout = dd_payout_month($pdo, $selectedMonth);
    $key = strtolower($email);
    if (isset($payout['contributors'][$key])) {
        $contributor = $payout['contributors'][$key];
    }
} catch (Throwable $error) {
    error_log('contributor-report.php: ' . $error->getMessage());
    $setupError = 'This report could not be built.';
}

$monthLabel = $selectedMonth === 'all'
    ? 'all months'
    : ($selectedMonth !== '' ? date('F Y', strtotime($selectedMonth . '-01')) : 'no data');

/* ---------------- CSV for this one contributor ---------------- */
if ($contributor && isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="earnings-' . preg_replace('/[^a-z0-9]+/i', '-', $contributor['email']) . '-' . $selectedMonth . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, array('Country', 'Currency', 'Tracking IDs', 'Clicks', 'Shipped',
                        'Earned in that currency', 'Share %', 'Rate to USD', 'Payable USD'));
    foreach ($contributor['countries'] as $country) {
        fputcsv($out, array(
            dd_country_label($pdo, $country['marketplace']), $country['currency'], count($country['tags']),
            $country['clicks'], $country['items_shipped'],
            number_format($country['earnings'], 2, '.', ''),
            number_format($country['share'], 2, '.', ''),
            rtrim(rtrim(number_format($country['rate'], 6, '.', ''), '0'), '.'),
            number_format($country['payable_usd'], 2, '.', ''),
        ));
    }
    fputcsv($out, array('TOTAL', '', '', '', '', '', '', '', number_format($contributor['payable_usd'], 2, '.', '')));
    fclose($out);
    exit;
}

$pageTitle   = 'Contributor report';
$currentPage = 'payout';
require __DIR__ . '/partials/admin_head.php';
?>

<?php
  /* Whose report this is comes first. The contributor's own page can say "Your
     earnings" because there is no doubt who it belongs to; here the owner is looking
     at somebody else and needs the name, the email and the tags in front of them. */
  $initials = '';
  if ($contributor) {
      foreach (preg_split('/\s+/', trim($contributor['name'])) as $part) {
          if ($part !== '' && preg_match('/[A-Za-z]/', $part[0])) { $initials .= strtoupper($part[0]); }
      }
      $initials = substr($initials, 0, 2) !== '' ? substr($initials, 0, 2) : '?';
  }
  ?>

  <div class="row">
    <div>
      <div class="page-title" style="margin-bottom:0">
        <?php if ($contributor): ?>
          <div class="who-head">
            <span class="who-avatar"><?= adminOut($initials) ?></span>
            <div>
              <h1><?= adminOut($contributor['name']) ?></h1>
              <p class="lede"><?= adminOut($contributor['email']) ?></p>
            </div>
          </div>
        <?php else: ?>
          <h1><i class="fas fa-chart-line"></i>Contributor report</h1>
          <p class="lede">Open somebody from the payout sheet to see their figures.</p>
        <?php endif; ?>
      </div>
    </div>
    <div class="spacer"></div>
    <div class="fld"><label>&nbsp;</label><a class="btn" href="user-commission.php?month=<?= adminOut($selectedMonth) ?>">&larr; Payout sheet</a></div>
  </div>

  <?php if ($setupError !== ''): ?>
    <div class="note note-red"><i class="fas fa-triangle-exclamation"></i><div><?= adminOut($setupError) ?></div></div>
  <?php elseif (!$contributor): ?>
    <div class="note note-amber">
      <i class="fas fa-triangle-exclamation"></i>
      <div><b>No contributor found for &ldquo;<?= adminOut($email) ?>&rdquo;.</b> They may have no tracking ID assigned yet. Open the payout sheet and pick somebody from the list.</div>
    </div>
  <?php else: ?>

  <?php
  /* Their tags, each with the commission set on it, and which of them somebody else
     also holds. Commission is per assignment now, so the rate belongs on the tag rather
     than on the person — two tags of theirs can sit on different percentages. */
  $theirTags = array();
  $sharedTags = array();
  $hasTagShare = false;
  try {
      $hasTagShare = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_tags'
            AND COLUMN_NAME = 'share_percent'")->fetchColumn() > 0;

      $tagStatement = $pdo->prepare(
          'SELECT tracking_id, marketplace' . ($hasTagShare ? ', share_percent' : '') . "
           FROM user_tags
           WHERE LOWER(user_email) = :e AND tracking_id IS NOT NULL AND tracking_id <> ''
           ORDER BY marketplace, tracking_id"
      );
      $tagStatement->execute(array(':e' => strtolower($contributor['email'])));
      $theirTags = $tagStatement->fetchAll(PDO::FETCH_ASSOC);

      /* One query for every tag they hold rather than one query per tag — the old loop
         issued a round trip each time, which on somebody with thirty tags is thirty. */
      if (!empty($theirTags)) {
          $names = array_values(array_unique(array_map(function ($row) {
              return (string) $row['tracking_id'];
          }, $theirTags)));
          $slots = implode(',', array_fill(0, count($names), '?'));
          $shared = $pdo->prepare(
              "SELECT tracking_id FROM user_tags
               WHERE tracking_id IN ($slots)
               GROUP BY tracking_id HAVING COUNT(DISTINCT LOWER(user_email)) > 1"
          );
          $shared->execute($names);
          $sharedTags = $shared->fetchAll(PDO::FETCH_COLUMN);
      }
  } catch (Throwable $error) {
      error_log('contributor-report.php tags: ' . $error->getMessage());
  }
  ?>

  <div class="card who-card">
    <div class="who-facts">
      <div><span class="l">Tracking IDs</span><span class="v"><?= count($theirTags) ?></span></div>
      <div><span class="l">Countries</span><span class="v"><?= count($contributor['countries']) ?: '&mdash;' ?></span></div>
      <div><span class="l">Earned in</span><span class="v"><?= !empty($contributor['currencies']) ? adminOut(implode(' · ', $contributor['currencies'])) : '&mdash;' ?></span></div>
      <div><span class="l">Payment</span><span class="v">
        <?php if (!empty($contributor['payment'])): ?>
          <span class="pill pill-green">Paid <?= adminOut(date('j M Y', strtotime($contributor['payment']['paid_on']))) ?></span>
        <?php elseif ($contributor['payable_usd'] > 0): ?>
          <span class="pill pill-amber">Pending</span>
        <?php else: ?>
          <span class="dim">nothing due</span>
        <?php endif; ?>
      </span></div>
    </div>

    <?php if (!empty($theirTags)): ?>
      <div class="who-tags">
        <?php foreach ($theirTags as $tagRow): ?>
          <?php $isShared = in_array($tagRow['tracking_id'], $sharedTags, true); ?>
          <span class="who-tag<?= $isShared ? ' who-tag-shared' : '' ?>"<?= $isShared ? ' title="Also assigned to somebody else"' : '' ?>>
            <span class="who-tag-country"><?= adminOut(dd_country_label($pdo, $tagRow['marketplace'])) ?></span>
            <?= adminOut($tagRow['tracking_id']) ?>
            <?php if ($hasTagShare): ?>
              <span class="who-tag-pct" title="Commission on this tag &mdash; set it on Create TAG"><?= adminOut(dd_pct($tagRow['share_percent'])) ?></span>
            <?php endif; ?>
            <?php if ($isShared): ?><i class="fas fa-triangle-exclamation"></i><?php endif; ?>
          </span>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="sub" style="margin-top:14px"><i class="fas fa-circle-minus"></i> No tracking ID is assigned to this person.</p>
    <?php endif; ?>

    <?php if (!empty($sharedTags)): ?>
      <div class="note note-amber">
        <i class="fas fa-triangle-exclamation"></i>
        <div><b><?= count($sharedTags) ?> of their tracking IDs <?= count($sharedTags) === 1 ? 'is' : 'are' ?> also assigned to somebody else</b>
        (<?= adminOut(implode(', ', $sharedTags)) ?>). Both holders are credited the full amount, so the figure below is higher than what those tags actually earned.</div>
      </div>
    <?php endif; ?>
  </div>

  <div class="note note-blue">
    <i class="fas fa-circle-info"></i>
    <div>Below is exactly the page <b><?= adminOut($contributor['name']) ?></b> sees when they log in &mdash; same figures, same layout, built from the same calculation.</div>
  </div>

  <div class="row" style="margin-top:22px">
    <div class="fld">
      <label for="month">Month</label>
      <form method="get" action="contributor-report.php" id="monthForm">
        <input type="hidden" name="email" value="<?= adminOut($contributor['email']) ?>">
        <select id="month" name="month" onchange="document.getElementById('monthForm').submit()">
          <?php foreach ($reportMonths as $month): ?>
            <option value="<?= adminOut($month) ?>"<?= $selectedMonth === $month ? ' selected' : '' ?>><?= adminOut(date('F Y', strtotime($month . '-01'))) ?></option>
          <?php endforeach; ?>
          <option value="all"<?= $selectedMonth === 'all' ? ' selected' : '' ?>>All months</option>
        </select>
      </form>
    </div>
    <div class="spacer"></div>
    <a class="btn" href="contributor-report.php?<?= adminOut(http_build_query(array('email' => $contributor['email'], 'month' => $selectedMonth, 'export' => 'csv'))) ?>">Download CSV</a>
    <button class="btn btn-dark" onclick="window.print()">Print / PDF</button>
  </div>

  <?php
  $sharePills = array();
  foreach ($contributor['countries'] as $country) { $sharePills[(string) $country['share']] = true; }
  $shareText = '&mdash;';
  if ($contributor['effective_share'] !== null) {
      $shareText = dd_pct($contributor['effective_share'], 1);
  } elseif (count($sharePills) === 1) {
      $shareText = dd_pct((float) array_key_first($sharePills));
  }

  /* The distinct commissions their tags carry in each country. A country holding tags on
     two different rates has no single percentage, so its row says so rather than printing
     a combined figure that matches none of the tags behind it. */
  $ratesByCountry = array();
  foreach ($theirTags as $tagRow) {
      if (!$hasTagShare) { continue; }
      $ratesByCountry[$tagRow['marketplace']][(string) (float) $tagRow['share_percent']] = true;
  }
  ?>

  <div class="hero">
    <div class="cap">Payable to <?= adminOut($contributor['name']) ?> for <?= adminOut($monthLabel) ?></div>
    <div class="big"><?= dd_usd($contributor['payable_usd']) ?><span>USD</span></div>
    <div class="msg">
      <?php if ($contributor['payable_usd'] > 0): ?>
        Across <?= count($contributor['countries']) ?> <?= count($contributor['countries']) === 1 ? 'country' : 'countries' ?>, using the exchange rate fixed when this month was imported and the commission currently set on each of their tags.
      <?php else: ?>
        No sales were reported against their tags in this month.
      <?php endif; ?>
    </div>

    <?php if (!empty($contributor['payment'])): ?>
      <div class="paid-line">
        <span class="pill pill-green"><i class="fas fa-check"></i> Paid on <?= adminOut(date('j F Y', strtotime($contributor['payment']['paid_on']))) ?></span>
        <?php if ($contributor['payment']['drifted']): ?>
          <span class="pill pill-red"><i class="fas fa-triangle-exclamation"></i> <?= dd_usd($contributor['payment']['paid_amount']) ?> was transferred — the figures have changed since</span>
        <?php endif; ?>
      </div>
    <?php elseif ($contributor['payable_usd'] > 0): ?>
      <div class="paid-line"><span class="pill pill-amber"><i class="fas fa-clock"></i> Payment pending</span></div>
    <?php endif; ?>
    <div class="stats">
      <div><div class="l">Clicks</div><div class="v"><?= number_format((int) $contributor['clicks']) ?></div></div>
      <div><div class="l">Items ordered</div><div class="v"><?= number_format((int) $contributor['items_ordered']) ?></div></div>
      <div><div class="l">Items returned</div><div class="v"><?= number_format((int) $contributor['items_returned']) ?></div></div>
      <div><div class="l">Share</div><div class="v"><?= $shareText ?><?= count($sharePills) > 1 ? '<div style="font-size:.7rem;font-weight:600;color:#a9c6b0;letter-spacing:0;text-transform:none">combined</div>' : '' ?></div></div>
    </div>
  </div>

  <section class="card">
    <div class="tw" style="margin-top:0">
      <table>
        <thead>
          <tr>
            <th>Country</th><th>Currency</th>
            <th class="r">Earned in that currency</th>
            <th class="r">Share</th><th class="r">Rate</th>
            <th class="r">Payable in USD</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($contributor['countries'])): ?>
          <tr><td colspan="6" class="dim" style="text-align:center;padding:30px">No earnings were reported against their tags this month.</td></tr>
        <?php endif; ?>
        <?php foreach ($contributor['countries'] as $country): ?>
          <?php
          $mixed = isset($ratesByCountry[$country['marketplace']])
                   && count($ratesByCountry[$country['marketplace']]) > 1;
          ?>
          <tr>
            <td class="name"><?= adminOut(dd_country_label($pdo, $country['marketplace'])) ?></td>
            <td><?= adminOut($country['currency']) ?></td>
            <td class="r num"><?= adminOut(dd_money($country['currency'], $country['earnings'])) ?></td>
            <td class="r num"><?= adminOut(dd_pct($country['share'])) ?><?php if ($mixed): ?>
              <div class="dim" style="font-size:.68rem;font-weight:600"
                   title="Their tags here are on <?= adminOut(implode('%, ', array_map(function ($r) { return rtrim(rtrim(number_format((float) $r, 2, '.', ''), '0'), '.'); }, array_keys($ratesByCountry[$country['marketplace']])))) ?>%">combined</div>
            <?php endif; ?></td>
            <td class="r num"><?= adminOut(dd_rate($country['rate'])) ?></td>
            <td class="r num" style="font-weight:700"><?= dd_usd($country['payable_usd']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td>Total</td><td></td>
            <td class="r dim">&mdash;</td><td class="r dim">&mdash;</td><td class="r dim">&mdash;</td>
            <td class="r num"><?= dd_usd($contributor['payable_usd']) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <p class="sub" style="margin-top:16px">Each row is one country. The middle column is the amount exactly as Amazon reported it, with the commission and the exchange rate beside it, so the payable figure can be checked by hand: <span class="dim">earned &times; rate &times; commission = payable</span>. They are paid in USD.</p>
    <p class="sub" style="margin-top:8px">Commission belongs to the tag, not the country &mdash; each tag&rsquo;s own rate is on its chip at the top of this page, and is set on <a href="index.php?tab=createTagTab">Create TAG</a>. Where one country holds tags on different rates, its row shows the combined figure and is marked as such. The exchange rate is fixed history and never changes; commission is live, so editing it restates any month not yet paid.</p>

    <?php if ($contributor['unpriced'] > 0): ?>
      <div class="note note-amber">
        <i class="fas fa-triangle-exclamation"></i>
        <div><b><?= adminOut(number_format($contributor['unpriced'], 2)) ?> of earnings could not be included.</b> Those rows were imported before the country and currency were recorded, so they cannot be converted. The administrator needs to re-import that month.</div>
      </div>
    <?php endif; ?>
  </section>

  <?php if (!empty($contributor['countries'])): ?>
  <section class="card">
    <h2><i class="fas fa-tags"></i> Their tracking IDs</h2>
    <p class="sub">Which of their tags produced the figures above.</p>
    <div class="tw">
      <table>
        <thead><tr><th>Tracking ID</th><th>Country</th><th class="r">Clicks</th><th class="r">Shipped</th><th class="r">Earned</th><th class="r">Payable (USD)</th></tr></thead>
        <tbody>
        <?php foreach ($contributor['countries'] as $country): ?>
          <?php foreach ($country['tags'] as $tag): ?>
            <tr>
              <td class="name"><?= adminOut($tag['tracking_id']) ?></td>
              <td><?= adminOut(dd_country_label($pdo, $country['marketplace'])) ?></td>
              <td class="r num"><?= number_format((int) $tag['clicks']) ?></td>
              <td class="r num"><?= number_format((int) $tag['items_shipped']) ?></td>
              <td class="r num"><?= adminOut(dd_money($country['currency'], $tag['earnings'])) ?></td>
              <td class="r num"><?= dd_usd($tag['payable_usd']) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
  <?php endif; ?>

  <?php endif; ?>
</main>


</body>
</html>
