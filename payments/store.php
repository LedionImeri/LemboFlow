<?php
/**
 * Record a payment for an invoice. POST + CSRF only.
 *   mode=full    "Mark as paid": one payment for the whole balance, dated today
 *   (default)    the amount, date and method from the Record payment window
 * On errors the invoice page reopens the window with the values and messages.
 */

declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
require BASE_PATH . '/src/payments.php';

$userId = require_login();
verify_csrf();

$invoiceId = filter_var($_POST['invoice_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$invoice   = find_invoice_or_404($userId, (int) $invoiceId);
$back      = 'invoices/view.php?id=' . $invoice['id'];

// Paid or cancelled: the payment window isn't shown on those invoices, so explain with a toast.
$blocker = payment_blocker($invoice);
if ($blocker !== null) {
    flash('error', $blocker);
    redirect($back);
}

if (($_POST['mode'] ?? '') === 'full') {
    [$paymentId, $errors] = mark_invoice_paid($userId, $invoice);
    if ($errors) {
        flash('error', reset($errors));
        redirect($back);
    }
    flash('success', "Invoice {$invoice['invoice_number']} marked as paid.");
    redirect($back);
}

$data = payment_input($_POST);
[$paymentId, $errors] = record_payment($userId, (int) $invoice['id'], $data);

if ($errors) {
    // Paid in full by another request in the meantime: no window to reopen.
    $fresh = find_invoice($userId, (int) $invoice['id']);
    if (payment_blocker($fresh) !== null) {
        flash('error', payment_blocker($fresh));
        redirect($back);
    }
    with_input($data + ['_open_modal' => 'payment-modal'], $errors);
    redirect($back);
}

$fresh = find_invoice($userId, (int) $invoice['id']);
flash('success', $fresh['status'] === 'paid'
    ? 'Payment recorded. ' . $invoice['invoice_number'] . ' is now paid in full.'
    : 'Payment of ' . money($data['amount'], $invoice['currency']) . ' recorded. Balance due: ' . money(invoice_balance($fresh), $invoice['currency']) . '.');
redirect($back);
