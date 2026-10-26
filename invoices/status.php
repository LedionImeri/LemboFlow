<?php
/**
 * Change an invoice's status. POST + CSRF only.
 *   action=mark_sent  draft → sent
 *   action=cancel     draft/sent/overdue without payments → cancelled
 */

declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
require BASE_PATH . '/src/invoices.php';

$userId = require_login();
verify_csrf();

$invoiceId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$invoice   = find_invoice_or_404($userId, (int) $invoiceId);

[$error, $success] = match ($_POST['action'] ?? '') {
    'mark_sent' => [mark_invoice_sent($userId, $invoice), "Invoice {$invoice['invoice_number']} marked as sent."],
    'cancel'    => [cancel_invoice($userId, $invoice),    "Invoice {$invoice['invoice_number']} cancelled."],
    default     => abort(400, 'Unknown action.'),
};

flash($error === null ? 'success' : 'error', $error ?? $success);
redirect('invoices/view.php?id=' . $invoice['id']);
