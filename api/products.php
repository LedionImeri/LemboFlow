<?php
/**
 * GET api/products.php?q=&type=&status=&sort=&page=
 *   → { ok, html, total }   rendered rows for the live search on products/
 *
 * GET api/products.php?mode=picker&q=
 *   → { ok, items: [{id, name, description, type, unit, unit_price, tax_rate}] }
 *     Active items only, for the invoice form's line item picker.
 */

declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
require BASE_PATH . '/src/products.php';

$userId = require_login();

if (($_GET['mode'] ?? '') === 'picker') {
    $search = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
    json_response(['ok' => true, 'items' => search_products_for_picker($userId, $search)]);
}

$currency = current_user()['currency'] ?? 'EUR';
$filters  = product_filters_from_query($_GET);
$result   = list_products($userId, $filters['q'], $filters['type'], $filters['status'], $filters['sort'], current_page());
$hasAnyProducts = true; // the filters only exist when there are items

ob_start();
require BASE_PATH . '/products/_table.php';
$html = ob_get_clean();

json_response(['ok' => true, 'html' => $html, 'total' => $result['pagination']['total']]);
