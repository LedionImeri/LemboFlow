<?php
/**
 * Archive or restore a product. POST + CSRF only.
 * Archived items stay in reports and old invoices but leave the invoice picker.
 */

declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
require BASE_PATH . '/src/products.php';

$userId = require_login();
verify_csrf();

$productId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$product   = find_product_or_404($userId, (int) $productId);
$active    = ($_POST['active'] ?? '') === '1';

set_product_active($userId, $product, $active);

flash('success', $active ? "{$product['name']} restored." : "{$product['name']} archived. It no longer appears in the invoice picker.");

// Back to the list the user came from, keeping their filters.
$back = $_SERVER['HTTP_REFERER'] ?? '';
$base = url('products/');
redirect(str_starts_with($back, $base) ? $back : 'products/');
