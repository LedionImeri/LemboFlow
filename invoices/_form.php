<?php
/**
 * Invoice form, shared by create.php and edit.php.
 *
 * Expects: $invoice (current values; for a new invoice only defaults), $items (lines),
 *          $clients, $products, $currency, $paymentTerms, $action, $mode ('create'|'edit')
 *
 * Totals shown here are a live preview (assets/js/invoice-form.js). The server
 * recalculates everything from quantity, price and tax when the form is saved.
 */

if (!defined('BASE_PATH')) {
    exit;
}

if (!function_exists('invoice_line_html')) {
    /** One line item. $i is the row index, or "__INDEX__" inside the <template>. */
    function invoice_line_html(int|string $i, array $item): string
    {
        $name  = fn (string $f) => "items[{$i}][{$f}]";
        $id    = fn (string $f) => "items-{$i}-{$f}";
        $err   = fn (string $f) => errors()["items.{$i}.{$f}"] ?? null;
        $attrs = fn (string $f) => $err($f) ? ' aria-invalid="true" aria-describedby="' . $id($f) . '-error"' : '';
        $msg   = fn (string $f) => $err($f) ? '<p class="field__error" id="' . $id($f) . '-error">' . e($err($f)) . '</p>' : '';

        ob_start(); ?>
        <div class="line" data-line>
          <input type="hidden" name="<?= $name('product_id') ?>" value="<?= e($item['product_id'] ?? '') ?>" data-field="product_id">

          <div class="line__cell line__cell--name">
            <label class="line__label" for="<?= $id('name') ?>">Item</label>
            <input class="input" id="<?= $id('name') ?>" name="<?= $name('name') ?>" value="<?= e($item['name'] ?? '') ?>"
                   maxlength="150" placeholder="What are you charging for?" data-field="name"<?= $attrs('name') ?>>
            <?= $msg('name') ?>
            <label class="sr-only" for="<?= $id('description') ?>">Description</label>
            <input class="input input--subtle" id="<?= $id('description') ?>" name="<?= $name('description') ?>"
                   value="<?= e($item['description'] ?? '') ?>" maxlength="1000" placeholder="Description (optional)" data-field="description">
          </div>

          <div class="line__cell line__cell--qty">
            <label class="line__label" for="<?= $id('quantity') ?>">Qty</label>
            <input class="input input--num" id="<?= $id('quantity') ?>" name="<?= $name('quantity') ?>"
                   value="<?= e($item['quantity'] ?? '1') ?>" inputmode="decimal" data-field="quantity"<?= $attrs('quantity') ?>>
            <?= $msg('quantity') ?>
          </div>

          <div class="line__cell line__cell--unit">
            <label class="line__label" for="<?= $id('unit') ?>">Unit</label>
            <input class="input" id="<?= $id('unit') ?>" name="<?= $name('unit') ?>" value="<?= e($item['unit'] ?? '') ?>"
                   maxlength="20" placeholder="hour" list="unit-options" data-field="unit"<?= $attrs('unit') ?>>
            <?= $msg('unit') ?>
          </div>

          <div class="line__cell line__cell--price">
            <label class="line__label" for="<?= $id('unit_price') ?>">Price</label>
            <input class="input input--num" id="<?= $id('unit_price') ?>" name="<?= $name('unit_price') ?>"
                   value="<?= e($item['unit_price'] ?? '') ?>" inputmode="decimal" placeholder="0.00" data-field="unit_price"<?= $attrs('unit_price') ?>>
            <?= $msg('unit_price') ?>
          </div>

          <div class="line__cell line__cell--tax">
            <label class="line__label" for="<?= $id('tax_rate') ?>">Tax %</label>
            <input class="input input--num" id="<?= $id('tax_rate') ?>" name="<?= $name('tax_rate') ?>"
                   value="<?= e($item['tax_rate'] ?? '0') ?>" inputmode="decimal" data-field="tax_rate"<?= $attrs('tax_rate') ?>>
            <?= $msg('tax_rate') ?>
          </div>

          <div class="line__cell line__cell--total">
            <span class="line__label">Amount</span>
            <output class="line__total num" data-line-total>—</output>
          </div>

          <div class="line__cell line__cell--remove">
            <button class="btn btn--ghost btn--sm btn--icon" type="button" data-remove-line aria-label="Remove line" title="Remove line">
              <?= icon('trash') ?>
            </button>
          </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

$moneyTemplate = money(0, $currency);
$paidAlready   = (float) ($invoice['amount_paid'] ?? 0);
$discountType  = old('discount_type', $invoice['discount_type'] ?? 'none');
$productsJson  = array_map(fn ($p) => [
    'id'          => (int) $p['id'],
    'name'        => $p['name'],
    'description' => $p['description'],
    'unit'        => $p['unit'],
    'unit_price'  => $p['unit_price'],
    'tax_rate'    => $p['tax_rate'],
], $products);
?>
<form class="invoice-form" id="invoice-form" method="post" action="<?= e($action) ?>" novalidate
      data-invoice-form
      data-money-template="<?= e($moneyTemplate) ?>"
      data-payment-terms="<?= (int) $paymentTerms ?>"
      data-paid="<?= e(hundredths_to_decimal(to_hundredths(number_format($paidAlready, 2, '.', '')))) ?>">
  <?= csrf_field() ?>

  <div class="invoice-form__main stack-lg">
    <section class="card">
      <div class="card__header">
        <h3 class="card__title">Details</h3>
        <?php if ($mode === 'edit'): ?>
          <span class="num muted"><?= e($invoice['invoice_number']) ?></span>
        <?php endif; ?>
      </div>
      <div class="card__body form-grid form-grid--3">
        <div class="field">
          <label class="field__label" for="client_id">Client</label>
          <?php if ($clients): ?>
            <select class="select" id="client_id" name="client_id" required<?= field_attrs('client_id') ?>>
              <option value="">Choose a client…</option>
              <?php $selectedClient = (string) old('client_id', (string) ($invoice['client_id'] ?? '')); ?>
              <?php foreach ($clients as $c): ?>
                <option value="<?= (int) $c['id'] ?>"<?= $selectedClient === (string) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          <?php else: ?>
            <p class="alert alert--warning">You have no clients yet. <a href="<?= e(url('clients/create.php')) ?>">Add a client</a> first.</p>
          <?php endif; ?>
          <?= field_error('client_id') ?>
        </div>
        <?= form_field('issue_date', 'Issue date', $invoice['issue_date'] ?? date('Y-m-d'), ['type' => 'date', 'required' => true]) ?>
        <?= form_field('due_date', 'Due date', $invoice['due_date'] ?? '', ['type' => 'date', 'required' => true, 'hint' => "Default: {$paymentTerms} days after issue"]) ?>
      </div>
    </section>

    <section class="card">
      <div class="card__header">
        <h3 class="card__title">Items</h3>
        <?php if ($products): ?>
          <div class="item-picker">
            <label class="sr-only" for="product-picker">Add from your products and services</label>
            <select class="select" id="product-picker" data-product-picker>
              <option value="">+ Add from catalog…</option>
              <?php foreach ($products as $p): ?>
                <option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?> · <?= e(money($p['unit_price'], $currency)) ?>/<?= e($p['unit']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>
      </div>

      <div class="lines">
        <div class="lines__head" aria-hidden="true">
          <span>Item</span><span class="num">Qty</span><span>Unit</span><span class="num">Price</span><span class="num">Tax %</span><span class="num">Amount</span><span></span>
        </div>
        <div class="lines__body" data-lines>
          <?php foreach ($items as $i => $item): ?>
            <?= invoice_line_html($i, $item) ?>
          <?php endforeach; ?>
        </div>
        <?php if (isset(errors()['items'])): ?>
          <p class="alert alert--danger lines__error" role="alert"><?= e(errors()['items']) ?></p>
        <?php endif; ?>
        <div class="lines__foot">
          <button class="btn btn--secondary btn--sm" type="button" data-add-line><?= icon('plus') ?>Add line</button>
        </div>
      </div>

      <template id="line-template"><?= invoice_line_html('__INDEX__', ['quantity' => '1', 'tax_rate' => (string) $defaultTax]) ?></template>
      <datalist id="unit-options">
        <?php foreach (['hour', 'day', 'week', 'month', 'project', 'item', 'piece', 'license'] as $unit): ?>
          <option value="<?= e($unit) ?>"></option>
        <?php endforeach; ?>
      </datalist>
      <script type="application/json" id="products-data"><?= json_encode($productsJson, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
    </section>

    <section class="card">
      <div class="card__header">
        <div>
          <h3 class="card__title">Notes</h3>
          <p class="card__subtitle">Printed at the bottom of the invoice.</p>
        </div>
      </div>
      <div class="card__body">
        <?= form_field('notes', 'Notes for the client', $invoice['notes'] ?? '', ['type' => 'textarea', 'optional' => true, 'maxlength' => 2000, 'rows' => 3]) ?>
      </div>
    </section>
  </div>

  <aside class="invoice-form__summary">
    <section class="card summary-card">
      <div class="card__header">
        <h3 class="card__title">Summary</h3>
        <span class="muted text-sm"><?= e($currency) ?></span>
      </div>
      <div class="card__body">
        <dl class="totals">
          <div><dt>Subtotal</dt><dd class="num" data-out="subtotal"><?= e($moneyTemplate) ?></dd></div>
          <div><dt>Tax</dt><dd class="num" data-out="tax"><?= e($moneyTemplate) ?></dd></div>
        </dl>

        <div class="discount">
          <div class="discount__controls">
            <label class="sr-only" for="discount_type">Discount type</label>
            <select class="select select--sm" id="discount_type" name="discount_type" data-discount-type>
              <?php foreach (INVOICE_DISCOUNT_TYPES as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= $discountType === $value ? ' selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
            <label class="sr-only" for="discount_value">Discount value</label>
            <input class="input input--num input--sm" id="discount_value" name="discount_value" inputmode="decimal"
                   value="<?= e(old('discount_value', ($invoice['discount_type'] ?? 'none') === 'none' ? '' : ($invoice['discount_value'] ?? ''))) ?>"
                   placeholder="0" data-discount-value<?= field_attrs('discount_value') ?>>
          </div>
          <?= field_error('discount_value') ?>
          <dl class="totals">
            <div><dt>Discount</dt><dd class="num" data-out="discount">−<?= e($moneyTemplate) ?></dd></div>
          </dl>
        </div>

        <dl class="totals totals--grand">
          <div><dt>Total</dt><dd class="num" data-out="total"><?= e($moneyTemplate) ?></dd></div>
          <?php if ($paidAlready > 0): ?>
            <div class="totals__muted"><dt>Already paid</dt><dd class="num"><?= e(money($paidAlready, $currency)) ?></dd></div>
            <div><dt>Balance due</dt><dd class="num" data-out="balance"><?= e($moneyTemplate) ?></dd></div>
          <?php endif; ?>
        </dl>
      </div>
      <div class="card__footer summary-card__actions">
        <?php if ($mode === 'create'): ?>
          <button class="btn btn--primary btn--block" type="submit" name="intent" value="send"><?= icon('send') ?>Save &amp; mark as sent</button>
          <button class="btn btn--secondary btn--block" type="submit" name="intent" value="draft">Save as draft</button>
        <?php else: ?>
          <button class="btn btn--primary btn--block" type="submit" name="intent" value="save"><?= icon('check') ?>Save changes</button>
          <a class="btn btn--ghost btn--block" href="<?= e(url('invoices/view.php?id=' . $invoice['id'])) ?>">Cancel</a>
        <?php endif; ?>
      </div>
    </section>
  </aside>

  <!-- Phones: the total stays visible while editing lines -->
  <div class="mobile-total-bar">
    <div>
      <span class="mobile-total-bar__label">Total</span>
      <strong class="num" data-out="total"><?= e($moneyTemplate) ?></strong>
    </div>
    <button class="btn btn--primary" type="submit" name="intent" value="<?= $mode === 'create' ? 'send' : 'save' ?>">
      <?= $mode === 'create' ? 'Save & send' : 'Save' ?>
    </button>
  </div>
</form>
