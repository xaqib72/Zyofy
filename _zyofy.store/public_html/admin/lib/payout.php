<?php
/**
 * Working out what a month owes.
 *
 * One place, used by the owner's payout sheet, the single-contributor report, and
 * later the contributor's own page — so the three can never disagree about a figure.
 *
 * The rate is frozen onto each report row at import; the commission is read live from
 * the tag assignment that earned it:
 *
 *     payable_usd = total_earnings x rate_to_usd (frozen) x user_tags.share_percent / 100
 *
 * Rows with no currency (imported before the rebuild) are deliberately excluded
 * from every total and reported separately. Their earnings are a mix of USD, EUR,
 * GBP and CAD added together, so any figure derived from them would be wrong.
 */

function dd_payout_round($value)
{
    return round((float) $value, 2);
}

/**
 * @return array contributors, unassigned, totals, issues
 */
/* Asked before reading a column that a migration may not have run yet, so an un-migrated
   copy of the site degrades to the older behaviour instead of fataling. */
function dd_payout_has_column($pdo, $table, $column)
{
    try {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
        );
        $statement->execute(array(':t' => $table, ':c' => $column));
        return (int) $statement->fetchColumn() > 0;
    } catch (Throwable $error) {
        return false;
    }
}

/* Finishes a bucket's per-country breakdown.
 *
 * Two things happen here, and the rounding one matters more than it looks.
 *
 * The share shown against a country is worked out from the money rather than copied off
 * whichever row happened to be first — what is payable there divided by what was grossed
 * there. One country can hold tags on different rates now, so no single row speaks for it.
 *
 * And every figure is rounded to the cent at the level below before being added up, so
 * the column a reader adds by hand equals the total printed under it. Rounding only at
 * the end is arithmetically purer but produces a page whose own numbers do not sum:
 * five rows showing 122.27 + 37.32 + 10.11 + 5.33 came to 175.03 under a total of
 * 175.01. Both pages invite the reader to check the figures by hand, so the visible
 * arithmetic has to be the true one. */
function dd_payout_finalise_countries(&$bucket)
{
    $total = 0.0;
    foreach ($bucket['countries'] as $key => $country) {
        if ($country['gross_usd'] > 0) {
            $bucket['countries'][$key]['share'] =
                ($country['payable_usd'] / $country['gross_usd']) * 100;
        }

        // Tags first, then the country from its rounded tags.
        $fromTags = 0.0;
        foreach ($country['tags'] as $tagKey => $tag) {
            $rounded = dd_payout_round($tag['payable_usd']);
            $bucket['countries'][$key]['tags'][$tagKey]['payable_usd'] = $rounded;
            $fromTags += $rounded;
        }

        $countryTotal = empty($country['tags'])
            ? dd_payout_round($country['payable_usd'])
            : dd_payout_round($fromTags);

        $bucket['countries'][$key]['payable_usd'] = $countryTotal;
        $bucket['countries'][$key]['gross_usd']   = dd_payout_round($country['gross_usd']);
        $total += $countryTotal;
    }

    // The bucket's own figure is the sum of what its rows now show.
    if (!empty($bucket['countries'])) {
        $bucket['payable_usd'] = dd_payout_round($total);
    }
}

/* The rate for one report row: the assignment covering that exact marketplace if there
   is one, otherwise whatever else that person holds the tag at. Null means no rate could
   be found at all, and the caller falls back to the share frozen on the row. */
/* Amazon tracking IDs are ASCII-like identifiers, but legacy assignments/imports can
   differ only by letter case or stray outer whitespace. Match on a canonical lookup key
   while keeping the original value untouched for display and stored report history. */
function dd_payout_tracking_key($value)
{
    $value = str_replace("\xC2\xA0", ' ', (string) $value);
    return strtolower(trim($value));
}

function dd_payout_tag_rate($rateByTag, $tag, $email, $marketplace)
{
    if (!isset($rateByTag[$tag][$email])) {
        return null;
    }
    $entry = $rateByTag[$tag][$email];
    if ($marketplace !== '' && isset($entry['byMarket'][$marketplace])
        && $entry['byMarket'][$marketplace] !== null) {
        return (float) $entry['byMarket'][$marketplace];
    }
    return $entry['any'] === null ? null : (float) $entry['any'];
}

