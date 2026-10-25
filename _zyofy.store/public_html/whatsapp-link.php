<?php
require_once __DIR__ . '/inc/site.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/whatsapp.php';
require_once __DIR__ . '/db.php';

dd_require_user_login(dd_url('/login/'));
$user = dd_logged_in_user($pdo);
$csrf = dd_csrf_token();
$notice = '';
$noticeType = 'info';
$pendingCode = null;
$postedPhone = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dd_csrf_validate($_POST['csrf_token'] ?? '')) {
        $notice = 'Your session expired. Reload this page and try again.';
        $noticeType = 'error';
    } elseif (($_POST['action'] ?? '') === 'unlink') {
        $pdo->beginTransaction();
        try {
            $remove = $pdo->prepare('DELETE FROM whatsapp_user_phones WHERE user_id = ?');
            $remove->execute([(int) $user['id']]);
            $expire = $pdo->prepare('UPDATE whatsapp_link_codes SET expires_at = UTC_TIMESTAMP(), consumed_at = COALESCE(consumed_at, UTC_TIMESTAMP()) WHERE user_id = ? AND consumed_at IS NULL');
            $expire->execute([(int) $user['id']]);
            $pdo->commit();
            $notice = 'WhatsApp has been disconnected from your Zyofy account.';
            $noticeType = 'success';
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[Zyofy WhatsApp] Account unlink failed.');
            $notice = 'Could not disconnect WhatsApp right now.';
            $noticeType = 'error';
        }
    } elseif (($_POST['action'] ?? '') === 'start') {
        $postedPhone = trim((string) ($_POST['phone'] ?? ''));
        try {
            $pendingCode = dd_whatsapp_start_link($pdo, (int) $user['id'], $postedPhone);
            $notice = 'Verification request created. Send the code below from that phone number to the Zyofy WhatsApp Business number.';
            $noticeType = 'success';
        } catch (Throwable $error) {
            $notice = $error instanceof InvalidArgumentException || $error instanceof RuntimeException
                ? $error->getMessage()
                : 'Could not start WhatsApp verification right now.';
            if (!($error instanceof InvalidArgumentException || $error instanceof RuntimeException)) error_log('[Zyofy WhatsApp] Account link request failed.');
            $noticeType = 'error';
        }
    }
}

$linkedQuery = $pdo->prepare('SELECT phone_e164, verified_at FROM whatsapp_user_phones WHERE user_id = ? LIMIT 1');
$linkedQuery->execute([(int) $user['id']]);
$linkedPhone = $linkedQuery->fetch(PDO::FETCH_ASSOC) ?: null;
$config = dd_whatsapp_config();
$botReady = dd_whatsapp_configured(true);

