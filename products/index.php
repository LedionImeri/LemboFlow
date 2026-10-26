<?php
/**
 * Products & services list: live search, type and status filters, sorting.
 */

declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
require BASE_PATH . '/src/products.php';

$userId   = require_login();
$currency = current_user()['currency'] ?? 'EUR';

$filters = product_filters_from_query($_GET);
$result  = list_products($userId, $filters['q'], $filters['type'], $filters['status'], $filters['sort'], current_page());
$hasAnyProducts = $result['pagination']['total'] > 0 || product_has_any($userId);

$typeChips   = ['' => 'All', 'service' => 'Services', 'product' => 'Products'];
$statusChips = ['active' => 'Active', 'archived' => 'Archived', 'all' => 'All'];

$pageTitle   = 'Products & Services';
$activeNav   = 'products';
$pageScripts = ['search.js'];
require BASE_PATH . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h2 class="page-header__title">Products &amp; services</h2>
    <p class="page-header__subtitle">What you sell, ready to drop into an invoice with its price and tax.</p>
  </div>
  <div class="page-header__actions">
    <a class="btn btn--primary" href="<?= e(url('products/create.php')) ?>"><?= icon('plus') ?>Add item</a>
  </div>
</div>

<section class="card">
  <?php if ($hasAnyProducts): ?>
    <form class="toolbar" method="get" action="<?= e(url('products/')) ?>" role="search"
          data-live-search data-endpoint="<?= e(url('api/products.php')) ?>" data-target="#product-results">
      <div class="input-icon">
        <?= icon('search') ?>
        <label class="sr-only" for="product-search">Search products and services</label>
        <input class="input" id="product-search" name="q" type="search" value="<?= e($filters['q']) ?>"
               placeholder="Search name or description…" autocomplete="off">
      </div>

      <fieldset class="chip-group">
        <legend class="sr-only">Type</legend>
        <?php foreach ($typeChips as $value => $label): ?>
          <label class="chip-radio">
            <input type="radio" name="type" value="<?= e($value) ?>"<?= $filters['type'] === $value ? ' checked' : '' ?>>
            <span class="chip"><?= e($label) ?></span>
          </label>
        <?php endforeach; ?>
      </fieldset>

      <fieldset class="chip-group">
        <legend class="sr-only">Status</legend>
        <?php foreach ($statusChips as $value => $label): ?>
          <label class="chip-radio">
            <input type="radio" name="status" value="<?= e($value) ?>"<?= $filters['status'] === $value ? ' checked' : '' ?>>
            <span class="chip"><?= e($label) ?></span>
          </label>
        <?php endforeach; ?>
      </fieldset>

      <label class="sr-only" for="product-sort">Sort by</label>
      <select class="select toolbar__select" id="product-sort" name="sort">
        <?php foreach (PRODUCT_SORTS as $value => $label): ?>
          <option value="<?= e($value) ?>"<?= $filters['sort'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <noscript><button class="btn btn--secondary" type="submit">Apply</button></noscript>
    </form>
  <?php endif; ?>

  <div id="product-results" class="results" aria-live="polite">
    <?php require __DIR__ . '/_table.php'; ?>
  </div>
</section>

<?php require BASE_PATH . '/includes/footer.php'; ?>
