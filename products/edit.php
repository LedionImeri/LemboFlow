<?php
/**
 * Edit a product or service. Existing invoices keep the values they were created with.
 */

declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
require BASE_PATH . '/src/products.php';

$userId  = require_login();
$product = find_product_or_404($userId, query_id());

if (is_post()) {
    verify_csrf();

    $data   = product_input($_POST);
    $errors = validate_product($data);

    if ($errors) {
        with_input($data, $errors);
        redirect('products/edit.php?id=' . $product['id']);
    }

    update_product($userId, (int) $product['id'], $data);
    flash('success', "{$data['name']} saved.");
    redirect('products/');
}

$usage = db()->prepare('SELECT COUNT(*) FROM invoice_items WHERE product_id = ?');
$usage->execute([$product['id']]);
$usageCount = (int) $usage->fetchColumn();

$currency    = current_user()['currency'] ?? 'EUR';
$action      = url('products/edit.php?id=' . $product['id']);
$submitLabel = 'Save changes';
$cancelUrl   = url('products/');

$deleteMessage = $usageCount > 0
    ? "Delete {$product['name']}? It is used on {$usageCount} invoice " . ($usageCount === 1 ? 'line' : 'lines')
      . '. Those invoices keep their copy of the name and price. Archiving hides it instead.'
    : "Delete {$product['name']}? This can’t be undone.";

$pageTitle = 'Edit item';
$activeNav = 'products';
require BASE_PATH . '/includes/header.php';
?>

<a class="back-link" href="<?= e($cancelUrl) ?>"><?= icon('chevron-left') ?>Products &amp; services</a>
<div class="page-header">
  <div>
    <h2 class="page-header__title"><?= e($product['name']) ?></h2>
    <p class="page-header__subtitle">
      <?= $product['is_active'] ? '' : 'Archived · ' ?>
      <?= $usageCount > 0 ? "Used on {$usageCount} invoice " . ($usageCount === 1 ? 'line' : 'lines') : 'Not used on any invoice yet' ?>
    </p>
  </div>
  <div class="page-header__actions">
    <form method="post" action="<?= e(url('products/delete.php')) ?>"
          data-confirm="<?= e($deleteMessage) ?>" data-confirm-title="Delete item" data-confirm-label="Delete">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
      <button class="btn btn--ghost btn--danger-text" type="submit"><?= icon('trash') ?>Delete</button>
    </form>
  </div>
</div>

<?php require __DIR__ . '/_form.php'; ?>

<?php require BASE_PATH . '/includes/footer.php'; ?>