function dd_payout_month($pdo, $reportMonth)
{
    $monthFilter = '';
    $params = array();
    if ($reportMonth !== 'all') {
        $monthFilter = ' WHERE report_month = :month';
        $params[':month'] = $reportMonth;
    }

    // Every report row for the month, with its own frozen rate and share.
    $rowStatement = $pdo->prepare(
        'SELECT tracking_id, marketplace, currency, rate_to_usd, share_percent,
                report_month, clicks, items_ordered, items_shipped, items_returned,
                ordered_revenue, items_shipped_earnings, items_returned_earnings,
                bonus, total_earnings
         FROM tracking_report_monthly' . $monthFilter
    );
    $rowStatement->execute($params);
    $reportRows = $rowStatement->fetchAll(PDO::FETCH_ASSOC);

    /* Commission belongs to the assignment, so it is read alongside the tag. */
    $hasTagShare = dd_payout_has_column($pdo, 'user_tags', 'share_percent');

    $tagStatement = $pdo->query(
        'SELECT user_email, tracking_id, marketplace'
        . ($hasTagShare ? ', share_percent' : '') . '
         FROM user_tags
         WHERE tracking_id IS NOT NULL AND tracking_id <> \'\''
    );

    /* Who holds which tag, and at what rate.
     *
     * A person is counted once per tag however many marketplaces it is recorded
     * against — listing the same tracking ID twice must not pay them twice. The rate,
     * though, is per assignment, so it is kept per marketplace as well: a tag set to 90%
     * on amazon.com and 70% on amazon.ca pays each row at its own country's figure.
     * `any` is what a row falls back to when its marketplace was never assigned. */
    $holdersByTag = array();
    $rateByTag    = array();
    foreach ($tagStatement as $tagRow) {
        $tag    = dd_payout_tracking_key($tagRow['tracking_id']);
        $email  = strtolower(trim((string) $tagRow['user_email']));
        $market = trim((string) $tagRow['marketplace']);
        $share  = $hasTagShare ? (float) $tagRow['share_percent'] : null;

        if ($tag === '' || $email === '') {
            continue;
        }
        if (!isset($holdersByTag[$tag])) {
            $holdersByTag[$tag] = array();
        }
        if (!in_array($email, $holdersByTag[$tag], true)) {
            $holdersByTag[$tag][] = $email;
        }

        if (!isset($rateByTag[$tag][$email])) {
            $rateByTag[$tag][$email] = array('any' => $share, 'byMarket' => array());
        }
        if ($market !== '') {
            $rateByTag[$tag][$email]['byMarket'][$market] = $share;
        }
        if ($rateByTag[$tag][$email]['any'] === null) {
            $rateByTag[$tag][$email]['any'] = $share;
        }
    }

    /* users.email and user_tags.user_email use different collations, so they cannot be
       joined in SQL — they are matched here instead.

       share_percent is the contributor's own commission rate. It used to come from the
       country, frozen onto each report row at import; it now belongs to the person, so
       one contributor can be on 60% and another on 85% wherever they earn. */
    $people = array();
    $hasUserShare = false;
    try {
        $check = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                                AND COLUMN_NAME = 'share_percent'");
        $hasUserShare = (int) $check->fetchColumn() > 0;
    } catch (Throwable $error) {
        $hasUserShare = false;
    }

    $sql = $hasUserShare
        ? 'SELECT id, name, email, share_percent FROM users ORDER BY name ASC'
        : 'SELECT id, name, email FROM users ORDER BY name ASC';

    foreach ($pdo->query($sql) as $user) {
        $people[strtolower((string) $user['email'])] = array(
            'id'    => (int) $user['id'],
            'name'  => (string) $user['name'],
            'email' => (string) $user['email'],
            // Before the migration has run, fall back to whatever the row was imported
            // with so the sheet still adds up instead of paying everybody nothing.
            'share' => $hasUserShare ? (float) $user['share_percent'] : null,
        );
    }

    $blank = array(
        'clicks' => 0, 'items_ordered' => 0, 'items_shipped' => 0, 'items_returned' => 0,
        'earnings' => 0.0, 'bonus' => 0.0, 'gross_usd' => 0.0, 'payable_usd' => 0.0,
        'unpriced' => 0.0, 'unpriced_rows' => 0,
    );

    $contributors = array();
    $unassigned   = $blank;
    $unassigned['tags'] = array();
    $unassigned['countries'] = array();
    $sharedTags = array();
    $sharedOvercredit = 0.0;

    /* Counted straight off the report rows, not by adding up each person's share of
       them. A tag held by two people lands in both their buckets, so summing the
       buckets would report the money twice — and this figure is a statement about
       the data itself, not about who gets credited for it. */
    $unpricedTotal = 0.0;
    $unpricedRows  = 0;
    foreach ($reportRows as $row) {
        if ((string) $row['currency'] === '') {
            $unpricedTotal += (float) $row['total_earnings'];
            $unpricedRows++;
        }
    }

    foreach ($reportRows as $row) {
        $tag        = dd_payout_tracking_key($row['tracking_id']);
        $currency   = (string) $row['currency'];
        $earnings   = (float) $row['total_earnings'];
        $priced     = $currency !== '';
        $grossUsd   = $priced ? $earnings * (float) $row['rate_to_usd'] : 0.0;
        $payableUsd = $priced ? $grossUsd * (float) $row['share_percent'] / 100 : 0.0;

        $holders = isset($holdersByTag[$tag]) ? $holdersByTag[$tag] : array();
        if (count($holders) > 1) {
            $sharedTags[$tag] = $holders;
        }

        if (empty($holders)) {
            dd_payout_add($unassigned, $row, $priced, $earnings, $grossUsd, $payableUsd);
            $unassigned['tags'][$tag] = true;
            continue;
        }

        // Each holder's credit for this row, so a shared tag can be told what it costs.
        $creditedHere = array();

        foreach ($holders as $email) {
            if (!isset($contributors[$email])) {
                $person = isset($people[$email])
                    ? $people[$email]
                    : array('id' => 0, 'name' => '(deleted account)', 'email' => $email, 'share' => null);
                $contributors[$email] = array_merge($blank, $person, array(
                    'tags' => array(), 'countries' => array(), 'currencies' => array(),
                ));
            }

            /* The rate on the assignment decides what they get for this row. Falling
               back, in order: the person's own rate (for an assignment made before the
               migration), then the share frozen on the row at import. */
            $theirShare = dd_payout_tag_rate($rateByTag, $tag, $email, (string) $row['marketplace']);
            if ($theirShare === null) {
                $theirShare = $contributors[$email]['share'];
            }
            $theirPayable = ($priced && $theirShare !== null)
                ? $grossUsd * $theirShare / 100
                : $payableUsd;

            dd_payout_add($contributors[$email], $row, $priced, $earnings, $grossUsd, $theirPayable, $theirShare);
            $contributors[$email]['tags'][$tag] = true;
            $creditedHere[] = $theirPayable;
            if ($priced && !in_array($currency, $contributors[$email]['currencies'], true)) {
                $contributors[$email]['currencies'][] = $currency;
            }
        }

        /* What this row costs by being held twice: everything credited on it beyond the
           largest single claim. In other words, the amount that would come off the total
           if each duplicate were narrowed to one person, keeping the biggest. */
        if (count($creditedHere) > 1) {
            $sharedOvercredit += array_sum($creditedHere) - max($creditedHere);
        }
    }

    // Everyone with a tag assigned belongs on the sheet, even at zero — a blank month
    // is information, not a reason to disappear from the payout run.
    foreach ($holdersByTag as $tag => $holders) {
        foreach ($holders as $email) {
            if (isset($contributors[$email])) {
                continue;
            }
            $person = isset($people[$email])
                ? $people[$email]
                : array('id' => 0, 'name' => '(deleted account)', 'email' => $email);
            $contributors[$email] = array_merge($blank, $person, array(
                'tags' => array(), 'countries' => array(), 'currencies' => array(),
            ));
        }
    }

    // How many tags each person holds, whether or not those tags earned anything.
    foreach ($holdersByTag as $tag => $holders) {
        foreach ($holders as $email) {
            if (isset($contributors[$email])) {
                $contributors[$email]['tags'][$tag] = true;
            }
        }
    }

    foreach ($contributors as $email => $contributor) {
        $contributors[$email]['tag_count'] = count($contributor['tags']);
        $contributors[$email]['unpriced']  = dd_payout_round($contributor['unpriced']);

        /* Rounds the country rows and rewrites this person's payable as their sum, so
           the column on screen adds up to the figure printed beneath it. Must run before
           the share below, which is derived from the rounded totals. */
        dd_payout_finalise_countries($contributors[$email]);

        $contributors[$email]['payable_usd']     = dd_payout_round($contributors[$email]['payable_usd']);
        $contributors[$email]['gross_usd']       = dd_payout_round($contributors[$email]['gross_usd']);
        $contributors[$email]['effective_share'] = $contributors[$email]['gross_usd'] > 0
            ? ($contributors[$email]['payable_usd'] / $contributors[$email]['gross_usd']) * 100
            : null;

        /* 'share' is what the screens print next to a person. With commission on the
           assignment there may be several, so it becomes the combined rate across
           everything they hold — which is the one figure that is true of all of it. */
        if ($contributors[$email]['effective_share'] !== null) {
            $contributors[$email]['share'] = $contributors[$email]['effective_share'];
        }

        sort($contributors[$email]['currencies']);
        uasort($contributors[$email]['countries'], function ($a, $b) {
            return $b['payable_usd'] <=> $a['payable_usd'];
        });
    }

    /* What has already been paid for this month.
     *
     * A paid row keeps the figure that was actually transferred. If the live
     * calculation has since moved — because the month was re-imported, or a tag was
     * reassigned — that is surfaced rather than hidden, because somebody was paid a
     * number the report no longer agrees with. */
    if ($reportMonth !== 'all') {
        $paidStatement = $pdo->prepare(
            'SELECT user_email, payable_usd, gross_usd, status, paid_on, frozen_at
             FROM payout_months WHERE report_month = :m'
        );
        $paidStatement->execute(array(':m' => $reportMonth));

        foreach ($paidStatement->fetchAll(PDO::FETCH_ASSOC) as $paidRow) {
            $email = strtolower((string) $paidRow['user_email']);
            if (!isset($contributors[$email])) {
                continue;
            }
            $paidAmount = dd_payout_round($paidRow['payable_usd']);
            $contributors[$email]['payment'] = array(
                'status'      => (string) $paidRow['status'],
                'paid_on'     => $paidRow['paid_on'],
                'paid_amount' => $paidAmount,
                'drifted'     => abs($paidAmount - $contributors[$email]['payable_usd']) > 0.005,
                'now'         => $contributors[$email]['payable_usd'],
            );
        }
    }

    foreach ($contributors as $email => $contributor) {
        if (!isset($contributor['payment'])) {
            $contributors[$email]['payment'] = null;
        }
    }

    uasort($contributors, function ($a, $b) {
        if ($a['payable_usd'] === $b['payable_usd']) {
            return strcasecmp($a['name'], $b['name']);
        }
        return $b['payable_usd'] <=> $a['payable_usd'];
    });

    $unassigned['payable_usd'] = dd_payout_round($unassigned['payable_usd']);
    $unassigned['gross_usd']   = dd_payout_round($unassigned['gross_usd']);
    $unassigned['unpriced']    = dd_payout_round($unassigned['unpriced']);
    $unassigned['tag_count']   = count($unassigned['tags']);
    dd_payout_finalise_countries($unassigned);
    uasort($unassigned['countries'], function ($a, $b) {
        return $b['payable_usd'] <=> $a['payable_usd'];
    });

    // A tag assigned to two people is credited to both, so this total is genuinely
    // higher than the money that came in. The sheet says so in its own warning
    // rather than quietly picking one holder.
    $totalToPay = 0.0;
    $paidTotal  = 0.0;
    $paidPeople = 0;
    foreach ($contributors as $contributor) {
        $totalToPay += $contributor['payable_usd'];
        if (!empty($contributor['payment']) && $contributor['payment']['status'] === 'paid') {
            $paidTotal += $contributor['payment']['paid_amount'];
            $paidPeople++;
        }
    }

    return array(
        'contributors' => $contributors,
        'unassigned'   => $unassigned,
        'totals'       => array(
            // The unassigned figure is deliberately NOT part of this. It is money the
            // month earned, but until a tag has an owner it is a to-do, not a payment.
            'total_to_pay'   => dd_payout_round($totalToPay),
            /* The gross, not a share of it. Nobody holds these tags, so there is no
               commission to apply — the old figure used the country share frozen on the
               row, which since commission moved to the assignment describes no one. The
               screen already showed the gross; the CSV showed the other number, so the
               same report disagreed with itself. */
            'unassigned_usd' => $unassigned['gross_usd'],
            'unpriced'       => dd_payout_round($unpricedTotal),
            'unpriced_rows'  => $unpricedRows,
            'people'         => count($contributors),
            'paid_total'     => dd_payout_round($paidTotal),
            'paid_people'    => $paidPeople,
            'still_owed'     => dd_payout_round($totalToPay - $paidTotal),
        ),
        'issues' => array(
            'shared_tags'       => $sharedTags,
            'shared_overcredit' => dd_payout_round($sharedOvercredit),
            'unpriced_rows' => $unpricedRows,
            'unassigned'    => $unassigned['tag_count'],
        ),
    );
}

