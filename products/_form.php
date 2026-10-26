<?php
/**
 * Product form, shared by create.php and edit.php.
 * Expects: $product (current values), $action, $submitLabel, $cancelUrl, $currency
 */

if (!defined('BASE_PATH')) {
    exit;
}

$type     = old('type', $product['type'] ?? 'service');
$isActive = old('is_active', (string) ($product['is_active'] ?? '1')) === '1';
?>
<form class="stack-lg form-narrow" method="post" action="<?= e($action) ?>" novalidate>
  <?= csrf_field() ?>

  <section class="card">
    <div class="card__header">
      <div>
        <h3 class="card__title">Item</h3>
        <p class="card__subtitle">Name and description are copied onto invoice lines.</p>
      </div>
    </div>
    <div class="card__body form-grid">
      <fieldset class="field span-2">
        <legend class="field__label">Type</legend>
        <div class="segmented">
          <?php foreach (PRODUCT_TYPES as $value => $label): ?>
            <label class="segmented__option">
              <input type="radio" name="type" value="<?= e($value) ?>"<?= $type === $value ? ' checked' : '' ?>>
              <span><?= icon($value === 'service' ? 'clock' : 'box', 'icon--sm') ?><?= e($label) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <?= field_error('type') ?>
      </fieldset>

      <?= form_field('name', 'Name', $product['name'] ?? '', ['required' => true, 'maxlength' => 150, 'autofocus' => empty($product['id']), 'placeholder' => 'Website design, Consulting hour, Hosting plan…', 'class' => 'span-2']) ?>
      <?= form_field('description', 'Description', $product['description'] ?? '', ['type' => 'textarea', 'optional' => true, 'maxlength' => 1000, 'rows' => 3, 'class' => 'span-2']) ?>
    </div>
  </section>

  <section class="card">
    <div class="card__header">
      <div>
        <h3 class="card__title">Pricing</h3>
        <p class="card__subtitle">Defaults for new invoice lines. You can still change them per invoice.</p>
      </div>
    </div>
    <div class="card__body form-grid form-grid--3">
      <?= form_field('unit_price', 'Price', $product['unit_price'] ?? '', ['required' => true, 'inputmode' => 'decimal', 'placeholder' => '0.00', 'suffix' => $currency, 'class' => 'field--num']) ?>
      <?= form_field('unit', 'Per', $product['unit'] ?? 'hour', ['required' => true, 'maxlength' => 20, 'list' => 'unit-options', 'hint' => 'hour, day, item…']) ?>
      <?= form_field('tax_rate', 'Tax rate', $product['tax_rate'] ?? '', ['required' => true, 'inputmode' => 'decimal', 'suffix' => '%', 'class' => 'field--num']) ?>
      <datalist id="unit-options">
        <?php foreach (PRODUCT_UNITS as $unit): ?>
          <option value="<?= e($unit) ?>"></option>
        <?php endforeach; ?>
      </datalist>
    </div>
  </section>

  <?php if (!empty($product['id'])): ?>
    <section class="card">
      <div class="card__body">
        <input type="hidden" name="is_active" value="0">
        <label class="checkbox">
          <input type="checkbox" name="is_active" value="1"<?= $isActive ? ' checked' : '' ?>>
          Active: show this item in the invoice picker
        </label>
      </div>
    </section>
  <?php else: ?>
    <input type="hidden" name="is_active" value="1">
  <?php endif; ?>

  <div class="sticky-actions">
    <a class="btn btn--secondary" href="<?= e($cancelUrl) ?>">Cancel</a>
    <button class="btn btn--primary" type="submit"><?= icon('check') ?><?= e($submitLabel) ?></button>
  </div>
</form>
