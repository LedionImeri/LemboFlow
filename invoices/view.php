<?php
/**
 * One invoice, laid out like the document the client receives, with actions
 * and payment progress.
 */

declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
require BASE_PATH . '/src/payments.php';
require BASE_PATH . '/src/settings.php';

$userId = require_login();
mark_overdue_invoices($userId);

$invoice  = find_invoice_or_404($userId, query_id());
$items    = invoice_items((int) $invoice['id']);
$payments = invoice_payments((int) $invoice['id']);
$profile  = get_business_profile($userId);
$currency = $invoice['currency'];

$isEditable   = in_array($invoice['status'], INVOICE_EDITABLE, true);
$canCancel    = $isEditable && (float) $invoice['amount_paid'] <= 0;
$deleteReason = invoice_delete_blocker($invoice);
$canPay       = payment_blocker($invoice) === null;
$balanceInput = hundredths_to_decimal(invoice_balance_cents($invoice));
$balance      = invoice_balance($invoice);
// Drafts show the full total as what will be due; issued invoices show what is still owed.
$amountDue    = $invoice['status'] === 'draft' ? (float) $invoice['total'] : $balance;
$paidPercent  = (float) $invoice['total'] > 0
    ? min(100, (int) round((float) $invoice['amount_paid'] / (float) $invoice['total'] * 100))
    : 0;

$from = array_filter([
    $profile['address_line1'],
    $profile['address_line2'],
    trim(($profile['postal_code'] ?? '') . ' ' . ($profile['city'] ?? '')),
    $profile['country'],
]);
$to = array_filter([
    $invoice['client_contact'],
    $invoice['client_address_line1'],
    $invoice['client_address_line2'],
    trim(($invoice['client_postal_code'] ?? '') . ' ' . ($invoice['client_city'] ?? '')),
    $invoice['client_country'],
]);

$daysLate = $invoice['status'] === 'overdue'
    ? (int) (new DateTime($invoice['due_date']))->diff(new DateTime('today'))->days
    : 0;

$pageTitle = $invoice['invoice_number'];
$activeNav = 'invoices';
require BASE_PATH . '/includes/header.php';
?>

<a class="back-link" href="<?= e(url('invoices/')) ?>"><?= icon('chevron-left') ?>Invoices</a>

