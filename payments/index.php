<?php
/**
 * All payments received: search, method filter, date range.
 */

declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
require BASE_PATH . '/src/payments.php';

$userId   = require_login();
$currency = current_user()['currency'] ?? 'EUR';

$filters        = payment_filters_from_query($_GET);
$result         = list_payments($userId, $filters, current_page());
$hasAnyPayments = $result['pagination']['total'] > 0 || payment_has_any($userId);

$methodChips = ['' => 'All'] + PAYMENT_METHODS;

$pageTitle   = 'Payments';
$activeNav   = 'payments';
$pageScripts = ['search.js'];
require BASE_PATH . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h2 class="page-header__title">Payments</h2>
    <p class="page-header__subtitle">Money actually received. This is what your revenue is built from.</p>
  </div>
</div>

<section class="card">
  <?php if ($hasAnyPayments): ?>
    <form class="toolbar toolbar--stacked" method="get" action="<?= e(url('payments/')) ?>" role="search"
          data-live-search data-endpoint="<?= e(url('api/payments.php')) ?>" data-target="#payment-results">
      <div class="toolbar__row">
        <div class="input-icon">
          <?= icon('search') ?>
          <label class="sr-only" for="payment-search">Search payments</label>
          <input class="input" id="payment-search" name="q" type="search" value="<?= e($filters['q']) ?>"
                 placeholder="Invoice, client or reference…" autocomplete="off">
        </div>
        <div class="date-range">
          <label class="sr-only" for="from">Paid from</label>
          <input class="input" id="from" name="from" type="date" value="<?= e($filters['from']) ?>" title="Paid from">
          <span class="muted" aria-hidden="true">–</span>
          <label class="sr-only" for="to">Paid until</label>
          <input class="input" id="to" name="to" type="date" value="<?= e($filters['to']) ?>" title="Paid until">
        </div>
        <label class="sr-only" for="payment-sort">Sort by</label>
        <select class="select toolbar__select" id="payment-sort" name="sort">
          <?php foreach (PAYMENT_SORTS as $value => $label): ?>
            <option value="<?= e($value) ?>"<?= $filters['sort'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <fieldset class="chip-group">
        <legend class="sr-only">Method</legend>
        <?php foreach ($methodChips as $value => $label): ?>
          <label class="chip-radio">
            <input type="radio" name="method" value="<?= e($value) ?>"<?= $filters['method'] === $value ? ' checked' : '' ?>>
            <span class="chip"><?= e($label) ?></span>
          </label>
        <?php endforeach; ?>
      </fieldset>
      <noscript><button class="btn btn--secondary" type="submit">Apply</button></noscript>
    </form>
  <?php endif; ?>

  <div id="payment-results" class="results" aria-live="polite">
    <?php require __DIR__ . '/_table.php'; ?>
  </div>
</section>

<?php require BASE_PATH . '/includes/footer.php'; ?>
