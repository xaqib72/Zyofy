<?php
/**
 * Saves the country share percentages and the currency rates.
 *
 * These are defaults only. Nothing here touches a month that has already been
 * imported — those rows carry their own frozen rate and share.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function respond($success, $message, $status = 200, $extra = array())
{
    http_response_code($status);
    echo json_encode(array_merge(array('success' => $success, 'message' => $message), $extra));
    exit;
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    respond(false, 'Your session has expired. Please log in again.', 403);
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    respond(false, 'Malformed request.', 400);
}

$token = isset($payload['csrf_token']) ? (string) $payload['csrf_token'] : '';
if (!isset($_SESSION['settings_csrf_token'])
    || !hash_equals($_SESSION['settings_csrf_token'], $token)) {
    respond(false, 'Security token did not match. Reload the page and try again.', 403);
}

require_once __DIR__ . '/../../db.php';

$adminName = isset($_SESSION['admin_name']) && $_SESSION['admin_name'] !== ''
    ? $_SESSION['admin_name']
    : 'Admin';

/* ------------------------------------------------------------------ *
 * The commission a brand-new tag assignment starts on.
 *
 * Commission belongs to the assignment, so this is the figure the Create TAG form
 * offers. It is stored as the column default on user_tags.share_percent, which means
 * every route that creates an assignment picks it up — the form, or a hand-written
 * INSERT — without anyone having to remember. No existing tag is touched.
 * ------------------------------------------------------------------ */
if (isset($payload['default_commission'])) {
    $raw = trim((string) $payload['default_commission']);
    if ($raw === '' || !is_numeric($raw)) {
        respond(false, 'The default commission must be a number.', 422);
    }

    $value = round((float) $raw, 2);
    if ($value < 0 || $value > 100) {
        respond(false, 'The default commission must be between 0 and 100 percent.', 422);
    }

    $hasTagShare = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_tags'
          AND COLUMN_NAME = 'share_percent'")->fetchColumn() > 0;
    if (!$hasTagShare) {
        respond(false, 'Per-tag commission is not set up yet. Run migrations/008_per_tag_commission.php once.', 409);
    }

    try {
        $before = $pdo->query(
            "SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_tags' AND COLUMN_NAME = 'share_percent'"
        )->fetchColumn();

        // ALTER cannot take a bound parameter, so the value is validated above and
        // then formatted to a plain decimal — nothing from the request reaches the
        // statement as text.
        $pdo->exec('ALTER TABLE user_tags ALTER COLUMN share_percent SET DEFAULT ' . number_format($value, 2, '.', ''));
    } catch (Throwable $error) {
        error_log('save_settings.php default commission: ' . $error->getMessage());
        respond(false, 'Could not save the default. Nothing was changed.', 500);
    }

    // Trailing zeros come off only after a decimal point: 70.00 reads as 70, and 70
    // stays 70 rather than becoming 7.
    $tidy = function ($number) {
        $text = number_format((float) $number, 2, '.', '');
        return strpos($text, '.') === false ? $text : rtrim(rtrim($text, '0'), '.');
    };
    $wasText = $before === null ? 'nothing' : $tidy(trim((string) $before, "'")) . '%';
    respond(true, 'New tags will now start on ' . $tidy($value) . '% (was ' . $wasText
        . '). Every tag already assigned keeps its own rate.');
}

/* ------------------------------------------------------------------ *
 * Countries — share % and the active flag.
 * ------------------------------------------------------------------ */
if (isset($payload['countries']) && is_array($payload['countries'])) {
    $rows = $payload['countries'];
    if (count($rows) === 0) {
        respond(false, 'Nothing to save.', 422);
    }

    $known = $pdo->query('SELECT marketplace FROM marketplace_settings')->fetchAll(PDO::FETCH_COLUMN);

    // Validate everything before writing anything, so a single bad value cannot
    // leave the table half-updated.
    $clean = array();
    foreach ($rows as $row) {
        $marketplace = isset($row['marketplace']) ? trim((string) $row['marketplace']) : '';
        if ($marketplace === '' || !in_array($marketplace, $known, true)) {
            respond(false, 'Unknown marketplace: ' . $marketplace, 422);
        }

        $shareRaw = isset($row['share_percent']) ? trim((string) $row['share_percent']) : '';
        if ($shareRaw === '' || !is_numeric($shareRaw)) {
            respond(false, 'Share % for ' . $marketplace . ' must be a number.', 422);
        }

        $share = round((float) $shareRaw, 2);
        if ($share < 0 || $share > 100) {
            respond(false, 'Share % for ' . $marketplace . ' must be between 0 and 100.', 422);
        }

        $clean[] = array(
            'marketplace' => $marketplace,
            'share'       => $share,
            'active'      => !empty($row['is_active']) ? 1 : 0,
        );
    }

    $before = array();
    foreach ($pdo->query('SELECT marketplace, share_percent, is_active FROM marketplace_settings') as $row) {
        $before[$row['marketplace']] = $row;
    }

    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare(
            'UPDATE marketplace_settings
             SET share_percent = :s, is_active = :a, updated_by = :u, updated_at = NOW()
             WHERE marketplace = :m'
        );
        foreach ($clean as $row) {
            $update->execute(array(
                ':s' => $row['share'],
                ':a' => $row['active'],
                ':u' => $adminName,
                ':m' => $row['marketplace'],
            ));
        }
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        error_log('save_settings.php countries: ' . $error->getMessage());
        respond(false, 'Could not save the countries. Nothing was changed.', 500);
    }

    // Report what actually moved, so the admin sees the effect rather than a bare "saved".
    $changed = array();
    foreach ($clean as $row) {
        $old = isset($before[$row['marketplace']]) ? $before[$row['marketplace']] : null;
        if (!$old) {
            continue;
        }
        if (abs((float) $old['share_percent'] - $row['share']) > 0.001) {
            $changed[] = $row['marketplace'] . ' ' . rtrim(rtrim(number_format((float) $old['share_percent'], 2, '.', ''), '0'), '.')
                       . '% to ' . rtrim(rtrim(number_format($row['share'], 2, '.', ''), '0'), '.') . '%';
        }
        if ((int) $old['is_active'] !== $row['active']) {
            $changed[] = $row['marketplace'] . ($row['active'] ? ' switched on' : ' switched off');
        }
    }

    respond(true, count($changed) > 0
        ? 'Saved: ' . implode(', ', $changed) . '. This applies to the next import — months already imported keep their own figures.'
        : 'Saved. Nothing was different from what was already stored.');
}

