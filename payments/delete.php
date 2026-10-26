<?php
/**
 * Delete a payment. POST + CSRF only.
 * The invoice's paid amount and status are recalculated right away.
 */

declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
require BASE_PATH . '/src/payments.php';

$userId = require_login();
verify_csrf();

$paymentId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$payment   = find_payment($userId, (int) $paymentId) ?? abort(404);

delete_payment($userId, $payment);

flash('success', 'Payment of ' . money($payment['amount'], $payment['currency']) . " for {$payment['invoice_number']} deleted.");

// Back to where the user came from: the payments list or the invoice.
redirect(($_POST['return'] ?? '') === 'payments' ? 'payments/' : 'invoices/view.php?id=' . $payment['invoice_id']);
