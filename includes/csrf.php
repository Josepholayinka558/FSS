<?php
/**
 * Lightweight CSRF token helpers.
 * Token stored in session. Use per request or per form — both fine.
 */
require_once(__DIR__ . '/security_bootstrap.php');

function fss_csrf_token(): string
{
    if (empty($_SESSION['fss_csrf'])) {
        $_SESSION['fss_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['fss_csrf'];
}

function fss_csrf_field(): string
{
    $t = htmlspecialchars(fss_csrf_token(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $t . '">';
}

function fss_verify_csrf(): void
{
    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    $postToken   = $_POST['csrf_token'] ?? null;
    $received    = $headerToken ?? $postToken;
    $expected    = $_SESSION['fss_csrf'] ?? '';
    if (
        empty($received) || empty($expected) ||
        !is_string($received) || !is_string($expected) ||
        !hash_equals($expected, $received)
    ) {
        http_response_code(403);
        die('CSRF verification failed. Please go back, refresh, and try again.');
    }
}
