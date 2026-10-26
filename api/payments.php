<?php
/**
 * GET api/payments.php?q=&method=&from=&to=&sort=&page=
 *   → { ok, html, total }   rendered rows for the live search on payments/
 */

declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
require BASE_PATH . '/src/payments.php';

$userId   = require_login();
$currency = current_user()['currency'] ?? 'EUR';

$filters        = payment_filters_from_query($_GET);
$result         = list_payments($userId, $filters, current_page());
$hasAnyPayments = true; // the filters only exist when there are payments

ob_start();
require BASE_PATH . '/payments/_table.php';
$html = ob_get_clean();

json_response(['ok' => true, 'html' => $html, 'total' => $result['pagination']['total']]);
