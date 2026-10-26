<?php
/**
 * Create an invoice. ?client_id= preselects a client (from a client's page).
 */

declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
require BASE_PATH . '/src/invoices.php';
require BASE_PATH . '/src/settings.php';

$userId  = require_login();
$profile = get_business_profile($userId);

if (is_post()) {
    verify_csrf();

    $data             = invoice_input($_POST);
    [$errors, $totals] = validate_invoice($userId, $data);

    if ($errors) {
        with_input($data, $errors);
        flash('error', 'The invoice wasn’t saved. Check the highlighted fields.');
        redirect('invoices/create.php');
    }

    $markSent  = ($_POST['intent'] ?? '') === 'send';
    $invoiceId = create_invoice($userId, $data, $totals, $profile, $markSent);

    $number = db()->prepare('SELECT invoice_number FROM invoices WHERE id = ?');
    $number->execute([$invoiceId]);
    flash('success', 'Invoice ' . $number->fetchColumn() . ($markSent ? ' saved and marked as sent.' : ' saved as draft.'));
    redirect('invoices/view.php?id=' . $invoiceId);
}

$clients  = clients_for_select($userId);
$products = products_for_picker($userId);

$terms     = (int) $profile['payment_terms_days'];
$issueDate = date('Y-m-d');
$invoice   = [
    'client_id'     => query_id('client_id') ?: '',
    'issue_date'    => $issueDate,
    'due_date'      => date('Y-m-d', strtotime("{$issueDate} +{$terms} days")),
    'discount_type' => 'none',
    'notes'         => $profile['default_notes'],
];

$defaultTax   = $profile['default_tax_rate'];
$items        = old('items', [['quantity' => '1', 'tax_rate' => (string) $defaultTax]]);
$currency     = $profile['currency'];
$paymentTerms = $terms;
$action       = url('invoices/create.php');
$mode         = 'create';

$pageTitle   = 'New invoice';
$activeNav   = 'invoices';
$pageScripts = ['invoice-form.js'];
require BASE_PATH . '/includes/header.php';
?>

<a class="back-link" href="<?= e(url('invoices/')) ?>"><?= icon('chevron-left') ?>Invoices</a>
<div class="page-header">
  <div>
    <h2 class="page-header__title">New invoice</h2>
    <p class="page-header__subtitle">The number is assigned when you save: next is <?= e(preview_next_invoice_number($userId, $profile['invoice_prefix'])) ?>.</p>
  </div>
</div>

<?php require __DIR__ . '/_form.php'; ?>

<?php require BASE_PATH . '/includes/footer.php'; ?>
