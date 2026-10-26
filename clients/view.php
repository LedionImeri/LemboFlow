<?php
/**
 * One client: contact details, money totals and all of their invoices.
 */

declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
require BASE_PATH . '/src/clients.php';
require BASE_PATH . '/src/invoices.php';

$userId   = require_login();
mark_overdue_invoices($userId);
$client   = find_client_or_404($userId, query_id());
$totals   = client_totals($userId, (int) $client['id']);
$invoices = client_invoices($userId, (int) $client['id']);
$currency = current_user()['currency'] ?? 'EUR';

$addressLines = array_filter([
    $client['address_line1'],
    $client['address_line2'],
    trim(($client['postal_code'] ?? '') . ' ' . ($client['city'] ?? '')),
    $client['country'],
]);

$kpis = [
    ['Billed',      'invoice',      money($totals['billed'], $currency),      'Issued invoices'],
    ['Paid',        'check-circle', money($totals['paid'], $currency),        'Payments received'],
    ['Outstanding', 'clock',        money($totals['outstanding'], $currency), 'Still to be paid'],
    ['Invoices',    'inbox',        (string) (int) $totals['invoice_count'],  $totals['last_invoice_date'] ? 'Last on ' . format_date($totals['last_invoice_date']) : 'None yet'],
];

$pageTitle = $client['name'];
$activeNav = 'clients';
require BASE_PATH . '/includes/header.php';
?>

<a class="back-link" href="<?= e(url('clients/')) ?>"><?= icon('chevron-left') ?>Clients</a>
<div class="page-header">
  <div class="row">
    <span class="avatar avatar--lg" aria-hidden="true"><?= e(initials($client['name'])) ?></span>
    <div>
      <h2 class="page-header__title"><?= e($client['name']) ?></h2>
      <p class="page-header__subtitle">Client since <?= e(format_date($client['created_at'])) ?></p>
    </div>
  </div>
  <div class="page-header__actions">
    <form method="post" action="<?= e(url('clients/delete.php')) ?>"
          data-confirm="Delete <?= e($client['name']) ?>? This can’t be undone."
          data-confirm-title="Delete client" data-confirm-label="Delete client">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $client['id'] ?>">
      <button class="btn btn--ghost btn--danger-text" type="submit"><?= icon('trash') ?>Delete</button>
    </form>
    <a class="btn btn--secondary" href="<?= e(url('clients/edit.php?id=' . $client['id'])) ?>"><?= icon('edit') ?>Edit</a>
    <a class="btn btn--primary" href="<?= e(url('invoices/create.php?client_id=' . $client['id'])) ?>"><?= icon('plus') ?>New invoice</a>
  </div>
</div>

<div class="stack-lg">
  <section class="kpi-grid" aria-label="Client totals">
    <?php foreach ($kpis as [$label, $iconName, $value, $meta]): ?>
      <article class="card kpi">
        <p class="kpi__label"><?= icon($iconName) ?><?= e($label) ?></p>
        <p class="kpi__value"><?= e($value) ?></p>
        <p class="kpi__meta"><?= e($meta) ?></p>
      </article>
    <?php endforeach; ?>
  </section>

  <div class="detail-layout">
    <section class="card">
      <div class="card__header">
        <h3 class="card__title">Invoices</h3>
        <?php if ($invoices): ?>
          <span class="muted text-sm"><?= count($invoices) ?> total</span>
        <?php endif; ?>
      </div>

      <?php if (!$invoices): ?>
        <div class="empty">
          <span class="empty__icon"><?= icon('invoice') ?></span>
          <p class="empty__title">No invoices for <?= e($client['name']) ?> yet</p>
          <p class="empty__text">Invoices you create for this client will be listed here with their payment status.</p>
          <div class="empty__actions">
            <a class="btn btn--primary" href="<?= e(url('invoices/create.php?client_id=' . $client['id'])) ?>"><?= icon('plus') ?>Create invoice</a>
          </div>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table table--stack">
            <thead>
              <tr>
                <th scope="col">Invoice</th>
                <th scope="col">Issued</th>
                <th scope="col">Due</th>
                <th scope="col">Status</th>
                <th scope="col" class="num">Total</th>
                <th scope="col" class="num">Balance</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($invoices as $inv): ?>
                <?php $balance = invoice_balance($inv); ?>
                <tr>
                  <td data-label="">
                    <a class="table__primary num" href="<?= e(url('invoices/view.php?id=' . $inv['id'])) ?>"><?= e($inv['invoice_number']) ?></a>
                  </td>
                  <td data-label="Issued"><?= e(format_date($inv['issue_date'])) ?></td>
                  <td data-label="Due"><?= e(format_date($inv['due_date'])) ?></td>
                  <td data-label="Status"><span class="badges"><?= invoice_badges($inv) ?></span></td>
                  <td data-label="Total" class="num"><?= e(money($inv['total'], $inv['currency'])) ?></td>
                  <td data-label="Balance" class="num">
                    <?= $balance <= 0
                        ? '<span class="muted">—</span>'
                        : '<strong>' . e(money($balance, $inv['currency'])) . '</strong>' ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <aside class="card">
      <div class="card__header">
        <h3 class="card__title">Details</h3>
      </div>
      <dl class="card__body details-list">
        <div>
          <dt>Contact</dt>
          <dd><?= e($client['contact_name'] ?: '—') ?></dd>
        </div>
        <div>
          <dt>Email</dt>
          <dd><?= $client['email'] ? '<a href="mailto:' . e($client['email']) . '">' . e($client['email']) . '</a>' : '—' ?></dd>
        </div>
        <div>
          <dt>Phone</dt>
          <dd><?= $client['phone'] ? '<a href="tel:' . e(preg_replace('/[^\d+]/', '', $client['phone'])) . '">' . e($client['phone']) . '</a>' : '—' ?></dd>
        </div>
        <div>
          <dt>Address</dt>
          <dd><?= $addressLines ? implode('<br>', array_map('e', $addressLines)) : '—' ?></dd>
        </div>
        <div>
          <dt>VAT number</dt>
          <dd><?= e($client['vat_number'] ?: '—') ?></dd>
        </div>
        <?php if ($client['notes']): ?>
          <div>
            <dt>Notes</dt>
            <dd class="details-list__notes"><?= nl2br(e($client['notes'])) ?></dd>
          </div>
        <?php endif; ?>
      </dl>
    </aside>
  </div>
</div>

<?php require BASE_PATH . '/includes/footer.php'; ?>
