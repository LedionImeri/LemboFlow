<?php
/**
 * Delete a product or service. POST + CSRF only.
 */

declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
require BASE_PATH . '/src/products.php';

$userId = require_login();
verify_csrf();

$productId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$product   = find_product_or_404($userId, (int) $productId);

delete_product($userId, $product);

flash('success', "{$product['name']} deleted.");
redirect('products/');
