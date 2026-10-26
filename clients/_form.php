<?php
/**
 * Client form fields, shared by create.php and edit.php.
 * Expects: $client (array of current values; empty for a new client),
 *          $action (form URL), $submitLabel, $cancelUrl
 */

if (!defined('BASE_PATH')) {
    exit;
}
?>
<form class="stack-lg form-narrow" method="post" action="<?= e($action) ?>" novalidate>
  <?= csrf_field() ?>

  <section class="card">
    <div class="card__header">
      <div>
        <h3 class="card__title">Client details</h3>
        <p class="card__subtitle">Name and VAT number appear in the “Bill to” block of invoices.</p>
      </div>
    </div>
    <div class="card__body form-grid">
      <?= form_field('name', 'Client or company name', $client['name'] ?? '', ['required' => true, 'maxlength' => 150, 'autocomplete' => 'organization', 'autofocus' => empty($client['id']), 'class' => 'span-2']) ?>
      <?= form_field('contact_name', 'Contact person', $client['contact_name'] ?? '', ['optional' => true, 'maxlength' => 100, 'autocomplete' => 'name']) ?>
      <?= form_field('vat_number', 'VAT / tax number', $client['vat_number'] ?? '', ['optional' => true, 'maxlength' => 40]) ?>
      <?= form_field('email', 'Email', $client['email'] ?? '', ['type' => 'email', 'optional' => true, 'maxlength' => 190, 'autocomplete' => 'email']) ?>
      <?= form_field('phone', 'Phone', $client['phone'] ?? '', ['type' => 'tel', 'optional' => true, 'maxlength' => 40, 'autocomplete' => 'tel']) ?>
    </div>
  </section>

  <section class="card">
    <div class="card__header">
      <h3 class="card__title">Address</h3>
    </div>
    <div class="card__body form-grid">
      <?= form_field('address_line1', 'Address line 1', $client['address_line1'] ?? '', ['optional' => true, 'maxlength' => 150, 'autocomplete' => 'address-line1', 'class' => 'span-2']) ?>
      <?= form_field('address_line2', 'Address line 2', $client['address_line2'] ?? '', ['optional' => true, 'maxlength' => 150, 'autocomplete' => 'address-line2', 'class' => 'span-2']) ?>
      <?= form_field('city', 'City', $client['city'] ?? '', ['optional' => true, 'maxlength' => 100, 'autocomplete' => 'address-level2']) ?>
      <?= form_field('postal_code', 'Postal code', $client['postal_code'] ?? '', ['optional' => true, 'maxlength' => 20, 'autocomplete' => 'postal-code']) ?>
      <?= form_field('country', 'Country', $client['country'] ?? '', ['optional' => true, 'maxlength' => 100, 'autocomplete' => 'country-name', 'class' => 'span-2']) ?>
    </div>
  </section>

  <section class="card">
    <div class="card__header">
      <div>
        <h3 class="card__title">Notes</h3>
        <p class="card__subtitle">Private. Never shown on invoices.</p>
      </div>
    </div>
    <div class="card__body">
      <?= form_field('notes', 'Internal notes', $client['notes'] ?? '', ['type' => 'textarea', 'optional' => true, 'maxlength' => 2000, 'rows' => 3]) ?>
    </div>
  </section>

  <div class="sticky-actions">
    <a class="btn btn--secondary" href="<?= e($cancelUrl) ?>">Cancel</a>
    <button class="btn btn--primary" type="submit"><?= icon('check') ?><?= e($submitLabel) ?></button>
  </div>
</form>
