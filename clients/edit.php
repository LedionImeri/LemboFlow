<?php
/**
 * Edit a client. Issued invoices are not affected by these changes.
 */

declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
require BASE_PATH . '/src/clients.php';

$userId = require_login();
$client = find_client_or_404($userId, query_id());

if (is_post()) {
    verify_csrf();

    $data   = client_input($_POST);
    $errors = validate_client($data);

    if ($errors) {
        with_input($data, $errors);
        redirect('clients/edit.php?id=' . $client['id']);
    }

    update_client($userId, (int) $client['id'], $data);
    flash('success', "Client {$data['name']} saved.");
    redirect('clients/view.php?id=' . $client['id']);
}

$action      = url('clients/edit.php?id=' . $client['id']);
$submitLabel = 'Save changes';
$cancelUrl   = url('clients/view.php?id=' . $client['id']);

$pageTitle = 'Edit client';
$activeNav = 'clients';
require BASE_PATH . '/includes/header.php';
?>

<a class="back-link" href="<?= e($cancelUrl) ?>"><?= icon('chevron-left') ?><?= e($client['name']) ?></a>
<div class="page-header">
  <div>
    <h2 class="page-header__title">Edit <?= e($client['name']) ?></h2>
  </div>
</div>

<?php require __DIR__ . '/_form.php'; ?>

<?php require BASE_PATH . '/includes/footer.php'; ?>
