<?php
/**
 * Log out. POST + CSRF only, so a link or image on another site can't log the user out.
 * Demo accounts are deleted on logout; they exist only for one visit.
 */

declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
require BASE_PATH . '/src/demo.php';

verify_csrf();

$user = current_user();

logout_user();

if ($user && $user['is_demo']) {
    delete_demo_account((int) $user['id']);
}

// Fresh, empty session just to carry the toast to the login page.
session_start();
session_regenerate_id(true);
flash('success', $user && $user['is_demo'] ? 'Thanks for trying LemboFlow! Your demo data has been removed.' : 'You have been logged out.');

redirect($user && $user['is_demo'] ? '' : 'auth/login.php');
