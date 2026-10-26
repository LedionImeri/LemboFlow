<?php
/**
 * GET api/clients.php?q=&sort=&page=
 *   → { ok, html, total }   rendered rows for the live search on clients/
 *
 * GET api/clients.php?mode=picker&q=
 *   → { ok, items: [{id, name, email, city}] }   for the invoice form's client picker
 */

declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
require BASE_PATH . '/src/clients.php';

$userId = require_login();
$search = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);

if (($_GET['mode'] ?? '') === 'picker') {
    json_response(['ok' => true, 'items' => search_clients_for_picker($userId, $search)]);
}

$sort     = array_key_exists($_GET['sort'] ?? '', CLIENT_SORTS) ? $_GET['sort'] : 'name';
$currency = current_user()['currency'] ?? 'EUR';

$result        = list_clients($userId, $search, $sort, current_page());
$hasAnyClients = true; // the search box only exists when there are clients

ob_start();
require BASE_PATH . '/clients/_table.php';
$html = ob_get_clean();

json_response(['ok' => true, 'html' => $html, 'total' => $result['pagination']['total']]);
