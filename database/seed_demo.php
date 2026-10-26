<?php
/**
 * Create a demo account from the command line (for screenshots or local testing).
 *
 *   php database/seed_demo.php           create a new demo account, print a one-time login
 *   php database/seed_demo.php --cleanup remove expired demo accounts
 *
 * The website's "View demo" button does the same thing for visitors.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/bootstrap.php';
require BASE_PATH . '/src/demo.php';

if (in_array('--cleanup', $argv, true)) {
    echo 'Removed ' . cleanup_demo_accounts() . " demo account(s).\n";
    exit(0);
}

$start  = microtime(true);
$userId = create_demo_account();

// Give this CLI-created demo a password so it can be used to log in, e.g. for screenshots.
$password = bin2hex(random_bytes(6));
db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
$email = db()->query('SELECT email FROM users WHERE id = ' . $userId)->fetchColumn();

$counts = db()->query(
    "SELECT (SELECT COUNT(*) FROM clients WHERE user_id = {$userId}) AS clients,
            (SELECT COUNT(*) FROM products WHERE user_id = {$userId}) AS products,
            (SELECT COUNT(*) FROM invoices WHERE user_id = {$userId}) AS invoices,
            (SELECT COUNT(*) FROM payments WHERE user_id = {$userId}) AS payments"
)->fetch();

printf(
    "Demo account created in %.2fs: %d clients, %d products, %d invoices, %d payments.\n" .
    "Log in at %s with:\n  email:    %s\n  password: %s\n" .
    "It is deleted on logout or after %d hours.\n",
    microtime(true) - $start,
    $counts['clients'], $counts['products'], $counts['invoices'], $counts['payments'],
    url('auth/login.php'), $email, $password, DEMO_LIFETIME_HOURS
);
