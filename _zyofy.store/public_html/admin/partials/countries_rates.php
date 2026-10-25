<?php
/**
 * Countries and exchange rates — the single implementation.
 *
 * Included by the dashboard's "Countries & Rates" tab and by exchange-rates.php, so
 * the two entry points cannot drift apart. Both save through actions/save_settings.php.
 *
 * Expects, from the including page:
 *   $countrySettings  rows from marketplace_settings
 *   $rateSettings     rows from exchange_rates
 *   $tagCountByMarket marketplace => number of tags assigned there  (optional)
 *   $out              an escaping callable
 */

if (!isset($tagCountByMarket)) { $tagCountByMarket = array(); }
if (!isset($out) || !is_callable($out)) {
    $out = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
}
?>
<?php
/* The figure a brand-new tag assignment starts on.
 *
 * Held as the column default on user_tags.share_percent rather than in a settings row,
 * so every way an assignment can be created — the Create TAG form, or a hand-written
 * INSERT in phpMyAdmin — picks it up without anybody remembering to. */
$defaultCommission = 70.00;
try {
    $row = $pdo->query(
        "SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_tags' AND COLUMN_NAME = 'share_percent'"
    )->fetch(PDO::FETCH_ASSOC);
    if ($row && $row['COLUMN_DEFAULT'] !== null) {
        $defaultCommission = (float) trim((string) $row['COLUMN_DEFAULT'], "'");
    }
} catch (Throwable $error) {
    error_log('countries_rates.php default commission: ' . $error->getMessage());
}
?>
<div class="card">
  <div class="card-header">
    <div>
      <h2><i class="fas fa-user-plus"></i> Commission for New Tags</h2>
      <p class="subtext">Commission belongs to the tag, and each one is set on <a href="index.php?tab=createTagTab">Create TAG</a>. This is only the figure a tag starts on the moment it is assigned &mdash; it never changes a tag that already exists.</p>
    </div>
  </div>
  <div class="action-row">
    <input class="settings-input" type="number" id="defaultCommission" step="0.01" min="0" max="100"
           value="<?= number_format($defaultCommission, 2, '.', '') ?>"> %
    <button class="button button-primary" id="saveDefaultCommission"><i class="fas fa-save"></i> Save Default</button>
    <span class="subtext">Applies to tags assigned from now on.</span>
  </div>
  <div id="defaultCommissionStatus" class="status"></div>
</div>

<div class="card">
  <div class="card-header">
    <div>
      <h2><i class="fas fa-percent"></i> Countries</h2>
      <p class="subtext">Which currency each Amazon store pays in, and whether it appears on the upload screen. The share % here is no longer what anybody is paid &mdash; commission moved to the person &mdash; but it is kept as a record of what each country was set to. Switch a country off to keep it out of the upload screen.</p>
    </div>
  </div>
  <div class="table-wrap"><table>
    <thead><tr><th>Country</th><th>Marketplace</th><th>Currency</th><th>Share %</th><th>Tags assigned</th><th>Active</th></tr></thead>
    <tbody>
    <?php foreach ($countrySettings as $country): ?>
      <?php
      $marketplace = $country['marketplace'];
      $isActive = (int) $country['is_active'] === 1;
      $tagCount = isset($tagCountByMarket[$marketplace]) ? (int) $tagCountByMarket[$marketplace] : 0;
      ?>
      <tr data-marketplace="<?= $out($marketplace) ?>"<?= $isActive ? '' : ' class="row-muted"' ?>>
        <td class="identity-cell"><strong><?= $out($country['country_label']) ?></strong></td>
        <td class="identity-cell"><span class="subtext"><?= $out($marketplace) ?></span></td>
        <td><strong><?= $out($country['currency']) ?></strong></td>
        <td><input class="settings-input share-input" type="number" step="0.01" min="0" max="100"
                   value="<?= number_format((float) $country['share_percent'], 2, '.', '') ?>"
                   aria-label="Share percent for <?= $out($country['country_label']) ?>"> %</td>
        <td><?= $tagCount > 0 ? number_format($tagCount) . ' tag' . ($tagCount === 1 ? '' : 's') : '<span class="subtext">none yet</span>' ?></td>
        <td><input class="active-input" type="checkbox" <?= $isActive ? 'checked' : '' ?>
                   aria-label="<?= $out($country['country_label']) ?> active"></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="action-row" style="margin-top:18px">
    <button class="button button-primary" id="saveCountries"><i class="fas fa-save"></i> Save Countries</button>
    <span class="subtext">A country's currency is a fact about Amazon, not a preference, so it is fixed here.</span>
  </div>
  <div id="countryStatus" class="status"></div>
</div>

<div class="card">
  <div class="card-header">
    <div>
      <h2><i class="fas fa-money-bill-transfer"></i> Rate to US Dollar</h2>
      <p class="subtext">How much one unit of each currency is worth in USD, and the date you are quoting it for. The rate is per currency while the share above is per country &mdash; so one euro rate serves Germany, France and Spain, and each keeps its own percentage.</p>
    </div>
  </div>
  <div class="table-wrap"><table>
    <thead><tr><th>Currency</th><th>1 unit = USD</th><th>Quoted for</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($rateSettings as $rate): ?>
      <?php $rateCode = (string) $rate['currency']; ?>
      <tr data-currency="<?= $out($rateCode) ?>">
        <td class="identity-cell"><strong><?= $out($rateCode) ?></strong></td>
        <?php if ($rateCode === 'USD'): ?>
          <td>1.0000</td><td><span class="subtext">&mdash;</span></td><td><span class="badge badge-locked">Base currency</span></td>
        <?php else: ?>
          <td><input class="settings-input rate-input" type="number" step="0.000001" min="0.000001"
                     value="<?= $out(rtrim(rtrim(number_format((float) $rate['rate_to_usd'], 6, '.', ''), '0'), '.')) ?>"
                     aria-label="Rate for <?= $out($rateCode) ?>"></td>
          <td><input class="settings-input date-input" type="date"
                     value="<?= $rate['quoted_for'] ? $out($rate['quoted_for']) : '' ?>"
                     aria-label="Quoted for date, <?= $out($rateCode) ?>"></td>
          <td><?= (int) $rate['is_confirmed'] === 1
                 ? '<span class="badge badge-exact">Set by you</span>'
                 : '<span class="badge badge-legacy">Indicative &mdash; not set yet</span>' ?></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="action-row" style="margin-top:18px">
    <button class="button button-primary" id="saveRates"><i class="fas fa-save"></i> Save Rates</button>
    <span class="subtext">A rate counts as set once it has a date against it.</span>
  </div>
  <div id="rateStatus" class="status"></div>
</div>

<div class="card">
  <div class="info-note">
    <i class="fas fa-circle-info"></i>
    <div><strong>Changing anything here does not rewrite past months.</strong> These are only the starting figures offered when you upload a report. The moment a month is imported, the rate and the share it used are stored on that month for good &mdash; so a report you have already paid against keeps saying exactly what it said the day you paid it, and next month simply uses the new numbers.</div>
  </div>
</div>