<div class="page-header">
  <div>
    <h2 class="page-header__title num"><?= e($invoice['invoice_number']) ?></h2>
    <p class="page-header__subtitle row">
      <?= invoice_badges($invoice) ?>
      <span>
        <?= e($invoice['client_name']) ?> ·
        <?php if ($invoice['status'] === 'overdue'): ?>
          <strong class="text-danger"><?= $daysLate ?> <?= $daysLate === 1 ? 'day' : 'days' ?> overdue</strong>
        <?php elseif ($invoice['status'] === 'paid'): ?>
          Paid <?= e(format_date($invoice['paid_at'])) ?>
        <?php else: ?>
          Due <?= e(format_date($invoice['due_date'])) ?>
        <?php endif; ?>
      </span>
    </p>
  </div>

  <div class="page-header__actions">
    <?php if ($invoice['status'] === 'draft'): ?>
      <form method="post" action="<?= e(url('invoices/status.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $invoice['id'] ?>">
        <input type="hidden" name="action" value="mark_sent">
        <button class="btn btn--primary" type="submit"><?= icon('send') ?>Mark as sent</button>
      </form>
    <?php endif; ?>

    <?php if ($canPay): ?>
      <button class="btn <?= $invoice['status'] === 'draft' ? 'btn--secondary' : 'btn--primary' ?>" type="button" data-modal-open="payment-modal">
        <?= icon('wallet') ?>Record payment
      </button>
    <?php endif; ?>

    <div class="btn-group">
      <a class="btn btn--secondary" href="<?= e(url('invoices/pdf.php?id=' . $invoice['id'] . '&download=1')) ?>"><?= icon('download') ?>PDF</a>
      <a class="btn btn--secondary btn--icon" href="<?= e(url('invoices/pdf.php?id=' . $invoice['id'])) ?>" target="_blank" rel="noopener"
         aria-label="Preview PDF in a new tab" title="Preview PDF"><?= icon('eye') ?></a>
    </div>

    <?php if ($isEditable): ?>
      <a class="btn btn--secondary" href="<?= e(url('invoices/edit.php?id=' . $invoice['id'])) ?>"><?= icon('edit') ?>Edit</a>
    <?php endif; ?>

    <?php if ($canPay || $canCancel || $deleteReason === null): ?>
      <details class="dropdown" data-dropdown>
        <summary class="btn btn--secondary btn--icon" aria-label="More actions"><?= icon('more') ?></summary>
        <div class="dropdown__menu" role="menu">
          <?php if ($canPay): ?>
            <form method="post" action="<?= e(url('payments/store.php')) ?>"
                  data-confirm="Record a payment of <?= e(money($balanceInput, $currency)) ?> dated today and mark <?= e($invoice['invoice_number']) ?> as paid?"
                  data-confirm-title="Mark as paid" data-confirm-label="Mark as paid" data-confirm-variant="primary">
              <?= csrf_field() ?>
              <input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>">
              <input type="hidden" name="mode" value="full">
              <button class="dropdown__item" role="menuitem" type="submit"><?= icon('check-circle') ?>Mark as paid</button>
            </form>
          <?php endif; ?>
          <?php if ($canCancel): ?>
            <form method="post" action="<?= e(url('invoices/status.php')) ?>"
                  data-confirm="Cancel <?= e($invoice['invoice_number']) ?>? It stays in your records as cancelled and no longer counts as owed."
                  data-confirm-title="Cancel invoice" data-confirm-label="Cancel invoice">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $invoice['id'] ?>">
              <input type="hidden" name="action" value="cancel">
              <button class="dropdown__item" role="menuitem" type="submit"><?= icon('x') ?>Cancel invoice</button>
            </form>
          <?php endif; ?>
          <?php if ($deleteReason === null): ?>
            <form method="post" action="<?= e(url('invoices/delete.php')) ?>"
                  data-confirm="Delete <?= e($invoice['invoice_number']) ?> permanently? This can’t be undone."
                  data-confirm-title="Delete invoice" data-confirm-label="Delete">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $invoice['id'] ?>">
              <button class="dropdown__item dropdown__item--danger" role="menuitem" type="submit"><?= icon('trash') ?>Delete</button>
            </form>
          <?php endif; ?>
        </div>
      </details>
    <?php endif; ?>
  </div>
</div>

