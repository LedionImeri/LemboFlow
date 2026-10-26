<?php
/**
 * Invoice as PDF.
 *   GET invoices/pdf.php?id=12            → opens in the browser
 *   GET invoices/pdf.php?id=12&download=1 → downloads INV-2026-0001.pdf
 */

declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
require BASE_PATH . '/src/pdf.php';

$userId = require_login();
mark_overdue_invoices($userId);

$invoice = find_invoice_or_404($userId, query_id());
$pdf     = render_invoice_pdf($userId, $invoice);

$filename    = invoice_pdf_filename($invoice);
$disposition = isset($_GET['download']) ? 'attachment' : 'inline';

header('Content-Type: application/pdf');
header("Content-Disposition: {$disposition}; filename=\"{$filename}\"");
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

echo $pdf;
