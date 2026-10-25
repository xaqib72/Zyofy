<?php
if (!defined('DD_APP_BOOTSTRAPPED')) {
    define('DD_APP_BOOTSTRAPPED', true);
}

$config = require __DIR__ . '/../config.php';
$GLOBALS['dd_config'] = $config;

if (!function_exists('dd_config')) {
    function dd_config($path = null, $default = null)
    {
        $config = isset($GLOBALS['dd_config']) && is_array($GLOBALS['dd_config']) ? $GLOBALS['dd_config'] : [];
        if ($path === null || $path === '') {
            return $config;
        }
        $value = $config;
        foreach (explode('.', (string) $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return $default;
            }
            $value = $value[$key];
        }
        return $value;
    }
}

if (!function_exists('dd_base_url')) {
    function dd_base_url()
    {
        return rtrim((string) dd_config('app.url', ''), '/');
    }
}

if (!function_exists('dd_url')) {
    function dd_url($path = '')
    {
        $base = dd_base_url();
        if ($path === '' || $path === '/') {
            return $base . '/';
        }
        return $base . '/' . ltrim((string) $path, '/');
    }
}

if (!function_exists('dd_media_url')) {
    function dd_media_url($path)
    {
        $path = trim((string) $path);
        return preg_match('~^https?://~i', $path) ? $path : dd_url($path);
    }
}

if (!function_exists('dd_e')) {
    function dd_e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('dd_request_is_https')) {
    function dd_request_is_https()
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        return isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
    }
}

// Safe defaults for public pages and JSON endpoints.
ini_set('display_errors', dd_config('app.debug', false) ? '1' : '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');
$logPath = __DIR__ . '/../storage/php-error.log';
@ini_set('error_log', $logPath);

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if (dd_request_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    if (dd_config('app.noindex', false)) {
        header('X-Robots-Tag: noindex, nofollow, noarchive', true);
    }
}
