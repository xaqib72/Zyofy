<?php
/**
 * Report Manager — folded into the dashboard.
 *
 * Everything this page used to do now lives in one place:
 *
 *   uploading  -> upload-month.php, which also records the month, country, currency,
 *                 exchange rate and share % that the old one-click form ignored
 *   history    -> the Reports tab of the dashboard, with the same delete button
 *
 * Keeping a second copy of the upload history meant two screens to maintain and two
 * places to look, so this address simply carries you to the one that is kept up to
 * date. Nothing has been taken away — old links and bookmarks still land somewhere
 * useful.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

header('Location: index.php?tab=reportsTab', true, 302);
exit;
