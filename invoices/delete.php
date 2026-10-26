<?php
/**
 * Delete a draft or cancelled invoice without payments. POST + CSRF only.
 * Sent invoices are cancelled instead, so issued numbers never disappear.
 */

declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
require BASE_PATH . '/src/invoices.php';

$userId = require_login();
verify_csrf();

$invoiceId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$invoice   = find_invoice_or_404($userId, (int) $invoiceId);

$blocked = delete_invoice($userId, $invoice);

if ($blocked !== null) {
    flash('error', $blocked);
    redirect('invoices/view.php?id=' . $invoice['id']);
}

flash('success', "Invoice {$invoice['invoice_number']} deleted.");
redirect('invoices/');
