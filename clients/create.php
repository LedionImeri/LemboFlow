<?php
/**
 * Add a client.
 */

declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
require BASE_PATH . '/src/clients.php';

$userId = require_login();

if (is_post()) {
    verify_csrf();

    $data   = client_input($_POST);
    $errors = validate_client($data);

    if ($errors) {
        with_input($data, $errors);
        redirect('clients/create.php');
    }

    $clientId = create_client($userId, $data);
    flash('success', "Client {$data['name']} added.");
    redirect('clients/view.php?id=' . $clientId);
}

$client      = [];
$action      = url('clients/create.php');
$submitLabel = 'Add client';
$cancelUrl   = url('clients/');

$pageTitle = 'Add client';
$activeNav = 'clients';
require BASE_PATH . '/includes/header.php';
?>

<a class="back-link" href="<?= e($cancelUrl) ?>"><?= icon('chevron-left') ?>Clients</a>
<div class="page-header">
  <div>
    <h2 class="page-header__title">Add client</h2>
    <p class="page-header__subtitle">Only the name is required. You can fill in the rest later.</p>
  </div>
</div>

<?php require __DIR__ . '/_form.php'; ?>

<?php require BASE_PATH . '/includes/footer.php'; ?>
