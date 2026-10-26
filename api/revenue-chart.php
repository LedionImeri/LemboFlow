<?php
/**
 * GET api/revenue-chart.php?months=12
 *   → { ok, months: [{month, label, short, total, payments, current}], total }
 * Revenue = payments received, grouped by payment month.
 */

declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
require BASE_PATH . '/src/stats.php';

$userId = require_login();
$months = in_array((int) ($_GET['months'] ?? 12), [6, 12], true) ? (int) $_GET['months'] : 12;

$series = revenue_by_month($userId, $months);

json_response([
    'ok'     => true,
    'months' => $series,
    'total'  => array_sum(array_column($series, 'total')),
]);
