<?php
/**
 * Invoice list: live search (number or client), status chips, date range, sorting.
 * Overdue invoices are detected on every load.
 */

declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
require BASE_PATH . '/src/invoices.php';

$userId   = require_login();
$currency = current_user()['currency'] ?? 'EUR';

mark_overdue_invoices($userId);

$filters        = invoice_filters_from_query($_GET);
$result         = list_invoices($userId, $filters, current_page());
$hasAnyInvoices = $result['pagination']['total'] > 0 || invoice_has_any($userId);

$statusChips = ['' => 'All', 'draft' => 'Draft', 'sent' => 'Sent', 'partial' => 'Partial', 'overdue' => 'Overdue', 'paid' => 'Paid', 'cancelled' => 'Cancelled'];

$pageTitle   = 'Invoices';
$activeNav   = 'invoices';
$pageScripts = ['search.js'];
require BASE_PATH . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h2 class="page-header__title">Invoices</h2>
    <p class="page-header__subtitle">Everything you've billed, and what's still open.</p>
  </div>
  <div class="page-header__actions">
    <a class="btn btn--primary" href="<?= e(url('invoices/create.php')) ?>"><?= icon('plus') ?>New invoice</a>
  </div>
</div>

<section class="card">
  <?php if ($hasAnyInvoices): ?>
    <form class="toolbar toolbar--stacked" method="get" action="<?= e(url('invoices/')) ?>" role="search"
          data-live-search data-endpoint="<?= e(url('api/invoices.php')) ?>" data-target="#invoice-results">
      <div class="toolbar__row">
        <div class="input-icon">
          <?= icon('search') ?>
          <label class="sr-only" for="invoice-search">Search invoices</label>
          <input class="input" id="invoice-search" name="q" type="search" value="<?= e($filters['q']) ?>"
                 placeholder="Invoice number or client…" autocomplete="off">
        </div>
        <div class="date-range">
          <label class="sr-only" for="from">Issued from</label>
          <input class="input" id="from" name="from" type="date" value="<?= e($filters['from']) ?>" title="Issued from">
          <span class="muted" aria-hidden="true">–</span>
          <label class="sr-only" for="to">Issued until</label>
          <input class="input" id="to" name="to" type="date" value="<?= e($filters['to']) ?>" title="Issued until">
        </div>
        <label class="sr-only" for="invoice-sort">Sort by</label>
        <select class="select toolbar__select" id="invoice-sort" name="sort">
          <?php foreach (INVOICE_SORTS as $value => $label): ?>
            <option value="<?= e($value) ?>"<?= $filters['sort'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <fieldset class="chip-group">
        <legend class="sr-only">Status</legend>
        <?php foreach ($statusChips as $value => $label): ?>
          <label class="chip-radio">
            <input type="radio" name="status" value="<?= e($value) ?>"<?= $filters['status'] === $value ? ' checked' : '' ?>>
            <span class="chip"><?= e($label) ?></span>
          </label>
        <?php endforeach; ?>
      </fieldset>
      <noscript><button class="btn btn--secondary" type="submit">Apply</button></noscript>
    </form>
  <?php endif; ?>

  <div id="invoice-results" class="results" aria-live="polite">
    <?php require __DIR__ . '/_table.php'; ?>
  </div>
</section>

<?php require BASE_PATH . '/includes/footer.php'; ?>
