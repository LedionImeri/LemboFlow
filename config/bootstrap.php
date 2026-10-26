<?php
/**
 * Every page starts with:  require __DIR__ . '/../config/bootstrap.php';
 *
 * Loads config, sets error handling and timezone, starts a hardened session,
 * and loads the database connection and helpers.
 */

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------
$localConfig = BASE_PATH . '/config/local.php';
if (!is_file($localConfig)) {
    http_response_code(500);
    exit('Missing config/local.php. Copy config/local.example.php to config/local.php and fill it in.');
}
$GLOBALS['__config'] = require $localConfig;

/**
 * Read a config value. Dots go into nested arrays: config('db.name').
 */
function config(string $key, mixed $default = null): mixed
{
    $value = $GLOBALS['__config'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

// ---------------------------------------------------------------------------
// Errors & timezone
// ---------------------------------------------------------------------------
date_default_timezone_set((string) config('timezone', 'UTC'));

$debug = (bool) config('debug', false);
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');

set_exception_handler(function (Throwable $e) use ($debug): void {
    error_log((string) $e);
    if (!headers_sent()) {
        http_response_code(500);
    }
    if (is_json_request()) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'error' => $debug ? $e->getMessage() : 'Server error.']);
        return;
    }
    if ($debug) {
        echo '<pre style="padding:16px;white-space:pre-wrap">' . htmlspecialchars((string) $e) . '</pre>';
        return;
    }
    render_error_page(500, 'Something went wrong', 'An unexpected error occurred. Please try again.');
});

// ---------------------------------------------------------------------------
// Security headers (sent from PHP so they also apply without Apache's .htaccess)
// ---------------------------------------------------------------------------
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    // Only our own script files may run: no inline scripts, no third-party code.
    // Inline style attributes are allowed (progress bars, chart heights);
    // data: images cover the CSS select arrow and the PDF logo.
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; "
        . "base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
}

// ---------------------------------------------------------------------------
// Session
// ---------------------------------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $path    = parse_url((string) config('app_url'), PHP_URL_PATH) ?: '/';

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('lemboflow');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => rtrim($path, '/') . '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ---------------------------------------------------------------------------
// Core files
// ---------------------------------------------------------------------------
require BASE_PATH . '/config/database.php';
require BASE_PATH . '/includes/helpers.php';
require BASE_PATH . '/includes/csrf.php';
require BASE_PATH . '/includes/auth.php';

enforce_session_timeout();
