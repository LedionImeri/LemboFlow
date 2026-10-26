<?php
/**
 * Edit a draft, sent or overdue invoice. Paid and cancelled invoices are read-only.
 */

declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
require BASE_PATH . '/src/invoices.php';
require BASE_PATH . '/src/settings.php';

$userId  = require_login();
$invoice = find_invoice_or_404($userId, query_id());

if (!in_array($invoice['status'], INVOICE_EDITABLE, true)) {
    flash('info', ucfirst($invoice['status']) . ' invoices can’t be edited.');
    redirect('invoices/view.php?id=' . $invoice['id']);
}

if (is_post()) {
    verify_csrf();

    $data              = invoice_input($_POST);
    [$errors, $totals] = validate_invoice($userId, $data, $invoice);

    if ($errors) {
        with_input($data, $errors);
        flash('error', 'Changes weren’t saved. Check the highlighted fields.');
        redirect('invoices/edit.php?id=' . $invoice['id']);
    }

    $blocked = update_invoice($userId, (int) $invoice['id'], $data, $totals);
    if ($blocked !== null) {
        flash('error', $blocked);
        redirect('invoices/view.php?id=' . $invoice['id']);
    }

    flash('success', "Invoice {$invoice['invoice_number']} saved.");
    redirect('invoices/view.php?id=' . $invoice['id']);
}

$profile  = get_business_profile($userId);
$clients  = clients_for_select($userId);
$products = products_for_picker($userId);

$savedItems = array_map(fn ($it) => [
    'product_id'  => (string) ($it['product_id'] ?? ''),
    'name'        => $it['name'],
    'description' => (string) $it['description'],
    'unit'        => (string) $it['unit'],
    'quantity'    => trim_decimal($it['quantity']),
    'unit_price'  => $it['unit_price'],
    'tax_rate'    => trim_decimal($it['tax_rate']),
], invoice_items((int) $invoice['id']));

$defaultTax   = $profile['default_tax_rate'];
$items        = old('items', $savedItems);
$currency     = $invoice['currency'];
$paymentTerms = (int) $profile['payment_terms_days'];
$action       = url('invoices/edit.php?id=' . $invoice['id']);
$mode         = 'edit';

$pageTitle   = 'Edit ' . $invoice['invoice_number'];
$activeNav   = 'invoices';
$pageScripts = ['invoice-form.js'];
require BASE_PATH . '/includes/header.php';
?>

<a class="back-link" href="<?= e(url('invoices/view.php?id=' . $invoice['id'])) ?>"><?= icon('chevron-left') ?><?= e($invoice['invoice_number']) ?></a>
<div class="page-header">
  <div>
    <h2 class="page-header__title">Edit <?= e($invoice['invoice_number']) ?></h2>
    <p class="page-header__subtitle"><?= invoice_badges($invoice) ?></p>
  </div>
</div>

<?php if ($invoice['status'] !== 'draft'): ?>
  <p class="alert alert--warning mb-5">This invoice has already been sent. If you change it, send the client the updated version.</p>
<?php endif; ?>

<?php require __DIR__ . '/_form.php'; ?>

<?php require BASE_PATH . '/includes/footer.php'; ?>
