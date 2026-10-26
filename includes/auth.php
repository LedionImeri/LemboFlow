<?php
/**
 * Session-based authentication helpers.
 *
 * Private pages:  $userId = require_login();
 * Guest pages:    redirect_if_logged_in();   (login, register)
 */

declare(strict_types=1);

/** Log out after this many seconds without a request. */
const SESSION_IDLE_TIMEOUT = 2 * 60 * 60;

function current_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

function is_logged_in(): bool
{
    return current_user_id() !== null;
}

/** The logged-in user's row (without the password hash), cached for the request. */
function current_user(): ?array
{
    static $user = false;

    if ($user === false) {
        $id = current_user_id();
        if ($id === null) {
            return $user = null;
        }
        $stmt = db()->prepare(
            'SELECT u.id, u.name, u.email, u.is_demo,
                    bp.business_name, bp.currency, bp.logo_path
               FROM users u
          LEFT JOIN business_profiles bp ON bp.user_id = u.id
              WHERE u.id = ?'
        );
        $stmt->execute([$id]);
        $user = $stmt->fetch() ?: null;

        // The account was deleted while the session was alive.
        if ($user === null) {
            logout_user();
        }
    }

    return $user;
}

/**
 * Called once per request from bootstrap.php. Ends sessions that have been
 * idle too long, otherwise refreshes the "last seen" time.
 */
function enforce_session_timeout(): void
{
    if (!is_logged_in()) {
        return;
    }

    $lastSeen = (int) ($_SESSION['_last_seen'] ?? 0);
    if ($lastSeen > 0 && time() - $lastSeen > SESSION_IDLE_TIMEOUT) {
        logout_user();
        session_start();
        flash('info', 'You were logged out after a period of inactivity.');
        return;
    }

    $_SESSION['_last_seen'] = time();
}

/** Guard for private pages. Returns the user ID. API calls get a JSON 401 instead of a redirect. */
function require_login(): int
{
    $id = current_user_id();
    if ($id !== null && current_user() !== null) {
        return $id;
    }

    if (is_json_request()) {
        json_response(['ok' => false, 'error' => 'Please log in again.'], 401);
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $_SESSION['_intended'] = $_SERVER['REQUEST_URI'] ?? null;
    }
    flash('info', 'Please log in to continue.');
    redirect('auth/login.php');
}

/** Login and register pages send logged-in users straight to the app. */
function redirect_if_logged_in(): void
{
    if (is_logged_in() && current_user() !== null) {
        redirect('dashboard/');
    }
}

/**
 * Where to go after logging in: the private page the user originally asked
 * for, if it belongs to this app, otherwise the dashboard.
 */
function intended_url(): string
{
    $intended = $_SESSION['_intended'] ?? null;
    unset($_SESSION['_intended']);

    $basePath = rtrim((string) parse_url((string) config('app_url'), PHP_URL_PATH), '/') . '/';

    if (
        is_string($intended)
        && str_starts_with($intended, $basePath)
        && !str_starts_with($intended, '//')
        && !str_contains($intended, '\\')
        && !str_contains($intended, '/auth/')
    ) {
        // redirect() expects a path relative to the app root.
        return substr($intended, strlen($basePath));
    }

    return 'dashboard/';
}

/** Start an authenticated session. A new session ID prevents session fixation. */
function login_user(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['user_id']    = $userId;
    $_SESSION['_last_seen'] = time();
    unset($_SESSION['_csrf'], $_SESSION['_login_throttle']);

    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$userId]);
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'],
        ]);
    }
    session_destroy();
}

// ---------------------------------------------------------------------------
// Login throttling
// Slows down password guessing from one browser session: after 5 failed
// attempts the form is locked for 60 seconds.
// ---------------------------------------------------------------------------

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCK_SECONDS = 60;

/** Seconds until login is allowed again, or 0. */
function login_lock_remaining(): int
{
    $t = $_SESSION['_login_throttle'] ?? null;
    if (!$t || $t['count'] < LOGIN_MAX_ATTEMPTS) {
        return 0;
    }
    $remaining = $t['locked_at'] + LOGIN_LOCK_SECONDS - time();
    if ($remaining <= 0) {
        unset($_SESSION['_login_throttle']);
        return 0;
    }
    return $remaining;
}

function record_failed_login(): void
{
    $t = $_SESSION['_login_throttle'] ?? ['count' => 0, 'locked_at' => 0];
    $t['count']++;
    if ($t['count'] >= LOGIN_MAX_ATTEMPTS) {
        $t['locked_at'] = time();
    }
    $_SESSION['_login_throttle'] = $t;
}