<div class="invoice-layout">
  <article class="card invoice-doc" aria-label="Invoice <?= e($invoice['invoice_number']) ?>">
    <header class="invoice-doc__head">
      <div>
        <?php if ($profile['logo_path']): ?>
          <img class="invoice-doc__logo" src="<?= e(url($profile['logo_path'])) ?>" alt="<?= e($profile['business_name']) ?>">
        <?php else: ?>
          <p class="invoice-doc__brand"><?= e($profile['business_name']) ?></p>
        <?php endif; ?>
      </div>
      <div class="invoice-doc__title">
        <h3>Invoice</h3>
        <p class="num"><?= e($invoice['invoice_number']) ?></p>
      </div>
    </header>

    <div class="invoice-doc__parties">
      <div>
        <p class="invoice-doc__label">From</p>
        <p class="invoice-doc__party"><?= e($profile['business_name']) ?></p>
        <?php foreach ($from as $line): ?><p><?= e($line) ?></p><?php endforeach; ?>
        <?php if ($profile['vat_number']): ?><p>VAT <?= e($profile['vat_number']) ?></p><?php endif; ?>
        <?php if ($profile['email']): ?><p><?= e($profile['email']) ?></p><?php endif; ?>
      </div>
      <div>
        <p class="invoice-doc__label">Bill to</p>
        <p class="invoice-doc__party">
          <a href="<?= e(url('clients/view.php?id=' . $invoice['client_id'])) ?>"><?= e($invoice['client_name']) ?></a>
        </p>
        <?php foreach ($to as $line): ?><p><?= e($line) ?></p><?php endforeach; ?>
        <?php if ($invoice['client_vat_number']): ?><p>VAT <?= e($invoice['client_vat_number']) ?></p><?php endif; ?>
        <?php if ($invoice['client_email']): ?><p><?= e($invoice['client_email']) ?></p><?php endif; ?>
      </div>
      <dl class="invoice-doc__dates">
        <div><dt>Issued</dt><dd><?= e(format_date($invoice['issue_date'])) ?></dd></div>
        <div><dt>Due</dt><dd><?= e(format_date($invoice['due_date'])) ?></dd></div>
        <div><dt>Amount due</dt><dd class="num"><strong><?= e(money($amountDue, $currency)) ?></strong></dd></div>
      </dl>
    </div>

    <div class="table-wrap">
      <table class="table invoice-doc__items">
        <thead>
          <tr>
            <th scope="col">Description</th>
            <th scope="col" class="num">Qty</th>
            <th scope="col" class="num">Price</th>
            <th scope="col" class="num">Tax</th>
            <th scope="col" class="num">Amount</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $item): ?>
            <tr>
              <td>
                <span class="table__primary"><?= e($item['name']) ?></span>
                <?php if ($item['description']): ?><span class="table__secondary"><?= e($item['description']) ?></span><?php endif; ?>
              </td>
              <td class="num"><?= e(trim_decimal($item['quantity'])) ?><?= $item['unit'] ? ' <span class="muted">' . e($item['unit']) . '</span>' : '' ?></td>
              <td class="num"><?= e(money($item['unit_price'], $currency)) ?></td>
              <td class="num"><?= e(trim_decimal($item['tax_rate'])) ?>%</td>
              <td class="num"><?= e(money($item['line_subtotal'], $currency)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="invoice-doc__foot">
      <div class="invoice-doc__notes">
        <?php if ($invoice['notes']): ?>
          <p class="invoice-doc__label">Notes</p>
          <p><?= nl2br(e($invoice['notes'])) ?></p>
        <?php endif; ?>
        <?php if ($profile['iban']): ?>
          <p class="invoice-doc__label">Payment details</p>
          <?php if ($profile['bank_name']): ?><p><?= e($profile['bank_name']) ?></p><?php endif; ?>
          <p class="num">IBAN <?= e(format_iban($profile['iban'])) ?></p>
          <?php if ($profile['swift_bic']): ?><p>SWIFT <?= e($profile['swift_bic']) ?></p><?php endif; ?>
          <p>Reference: <?= e($invoice['invoice_number']) ?></p>
        <?php endif; ?>
      </div>
      <dl class="totals invoice-doc__totals">
        <div><dt>Subtotal</dt><dd class="num"><?= e(money($invoice['subtotal'], $currency)) ?></dd></div>
        <div><dt>Tax</dt><dd class="num"><?= e(money($invoice['tax_total'], $currency)) ?></dd></div>
        <?php if ((float) $invoice['discount_amount'] > 0): ?>
          <div>
            <dt>Discount<?= $invoice['discount_type'] === 'percent' ? ' (' . e(trim_decimal($invoice['discount_value'])) . '%)' : '' ?></dt>
            <dd class="num">−<?= e(money($invoice['discount_amount'], $currency)) ?></dd>
          </div>
        <?php endif; ?>
        <div class="totals__grand"><dt>Total</dt><dd class="num"><?= e(money($invoice['total'], $currency)) ?></dd></div>
        <?php if ((float) $invoice['amount_paid'] > 0): ?>
          <div class="totals__muted"><dt>Paid</dt><dd class="num">−<?= e(money($invoice['amount_paid'], $currency)) ?></dd></div>
          <div><dt>Balance due</dt><dd class="num"><strong><?= e(money($balance, $currency)) ?></strong></dd></div>
        <?php endif; ?>
      </dl>
    </div>
  </article>

  <aside class="stack">
    <section class="card">
      <div class="card__header">
        <h3 class="card__title">Payments</h3>
        <span class="num muted text-sm"><?= $paidPercent ?>%</span>
      </div>
      <div class="card__body stack">
        <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $paidPercent ?>"
             aria-label="Paid <?= $paidPercent ?> percent">
          <span style="width: <?= $paidPercent ?>%"></span>
        </div>
        <dl class="totals">
          <div><dt>Paid</dt><dd class="num"><?= e(money($invoice['amount_paid'], $currency)) ?></dd></div>
          <div><dt>Balance</dt><dd class="num"><strong><?= e(money($balance, $currency)) ?></strong></dd></div>
        </dl>

        <?php if ($payments): ?>
          <ul class="payment-list">
            <?php foreach ($payments as $p): ?>
              <li>
                <span>
                  <strong class="num"><?= e(money($p['amount'], $currency)) ?></strong>
                  <small class="muted"><?= e(format_date($p['paid_on'])) ?> · <?= e(PAYMENT_METHODS[$p['method']] ?? $p['method']) ?><?= $p['reference'] ? ' · ' . e($p['reference']) : '' ?></small>
                </span>
                <form method="post" action="<?= e(url('payments/delete.php')) ?>"
                      data-confirm="Delete this payment of <?= e(money($p['amount'], $currency)) ?>? The balance due goes up again."
                      data-confirm-title="Delete payment" data-confirm-label="Delete payment">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                  <button class="btn btn--ghost btn--sm btn--icon" type="submit" aria-label="Delete payment" title="Delete payment"><?= icon('trash') ?></button>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php elseif ($invoice['status'] !== 'cancelled'): ?>
          <p class="muted text-sm">No payments recorded yet.</p>
        <?php endif; ?>

        <?php if ($canPay): ?>
          <button class="btn btn--secondary btn--block" type="button" data-modal-open="payment-modal"><?= icon('plus') ?>Record payment</button>
        <?php endif; ?>
      </div>
    </section>

    <section class="card">
      <div class="card__header"><h3 class="card__title">Timeline</h3></div>
      <ul class="card__body timeline">
        <li><span>Created</span><time><?= e(format_date($invoice['created_at'], 'M j, Y H:i')) ?></time></li>
        <?php if ($invoice['sent_at']): ?><li><span>Sent</span><time><?= e(format_date($invoice['sent_at'], 'M j, Y H:i')) ?></time></li><?php endif; ?>
        <?php if ($invoice['paid_at']): ?><li><span>Paid in full</span><time><?= e(format_date($invoice['paid_at'], 'M j, Y H:i')) ?></time></li><?php endif; ?>
        <?php if ($invoice['cancelled_at']): ?><li><span>Cancelled</span><time><?= e(format_date($invoice['cancelled_at'], 'M j, Y H:i')) ?></time></li><?php endif; ?>
        <li><span>Last change</span><time><?= e(format_date($invoice['updated_at'], 'M j, Y H:i')) ?></time></li>
      </ul>
    </section>
  </aside>
</div>

<?php if ($canPay): ?>
<dialog class="modal" id="payment-modal" aria-labelledby="payment-modal-title"<?= old('_open_modal') === 'payment-modal' ? ' data-open-on-load' : '' ?>>
  <form method="post" action="<?= e(url('payments/store.php')) ?>" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>">
    <div class="modal__header">
      <h2 class="modal__title" id="payment-modal-title">Record payment</h2>
      <button class="btn btn--icon btn--ghost" type="button" data-modal-close aria-label="Close"><?= icon('x') ?></button>
    </div>
    <div class="modal__body stack">
      <p class="muted text-sm">
        <?= e($invoice['invoice_number']) ?> · Balance due <strong class="num"><?= e(money($balanceInput, $currency)) ?></strong>
        <?php if ($invoice['status'] === 'draft'): ?><br>This draft will be marked as sent.<?php endif; ?>
      </p>
      <div class="form-grid">
        <?= form_field('amount', 'Amount', $balanceInput, ['required' => true, 'inputmode' => 'decimal', 'suffix' => $currency, 'class' => 'field--num', 'autofocus' => true]) ?>
        <?= form_field('paid_on', 'Payment date', date('Y-m-d'), ['type' => 'date', 'required' => true, 'max' => date('Y-m-d')]) ?>
        <?= form_field('method', 'Method', 'bank_transfer', ['type' => 'select', 'options' => PAYMENT_METHODS, 'required' => true]) ?>
        <?= form_field('reference', 'Reference', '', ['optional' => true, 'maxlength' => 100, 'placeholder' => 'Bank reference, receipt no.…']) ?>
        <?= form_field('notes', 'Note', '', ['optional' => true, 'maxlength' => 255, 'class' => 'span-2']) ?>
      </div>
    </div>
    <div class="modal__footer">
      <button class="btn btn--secondary" type="button" data-modal-close>Cancel</button>
      <button class="btn btn--primary" type="submit"><?= icon('check') ?>Record payment</button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<?php require BASE_PATH . '/includes/footer.php'; ?>
