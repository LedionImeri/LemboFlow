<?php
/**
 * GET api/invoices.php?q=&status=&from=&to=&sort=&page=
 *   → { ok, html, total }   rendered rows for the live search on invoices/
 */

declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
require BASE_PATH . '/src/invoices.php';

$userId   = require_login();
$currency = current_user()['currency'] ?? 'EUR';

$filters        = invoice_filters_from_query($_GET);
$result         = list_invoices($userId, $filters, current_page());
$hasAnyInvoices = true; // the filters only exist when there are invoices

ob_start();
require BASE_PATH . '/invoices/_table.php';
$html = ob_get_clean();

json_response(['ok' => true, 'html' => $html, 'total' => $result['pagination']['total']]);
