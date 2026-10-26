<?php
/**
 * CSRF protection. One random token per session.
 *
 * Forms:   <?= csrf_field() ?>
 * fetch(): header X-CSRF-Token (app.js reads it from <meta name="csrf-token">)
 * Server:  verify_csrf() at the top of every POST handler.
 */

declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Allows POST only, and stops with 419 when the token is missing or wrong. */
function verify_csrf(): void
{
    if (!is_post()) {
        abort(405);
    }

    $sent = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (!is_string($sent) || empty($_SESSION['_csrf']) || !hash_equals($_SESSION['_csrf'], $sent)) {
        abort(419);
    }
}
