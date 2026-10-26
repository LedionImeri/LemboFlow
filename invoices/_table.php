<?php
/**
 * Invoice list results: summary strip, table and pagination, or an empty state.
 * Rendered by invoices/index.php and by api/invoices.php for live search.
 *
 * Expects: $result (from list_invoices), $filters, $currency, $hasAnyInvoices
 */

if (!defined('BASE_PATH')) {
    exit;
}

$rows      = $result['rows'];
$summary   = $result['summary'];
$filtering = $filters['q'] !== '' || $filters['status'] !== '' || $filters['from'] !== '' || $filters['to'] !== '';
?>
<?php if ($rows): ?>
  <div class="list-summary">
    <span><strong><?= (int) $summary['count'] ?></strong> <?= (int) $summary['count'] === 1 ? 'invoice' : 'invoices' ?></span>
    <span>Billed <strong class="num"><?= e(money($summary['billed'], $currency)) ?></strong></span>
    <span>Outstanding <strong class="num"><?= e(money($summary['outstanding'], $currency)) ?></strong></span>
  </div>

  <div class="table-wrap">
    <table class="table table--stack">
      <thead>
        <tr>
          <th scope="col">Invoice</th>
          <th scope="col">Client</th>
          <th scope="col">Issued</th>
          <th scope="col">Due</th>
          <th scope="col">Status</th>
          <th scope="col" class="num">Total</th>
          <th scope="col" class="num">Balance</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $inv): ?>
          <?php $balance = invoice_balance($inv); ?>
          <tr class="<?= $inv['status'] === 'cancelled' ? 'is-archived' : '' ?>">
            <td data-label="">
              <a class="table__primary num" href="<?= e(url('invoices/view.php?id=' . $inv['id'])) ?>"><?= e($inv['invoice_number']) ?></a>
              <span class="table__secondary show-mobile"><?= e($inv['client_name']) ?></span>
            </td>
            <td data-label="Client" class="hide-mobile">
              <a href="<?= e(url('clients/view.php?id=' . $inv['client_id'])) ?>"><?= e($inv['client_name']) ?></a>
            </td>
            <td data-label="Issued"><?= e(format_date($inv['issue_date'])) ?></td>
            <td data-label="Due" class="<?= $inv['status'] === 'overdue' ? 'text-danger' : '' ?>"><?= e(format_date($inv['due_date'])) ?></td>
            <td data-label="Status"><span class="badges"><?= invoice_badges($inv) ?></span></td>
            <td data-label="Total" class="num"><?= e(money($inv['total'], $inv['currency'])) ?></td>
            <td data-label="Balance" class="num">
              <?= $balance > 0 ? '<strong>' . e(money($balance, $inv['currency'])) . '</strong>' : '<span class="muted">—</span>' ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php elseif (!$hasAnyInvoices): ?>
  <div class="empty">
    <span class="empty__icon"><?= icon('invoice') ?></span>
    <p class="empty__title">No invoices yet</p>
    <p class="empty__text">Create your first invoice. Numbers like INV-<?= date('Y') ?>-0001 are assigned automatically.</p>
    <div class="empty__actions">
      <a class="btn btn--primary" href="<?= e(url('invoices/create.php')) ?>"><?= icon('plus') ?>New invoice</a>
    </div>
  </div>
<?php else: ?>
  <div class="empty">
    <span class="empty__icon"><?= icon('search') ?></span>
    <p class="empty__title">No invoices match</p>
    <p class="empty__text">
      <?= $filters['q'] !== '' ? 'Nothing found for “' . e($filters['q']) . '”. ' : '' ?>Try another search, status or date range.
    </p>
  </div>
<?php endif; ?>

<?= render_pagination($result['pagination'], 'invoices/', [
    'q'      => $filters['q'],
    'status' => $filters['status'],
    'from'   => $filters['from'],
    'to'     => $filters['to'],
    'sort'   => $filters['sort'] === 'newest' ? '' : $filters['sort'],
], 'invoices') ?>