/**
 * Folds one report row into a bucket, and into that bucket's per-country breakdown.
 *
 * $shareOverride is the contributor's own commission rate. It is passed in rather than
 * read off the row because the row's share is a record of the country setting at
 * import, and the payable figure now follows the person instead.
 */
function dd_payout_add(&$bucket, $row, $priced, $earnings, $grossUsd, $payableUsd, $shareOverride = null)
{
    $bucket['clicks']         += (int) $row['clicks'];
    $bucket['items_ordered']  += (int) $row['items_ordered'];
    $bucket['items_shipped']  += (int) $row['items_shipped'];
    $bucket['items_returned'] += (int) $row['items_returned'];
    $bucket['bonus']          += (float) $row['bonus'];

    if (!$priced) {
        $bucket['unpriced']      += $earnings;
        $bucket['unpriced_rows'] += 1;
        return;
    }

    $bucket['gross_usd']   += $grossUsd;
    $bucket['payable_usd'] += $payableUsd;

    $key = (string) $row['marketplace'];
    if (!isset($bucket['countries'][$key])) {
        $bucket['countries'][$key] = array(
            'marketplace' => $key,
            'currency'    => (string) $row['currency'],
            'rate'        => (float) $row['rate_to_usd'],
            'share'       => $shareOverride !== null ? $shareOverride : (float) $row['share_percent'],
            'earnings'    => 0.0,
            'gross_usd'   => 0.0,
            'payable_usd' => 0.0,
            'clicks'      => 0,
            'items_shipped' => 0,
            'items_returned' => 0,
            'tags'        => array(),
        );
    }

    $country = &$bucket['countries'][$key];
    $country['earnings']    += $earnings;
    $country['gross_usd']   += $grossUsd;
    $country['payable_usd'] += $payableUsd;
    $country['clicks']         += (int) $row['clicks'];
    $country['items_shipped']  += (int) $row['items_shipped'];
    $country['items_returned'] += (int) $row['items_returned'];
    $country['tags'][(string) $row['tracking_id']] = array(
        'tracking_id' => (string) $row['tracking_id'],
        'earnings'    => $earnings,
        'payable_usd' => $payableUsd,
        'clicks'      => (int) $row['clicks'],
        'items_shipped' => (int) $row['items_shipped'],
        // The commission this one tag was paid at. Kept per tag, not just per country,
        // because two tags in the same country can now sit on different rates.
        'share'       => $shareOverride !== null ? $shareOverride : (float) $row['share_percent'],
    );
    unset($country);
}

