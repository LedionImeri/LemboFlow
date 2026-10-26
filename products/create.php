<?php
/**
 * Add a product or service.
 */

declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
require BASE_PATH . '/src/products.php';
require BASE_PATH . '/src/settings.php';

$userId = require_login();

if (is_post()) {
    verify_csrf();

    $data   = product_input($_POST);
    $errors = validate_product($data);

    if ($errors) {
        with_input($data, $errors);
        redirect('products/create.php');
    }

    create_product($userId, $data);
    flash('success', PRODUCT_TYPES[$data['type']] . " {$data['name']} added.");
    redirect('products/');
}

$profile = get_business_profile($userId);

// New items start with the business's default tax rate.
$product     = ['tax_rate' => $profile['default_tax_rate']];
$currency    = $profile['currency'];
$action      = url('products/create.php');
$submitLabel = 'Add item';
$cancelUrl   = url('products/');

$pageTitle = 'Add item';
$activeNav = 'products';
require BASE_PATH . '/includes/header.php';
?>

<a class="back-link" href="<?= e($cancelUrl) ?>"><?= icon('chevron-left') ?>Products &amp; services</a>
<div class="page-header">
  <div>
    <h2 class="page-header__title">Add product or service</h2>
  </div>
</div>

<?php require __DIR__ . '/_form.php'; ?>

<?php require BASE_PATH . '/includes/footer.php'; ?>
