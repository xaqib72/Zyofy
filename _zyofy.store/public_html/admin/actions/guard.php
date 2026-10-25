<?php
/**
 * Shared guard for the admin's JSON actions.
 *
 * These endpoints read their body with json_decode(file_get_contents('php://input'))
 * and never look at the Content-Type. That is enough for a cross-site form to reach
 * them: a form posted with enctype="text/plain" can be crafted so its body parses as
 * JSON, which is the well-known JSON-CSRF trick. Being logged in as the admin is then
 * all it takes for another site to reassign a tag, delete a contributor, or move
 * somebody's commission — so a token is required as well as a session.
 */

function dd_guard_respond($message, $status)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('success' => false, 'message' => $message));
    exit;
}

/**
 * Requires an admin session and a matching CSRF token, and returns the decoded body.
 *
 * $tokenKey names the session token to check against — 'report_csrf_token' for the
 * dashboard's actions, 'settings_csrf_token' for the settings ones.
 */
function dd_guard_admin_json($tokenKey = 'report_csrf_token')
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        dd_guard_respond('Your session has expired. Please log in again.', 403);
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = $_POST;
    }
    if (!is_array($body)) {
        dd_guard_respond('Malformed request.', 400);
    }

    $sent = isset($body['csrf_token']) ? (string) $body['csrf_token'] : '';
    $held = isset($_SESSION[$tokenKey]) ? (string) $_SESSION[$tokenKey] : '';

    if ($held === '' || !hash_equals($held, $sent)) {
        dd_guard_respond('Security token did not match. Reload the page and try again.', 403);
    }

    return $body;
}
