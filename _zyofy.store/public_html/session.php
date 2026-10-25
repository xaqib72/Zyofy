<?php
require_once __DIR__ . '/inc/app.php';

if (!defined('DD_USER_SESSION_TTL')) {
    define('DD_USER_SESSION_TTL', 10 * 60 * 60);
}

function dd_start_user_session()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.gc_maxlifetime', (string) DD_USER_SESSION_TTL);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', dd_request_is_https() ? '1' : '0');
    ini_set('session.cookie_samesite', 'Lax');

    session_set_cookie_params([
        'lifetime' => DD_USER_SESSION_TTL,
        'path' => '/',
        'domain' => '',
        'secure' => dd_request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    if (!headers_sent()) {
        header('Cache-Control: private, no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
    }

    if (isset($_SESSION['user_id']) && !isset($_SESSION['user_login_expires_at'])) {
        $_SESSION['user_login_expires_at'] = time() + DD_USER_SESSION_TTL;
    }

    if (isset($_SESSION['user_login_expires_at']) && time() >= (int) $_SESSION['user_login_expires_at']) {
        dd_clear_user_session();
    }
}

function dd_clear_user_session()
{
    unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_email'], $_SESSION['user_login_expires_at']);
}

function dd_user_is_logged_in()
{
    return isset($_SESSION['user_id'], $_SESSION['user_email'], $_SESSION['user_login_expires_at'])
        && time() < (int) $_SESSION['user_login_expires_at'];
}

function dd_login_user($user)
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_name'] = (string) $user['name'];
    $_SESSION['user_email'] = (string) $user['email'];
    $_SESSION['user_login_expires_at'] = time() + DD_USER_SESSION_TTL;
}

function dd_require_user_login($redirectUrl = null)
{
    if (!dd_user_is_logged_in()) {
        header('Location: ' . ($redirectUrl ?: dd_url('/login/')));
        exit;
    }
}

function dd_logout_user()
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

dd_start_user_session();