/** Country labels, looked up once. */
function dd_country_labels($pdo)
{
    static $labels = null;
    if ($labels !== null) {
        return $labels;
    }

    $labels = array();
    try {
        foreach ($pdo->query('SELECT marketplace, country_label FROM marketplace_settings') as $row) {
            $labels[$row['marketplace']] = $row['country_label'];
        }
    } catch (Throwable $error) {
        $labels = array();
    }

    return $labels;
}

function dd_country_label($pdo, $marketplace)
{
    $labels = dd_country_labels($pdo);
    if (isset($labels[$marketplace])) {
        return $labels[$marketplace];
    }
    return $marketplace !== '' ? $marketplace : 'Not recorded';
}

function dd_currency_symbol($currency)
{
    $symbols = array(
        'USD' => '$', 'EUR' => "\xE2\x82\xAC", 'GBP' => "\xC2\xA3", 'CAD' => 'C$',
        'JPY' => "\xC2\xA5", 'AUD' => 'A$', 'INR' => "\xE2\x82\xB9", 'MXN' => 'Mex$',
        'BRL' => 'R$', 'SEK' => 'kr ', 'PLN' => 'zl ', 'AED' => 'AED ',
        'SAR' => 'SAR ', 'SGD' => 'S$',
    );

    return isset($symbols[$currency]) ? $symbols[$currency] : $currency . ' ';
}

function dd_money($currency, $amount)
{
    return dd_currency_symbol($currency) . number_format((float) $amount, 2);
}

function dd_usd($amount)
{
    return '$' . number_format((float) $amount, 2);
}

/**
 * "90", "82.5", "70" — trailing zeros only ever trimmed after a decimal point.
 * Trimming the raw string would turn 90 into 9.
 */
function dd_pct($value, $decimals = 2)
{
    $text = number_format((float) $value, $decimals, '.', '');
    if (strpos($text, '.') !== false) {
        $text = rtrim(rtrim($text, '0'), '.');
    }

    return $text . '%';
}

/** Same rule for the exchange rate: 1.08 stays 1.08, 1 stays 1. */
function dd_rate($value)
{
    $text = number_format((float) $value, 6, '.', '');
    if (strpos($text, '.') !== false) {
        $text = rtrim(rtrim($text, '0'), '.');
    }

    return $text;
}