/* ------------------------------------------------------------------ *
 * Rates — value and the date it is quoted for.
 * A rate counts as confirmed once it has a date against it; without one it
 * stays "indicative", which is what the upload screen warns about.
 * ------------------------------------------------------------------ */
if (isset($payload['rates']) && is_array($payload['rates'])) {
    $rows = $payload['rates'];
    if (count($rows) === 0) {
        respond(false, 'Nothing to save.', 422);
    }

    $known = $pdo->query('SELECT currency FROM exchange_rates')->fetchAll(PDO::FETCH_COLUMN);

    $clean = array();
    foreach ($rows as $row) {
        $currency = isset($row['currency']) ? strtoupper(trim((string) $row['currency'])) : '';
        if ($currency === '' || !in_array($currency, $known, true)) {
            respond(false, 'Unknown currency: ' . $currency, 422);
        }
        if ($currency === 'USD') {
            continue; // the base never moves
        }

        $rateRaw = isset($row['rate_to_usd']) ? trim((string) $row['rate_to_usd']) : '';
        if ($rateRaw === '' || !is_numeric($rateRaw)) {
            respond(false, 'The rate for ' . $currency . ' must be a number.', 422);
        }

        $rate = round((float) $rateRaw, 6);
        if ($rate <= 0) {
            respond(false, 'The rate for ' . $currency . ' must be greater than zero.', 422);
        }
        // A rate this far from 1 is almost always a typo, and a wrong rate silently
        // mis-pays everybody in that currency.
        if ($rate > 1000 || $rate < 0.00001) {
            respond(false, 'The rate for ' . $currency . ' (' . $rate . ') looks wrong. Enter how much one ' . $currency . ' is worth in USD.', 422);
        }

        $quotedFor = isset($row['quoted_for']) ? trim((string) $row['quoted_for']) : '';
        if ($quotedFor !== '') {
            $date = DateTime::createFromFormat('Y-m-d', $quotedFor);
            if (!$date || $date->format('Y-m-d') !== $quotedFor) {
                respond(false, 'The date for ' . $currency . ' is not a valid date.', 422);
            }
        }

        $clean[] = array(
            'currency' => $currency,
            'rate'     => $rate,
            'quoted'   => $quotedFor === '' ? null : $quotedFor,
        );
    }

    $before = array();
    foreach ($pdo->query('SELECT currency, rate_to_usd FROM exchange_rates') as $row) {
        $before[$row['currency']] = (float) $row['rate_to_usd'];
    }

    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare(
            'UPDATE exchange_rates
             SET rate_to_usd = :r, quoted_for = :q, is_confirmed = :k,
                 updated_by = :u, updated_at = NOW()
             WHERE currency = :c'
        );
        foreach ($clean as $row) {
            $update->execute(array(
                ':r' => $row['rate'],
                ':q' => $row['quoted'],
                ':k' => $row['quoted'] === null ? 0 : 1,
                ':u' => $adminName,
                ':c' => $row['currency'],
            ));
        }
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        error_log('save_settings.php rates: ' . $error->getMessage());
        respond(false, 'Could not save the rates. Nothing was changed.', 500);
    }

    $changed = array();
    foreach ($clean as $row) {
        if (isset($before[$row['currency']]) && abs($before[$row['currency']] - $row['rate']) > 0.0000005) {
            $changed[] = $row['currency'] . ' ' . rtrim(rtrim(number_format($before[$row['currency']], 6, '.', ''), '0'), '.')
                       . ' to ' . rtrim(rtrim(number_format($row['rate'], 6, '.', ''), '0'), '.');
        }
    }

    respond(true, count($changed) > 0
        ? 'Saved: ' . implode(', ', $changed) . '. This applies to the next import — months already imported keep their own rate.'
        : 'Saved. Nothing was different from what was already stored.');
}

respond(false, 'Nothing to save.', 422);
