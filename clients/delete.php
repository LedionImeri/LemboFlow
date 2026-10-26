<?php
/**
 * Delete a client. POST + CSRF only.
 * Clients with invoices are refused with an explanation, never a database error.
 */

declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
require BASE_PATH . '/src/clients.php';

$userId = require_login();
verify_csrf();

$clientId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$client   = find_client_or_404($userId, (int) $clientId);

$blocked = delete_client($userId, $client);

if ($blocked !== null) {
    flash('error', $blocked);
    redirect('clients/view.php?id=' . $client['id']);
}

flash('success', "Client {$client['name']} deleted.");
redirect('clients/');
