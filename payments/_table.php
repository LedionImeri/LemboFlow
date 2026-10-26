<?php
/**
 * Payment list results. Rendered by payments/index.php and api/payments.php.
 * Expects: $result (from list_payments), $filters, $currency, $hasAnyPayments
 */

if (!defined('BASE_PATH')) {
    exit;
}

$rows    = $result['rows'];
$summary = $result['summary'];
?>
<?php if ($rows): ?>
  <div class="list-summary">
    <span><strong><?= (int) $summary['count'] ?></strong> <?= (int) $summary['count'] === 1 ? 'payment' : 'payments' ?></span>
    <span>Received <strong class="num"><?= e(money($summary['received'], $currency)) ?></strong></span>
  </div>

  <div class="table-wrap">
    <table class="table table--stack">
      <thead>
        <tr>
          <th scope="col">Date</th>
          <th scope="col">Invoice</th>
          <th scope="col">Client</th>
          <th scope="col">Method</th>
          <th scope="col">Reference</th>
          <th scope="col" class="num">Amount</th>
          <th scope="col" class="actions"><span class="sr-only">Actions</span></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $p): ?>
          <tr>
            <td data-label=""><span class="table__primary"><?= e(format_date($p['paid_on'])) ?></span></td>
            <td data-label="Invoice"><a class="num" href="<?= e(url('invoices/view.php?id=' . $p['invoice_id'])) ?>"><?= e($p['invoice_number']) ?></a></td>
            <td data-label="Client"><a href="<?= e(url('clients/view.php?id=' . $p['client_id'])) ?>"><?= e($p['client_name']) ?></a></td>
            <td data-label="Method"><?= e(PAYMENT_METHODS[$p['method']] ?? $p['method']) ?></td>
            <td data-label="Reference"><?= e($p['reference'] ?: '—') ?></td>
            <td data-label="Amount" class="num"><strong class="text-success"><?= e(money($p['amount'], $p['currency'])) ?></strong></td>
            <td data-label="" class="actions">
              <form class="inline-form" method="post" action="<?= e(url('payments/delete.php')) ?>"
                    data-confirm="Delete this payment of <?= e(money($p['amount'], $p['currency'])) ?> for <?= e($p['invoice_number']) ?>? The invoice will show it as unpaid again."
                    data-confirm-title="Delete payment" data-confirm-label="Delete payment">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <input type="hidden" name="return" value="payments">
                <button class="btn btn--ghost btn--sm btn--icon" type="submit" aria-label="Delete payment" title="Delete"><?= icon('trash') ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php elseif (!$hasAnyPayments): ?>
  <div class="empty">
    <span class="empty__icon"><?= icon('card') ?></span>
    <p class="empty__title">No payments yet</p>
    <p class="empty__text">Open an invoice and choose “Record payment” when a client pays. Revenue on your dashboard comes from these payments.</p>
    <div class="empty__actions">
      <a class="btn btn--secondary" href="<?= e(url('invoices/?status=sent')) ?>"><?= icon('invoice') ?>Open invoices</a>
    </div>
  </div>
<?php else: ?>
  <div class="empty">
    <span class="empty__icon"><?= icon('search') ?></span>
    <p class="empty__title">No payments match</p>
    <p class="empty__text">Try another search, method or date range.</p>
  </div>
<?php endif; ?>

<?= render_pagination($result['pagination'], 'payments/', [
    'q'      => $filters['q'],
    'method' => $filters['method'],
    'from'   => $filters['from'],
    'to'     => $filters['to'],
    'sort'   => $filters['sort'] === 'newest' ? '' : $filters['sort'],
], 'payments') ?>
