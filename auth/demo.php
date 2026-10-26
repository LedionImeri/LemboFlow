<?php
/**
 * Start a demo. POST + CSRF only (from the landing and login pages).
 * Creates a private, seeded demo account for this visitor and logs them in.
 */

declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
require BASE_PATH . '/src/demo.php';

verify_csrf();

// Someone already logged in with a real account keeps it.
if (is_logged_in() && current_user() !== null && !current_user()['is_demo']) {
    flash('info', 'You’re already logged in to your own account.');
    redirect('dashboard/');
}

// Check the limit before touching anything, so a refused restart keeps the current demo.
$wait = demo_throttle();
if ($wait > 0) {
    flash('error', 'Too many demos were started in a short time. Please try again in ' . ceil($wait / 60) . ' min.');
    redirect(is_logged_in() ? 'dashboard/' : 'auth/login.php');
}

// A visitor restarting the demo gets a fresh one; the old one is removed.
if (is_logged_in() && current_user()['is_demo']) {
    $previous = current_user_id();
    $starts   = $_SESSION['_demo_starts'] ?? [];
    logout_user();
    delete_demo_account($previous);
    session_start();
    $_SESSION['_demo_starts'] = $starts; // restarting must not reset the start limit
}

cleanup_demo_accounts();

$userId = create_demo_account();
login_user($userId);

flash('success', 'Welcome to the LemboFlow demo! Everything here is sample data. Feel free to try anything.');
redirect('dashboard/');