dd_render_head('Connect WhatsApp', ['body_class' => 'storefront-page whatsapp-link-page', 'description' => 'Securely connect your WhatsApp number to your Zyofy contributor account.']);
dd_render_storefront_header('generator', $user);
?>
<style>
.wa-link-main{padding:48px 0 76px;background:#f7f5f1;min-height:60vh}.wa-link-shell{max-width:780px}.wa-link-card{padding:clamp(22px,5vw,48px);background:#fff;border:1px solid #e8e1d8;border-radius:22px;box-shadow:0 18px 50px rgba(30,40,56,.08)}.wa-link-kicker{color:#c9534f;font-size:.72rem;font-weight:850;letter-spacing:.16em;text-transform:uppercase}.wa-link-card h1{margin:12px 0;font:clamp(2rem,5vw,3.4rem)/1.02 Georgia,serif;color:#1c2942}.wa-link-intro,.wa-link-help{color:#63718a;line-height:1.65}.wa-link-form{display:grid;gap:12px;margin-top:26px}.wa-link-form label{font-weight:750;color:#26334b}.wa-link-form input{width:100%;min-height:50px;margin-top:8px;padding:12px 14px;border:1px solid #d9d4cc;border-radius:12px;font:inherit}.wa-link-button{min-height:48px;padding:12px 18px;border:0;border-radius:12px;background:#1d2a43;color:#fff;font:inherit;font-weight:800;cursor:pointer}.wa-link-button:disabled{opacity:.55;cursor:not-allowed}.wa-link-button-danger{background:#fff3f1;color:#a63f39;border:1px solid #f1d3cd}.wa-link-note,.wa-link-notice,.wa-link-code{margin-top:18px;padding:14px 16px;border-radius:12px;line-height:1.55}.wa-link-note{background:#f4f7f5;color:#536458}.wa-link-notice.info{background:#eef4fb;color:#365371}.wa-link-notice.success{background:#edf8ef;color:#246b3c}.wa-link-notice.error{background:#fff2ef;color:#9e4239}.wa-link-code{background:#fff7e4;border:1px solid #eddbad;color:#674f17}.wa-link-code strong{display:block;margin:5px 0;font:800 2rem/1.2 ui-monospace,monospace;letter-spacing:.18em}.wa-link-status{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;padding:16px;margin:22px 0;background:#f7f8f6;border:1px solid #e3e9e2;border-radius:14px}.wa-link-status strong{color:#1d2a43}.wa-link-status form{margin:0}.wa-link-divider{height:1px;border:0;background:#eee8df;margin:26px 0}
@media(max-width:600px){.wa-link-main{padding:24px 0 48px}.wa-link-shell{padding-left:14px;padding-right:14px}.wa-link-card{border-radius:18px}.wa-link-status{align-items:stretch}.wa-link-status form,.wa-link-status button{width:100%}}
</style>
<link rel="stylesheet" href="<?= dd_e(dd_url('/assets/css/zyofy-2026.css?v=' . (is_file(__DIR__ . '/assets/css/zyofy-2026.css') ? filemtime(__DIR__ . '/assets/css/zyofy-2026.css') : '1'))) ?>">
<main class="wa-link-main"><div class="shell wa-link-shell"><section class="wa-link-card">
  <span class="wa-link-kicker">Contributor account · WhatsApp</span>
  <h1>Connect your WhatsApp.</h1>
  <p class="wa-link-intro">Verify the number you will message Zyofy from. Product posts created in WhatsApp will use a tracking ID assigned to your Zyofy account for the matching marketplace.</p>
  <?php if ($notice !== ''): ?><div class="wa-link-notice <?= dd_e($noticeType) ?>" role="status"><?= dd_e($notice) ?></div><?php endif; ?>
  <?php if ($linkedPhone): ?>
    <div class="wa-link-status"><div><strong>Verified number</strong><br><span><?= dd_e($linkedPhone['phone_e164']) ?> · verified <?= dd_e($linkedPhone['verified_at']) ?> UTC</span></div><form method="post"><input type="hidden" name="csrf_token" value="<?= dd_e($csrf) ?>"><input type="hidden" name="action" value="unlink"><button class="wa-link-button wa-link-button-danger" type="submit">Disconnect</button></form></div>
  <?php endif; ?>
  <?php if (!$botReady): ?>
    <div class="wa-link-note">WhatsApp verification is not configured yet. The Zyofy administrator needs to finish the secure WhatsApp Cloud API setup before numbers can be linked.</div>
  <?php elseif (!$linkedPhone): ?>
    <form class="wa-link-form" method="post">
      <input type="hidden" name="csrf_token" value="<?= dd_e($csrf) ?>"><input type="hidden" name="action" value="start">
      <label for="waPhone">Your WhatsApp number in international format<input id="waPhone" name="phone" type="tel" autocomplete="tel" inputmode="tel" maxlength="24" required placeholder="+923001234567" value="<?= dd_e($postedPhone) ?>"></label>
      <button class="wa-link-button" type="submit">Create verification code</button>
    </form>
    <p class="wa-link-help">Enter the country code. We will show a one-time code on this signed-in page; send <strong>LINK followed by the code</strong> from that phone to the Zyofy WhatsApp Business number. Codes expire after 10 minutes and have limited attempts.</p>
    <?php if ($pendingCode): ?>
      <div class="wa-link-code"><span>Send this from <?= dd_e($pendingCode['phone']) ?> to <?= dd_e($config['business_number'] ?: 'the Zyofy WhatsApp Business number') ?>:</span><strong>LINK <?= dd_e($pendingCode['code']) ?></strong><span>Do not share this code with anyone. It is shown only in your signed-in Zyofy session.</span></div>
    <?php endif; ?>
  <?php else: ?>
    <hr class="wa-link-divider"><p class="wa-link-help">This verified number can send an Amazon URL or an ASIN with its marketplace to create a Zyofy product post. To replace it, disconnect this number and verify the new one.</p>
  <?php endif; ?>
</section></div></main>
<?php dd_render_footer(); ?>
