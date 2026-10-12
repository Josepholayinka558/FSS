<?php
/**
 * security_bootstrap.php
 * --------------------------------------------------------------
 * Hardens every authenticated page / entry-point.
 * Load this BEFORE any output (best: right after session_start).
 * Safe to require_once on pages that already started the session.
 * --------------------------------------------------------------
 */
if (session_status() === PHP_SESSION_NONE) {
    // Conservative session cookie defaults.
    $cookieParams = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => $cookieParams['lifetime'] ?: 0,
        'path'     => $cookieParams['path']     ?: '/',
        'domain'   => $cookieParams['domain']   ?: '',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
} else {
    // Session already started — at least stamp attributes for subsequent updates.
    $cookieParams = session_get_cookie_params();
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => $cookieParams['lifetime'] ?: 0,
            'path'     => $cookieParams['path']     ?: '/',
            'domain'   => $cookieParams['domain']   ?: '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(
            $cookieParams['lifetime'] ?: 0,
            ($cookieParams['path']     ?: '/') . '; SameSite=Lax',
            $cookieParams['domain']   ?: '',
            $secure,
            true
        );
    }
}

// Tiny helper for request-context.
if (!function_exists('fss_request_ip')) {
    function fss_request_ip(): string
    {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = explode(',', $_SERVER[$k])[0];
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }
}

if (!function_exists('fss_user_agent')) {
    function fss_user_agent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? '';
    }
}
