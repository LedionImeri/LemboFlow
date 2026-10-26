<?php
/**
 * Settings: business profile (with logo), account details, password.
 * One page with three tabs; each tab posts its own form back here.
 */

declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
require BASE_PATH . '/src/settings.php';

$userId = require_login();
$user   = current_user();

$tabs = [
    'business' => ['Business profile', 'invoice'],
    'account'  => ['Account',          'user'],
    'password' => ['Password',         'lock'],
];
$tab = array_key_exists($_GET['tab'] ?? '', $tabs) ? $_GET['tab'] : 'business';

// ---------------------------------------------------------------------------
// Save
// ---------------------------------------------------------------------------
if (is_post()) {
    verify_csrf();
    $form = (string) ($_POST['form'] ?? '');

    if (in_array($form, ['account', 'password'], true) && $user['is_demo']) {
        flash('warning', 'Login details of the demo account can’t be changed.');
        redirect('settings/?tab=' . $form);
    }

    switch ($form) {
        case 'business':
            $data   = business_input($_POST);
            $errors = validate_business_profile($data);

            $logoFile  = $_FILES['logo'] ?? null;
            $logoError = validate_logo_upload($logoFile);
            if ($logoError !== null) {
                $errors['logo'] = $logoError;
            }

            if ($errors) {
                with_input($data, $errors);
                flash('error', 'Some fields need your attention.');
                redirect('settings/?tab=business');
            }

            update_business_profile($userId, $data);

            if ($logoFile && $logoFile['error'] === UPLOAD_ERR_OK) {
                replace_logo($userId, store_logo($userId, $logoFile));
            } elseif (!empty($_POST['remove_logo'])) {
                replace_logo($userId, null);
            }

            flash('success', 'Business profile saved.');
            redirect('settings/?tab=business');

        case 'account':
            $data = [
                'name'             => input('name'),
                'email'            => input('email'),
                'current_password' => (string) ($_POST['current_password'] ?? ''),
            ];
            $errors = validate_account($userId, $data, $user['email']);

            if ($errors) {
                with_input($data, $errors);
                redirect('settings/?tab=account');
            }

            update_account($userId, $data['name'], $data['email']);
            flash('success', 'Account details saved.');
            redirect('settings/?tab=account');

        case 'password':
            $data = [
                'current_password'      => (string) ($_POST['current_password'] ?? ''),
                'password'              => (string) ($_POST['password'] ?? ''),
                'password_confirmation' => (string) ($_POST['password_confirmation'] ?? ''),
            ];
            $errors = validate_password_change($userId, $data);

            if ($errors) {
                with_input([], $errors);
                redirect('settings/?tab=password');
            }

            change_password($userId, $data['password']);
            flash('success', 'Password changed.');
            redirect('settings/?tab=password');

        default:
            abort(400, 'Unknown form.');
    }
}

// ---------------------------------------------------------------------------
// Show
// ---------------------------------------------------------------------------
$profile    = get_business_profile($userId);
$nextNumber = preview_next_invoice_number($userId, (string) old('invoice_prefix', $profile['invoice_prefix']));

$addressLines = array_filter([
    $profile['address_line1'],
    $profile['address_line2'],
    trim(($profile['postal_code'] ?? '') . ' ' . ($profile['city'] ?? '')),
    $profile['country'],
]);

$pageTitle = 'Settings';
$activeNav   = 'settings';
$pageScripts = ['settings.js'];
require BASE_PATH . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h2 class="page-header__title">Settings</h2>
    <p class="page-header__subtitle">Your business details appear on every invoice you send.</p>
  </div>
</div>

<nav class="tabs" aria-label="Settings sections">
  <?php foreach ($tabs as $key => [$label, $iconName]): ?>
    <a class="tabs__link<?= $tab === $key ? ' is-active' : '' ?>"
       href="<?= e(url('settings/?tab=' . $key)) ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>>
      <?= icon($iconName) ?><?= e($label) ?>
    </a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'business'): ?>

<div class="settings-layout">
  <form class="stack-lg" method="post" action="<?= e(url('settings/?tab=business')) ?>" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="form" value="business">
    <input type="hidden" name="MAX_FILE_SIZE" value="<?= LOGO_MAX_BYTES ?>">

    <section class="card">
      <div class="card__header">
        <div>
          <h3 class="card__title">Business details</h3>
          <p class="card__subtitle">Shown in the “From” block of your invoices.</p>
        </div>
      </div>
      <div class="card__body form-grid">
        <?= form_field('business_name', 'Business name', $profile['business_name'], ['required' => true, 'maxlength' => 150, 'autocomplete' => 'organization', 'class' => 'span-2']) ?>
        <?= form_field('email', 'Business email', $profile['email'], ['type' => 'email', 'optional' => true, 'maxlength' => 190, 'autocomplete' => 'email']) ?>
        <?= form_field('phone', 'Phone', $profile['phone'], ['type' => 'tel', 'optional' => true, 'maxlength' => 40, 'autocomplete' => 'tel']) ?>
        <?= form_field('website', 'Website', $profile['website'], ['optional' => true, 'maxlength' => 190, 'placeholder' => 'example.com', 'inputmode' => 'url', 'class' => 'span-2']) ?>
      </div>
    </section>

    <section class="card">
      <div class="card__header">
        <h3 class="card__title">Address</h3>
      </div>
      <div class="card__body form-grid">
        <?= form_field('address_line1', 'Address line 1', $profile['address_line1'], ['optional' => true, 'maxlength' => 150, 'autocomplete' => 'address-line1', 'class' => 'span-2']) ?>
        <?= form_field('address_line2', 'Address line 2', $profile['address_line2'], ['optional' => true, 'maxlength' => 150, 'autocomplete' => 'address-line2', 'class' => 'span-2']) ?>
        <?= form_field('city', 'City', $profile['city'], ['optional' => true, 'maxlength' => 100, 'autocomplete' => 'address-level2']) ?>
        <?= form_field('postal_code', 'Postal code', $profile['postal_code'], ['optional' => true, 'maxlength' => 20, 'autocomplete' => 'postal-code']) ?>
        <?= form_field('country', 'Country', $profile['country'], ['optional' => true, 'maxlength' => 100, 'autocomplete' => 'country-name', 'class' => 'span-2']) ?>
      </div>
    </section>

    <section class="card">
      <div class="card__header">
        <div>
          <h3 class="card__title">Tax &amp; banking</h3>
          <p class="card__subtitle">Bank details are printed as payment instructions.</p>
        </div>
      </div>
      <div class="card__body form-grid">
        <?= form_field('vat_number', 'VAT / tax number', $profile['vat_number'], ['optional' => true, 'maxlength' => 40]) ?>
        <?= form_field('bank_name', 'Bank name', $profile['bank_name'], ['optional' => true, 'maxlength' => 100]) ?>
        <?= form_field('iban', 'IBAN', format_iban($profile['iban']), ['optional' => true, 'maxlength' => 42, 'placeholder' => 'AL47 2121 1009 0000 0002 3569 8741', 'hint' => 'Spaces are fine, the checksum is verified.']) ?>
        <?= form_field('swift_bic', 'SWIFT / BIC', $profile['swift_bic'], ['optional' => true, 'maxlength' => 11]) ?>
      </div>
    </section>

    <section class="card">
      <div class="card__header">
        <div>
          <h3 class="card__title">Invoice defaults</h3>
          <p class="card__subtitle">Used for new invoices. Existing invoices keep their own values.</p>
        </div>
      </div>
      <div class="card__body form-grid">
        <?= form_field('currency', 'Currency', $profile['currency'], ['type' => 'select', 'options' => SUPPORTED_CURRENCIES, 'required' => true]) ?>
        <?= form_field('invoice_prefix', 'Invoice prefix', $profile['invoice_prefix'], ['required' => true, 'maxlength' => 10, 'hint' => 'Next invoice number: ' . $nextNumber]) ?>
        <?= form_field('payment_terms_days', 'Payment terms', $profile['payment_terms_days'], ['type' => 'number', 'required' => true, 'min' => 0, 'max' => 365, 'step' => 1, 'inputmode' => 'numeric', 'suffix' => 'days', 'hint' => 'Due date = issue date + this many days.']) ?>
        <?= form_field('default_tax_rate', 'Default tax rate', $profile['default_tax_rate'], ['type' => 'number', 'required' => true, 'min' => 0, 'max' => 100, 'step' => '0.01', 'inputmode' => 'decimal', 'suffix' => '%']) ?>
        <?= form_field('default_notes', 'Default invoice notes', $profile['default_notes'], ['type' => 'textarea', 'optional' => true, 'maxlength' => 2000, 'rows' => 3, 'placeholder' => 'Thank you for your business!', 'class' => 'span-2']) ?>
      </div>
    </section>

    <section class="card">
      <div class="card__header">
        <div>
          <h3 class="card__title">Logo</h3>
          <p class="card__subtitle">PNG, JPG or WebP, up to 2 MB. A wide logo on a transparent background looks best.</p>
        </div>
      </div>
      <div class="card__body logo-upload">
        <div class="logo-upload__preview" data-logo-preview>
          <?php if ($profile['logo_path']): ?>
            <img src="<?= e(url($profile['logo_path'])) ?>" alt="Current logo">
          <?php else: ?>
            <span class="logo-upload__placeholder"><?= e(initials($profile['business_name'])) ?></span>
          <?php endif; ?>
        </div>
        <div class="stack">
          <div class="field">
            <label class="field__label" for="logo"><?= $profile['logo_path'] ? 'Replace logo' : 'Upload logo' ?></label>
            <input class="file-input" id="logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp"
                   data-logo-input<?= field_attrs('logo') ?>>
            <?= field_error('logo') ?>
          </div>
          <?php if ($profile['logo_path']): ?>
            <label class="checkbox">
              <input type="checkbox" name="remove_logo" value="1"> Remove current logo
            </label>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <div class="sticky-actions">
      <button class="btn btn--primary" type="submit"><?= icon('check') ?>Save business profile</button>
    </div>
  </form>

  <aside class="settings-aside">
    <section class="card">
      <div class="card__header">
        <div>
          <h3 class="card__title">On your invoices</h3>
          <p class="card__subtitle">Preview of your saved details</p>
        </div>
      </div>
      <div class="card__body invoice-from">
        <?php if ($profile['logo_path']): ?>
          <img class="invoice-from__logo" src="<?= e(url($profile['logo_path'])) ?>" alt="">
        <?php endif; ?>
        <p class="invoice-from__name"><?= e($profile['business_name']) ?></p>
        <?php foreach ($addressLines as $line): ?>
          <p><?= e($line) ?></p>
        <?php endforeach; ?>
        <?php if ($profile['vat_number']): ?><p>VAT: <?= e($profile['vat_number']) ?></p><?php endif; ?>
        <?php if ($profile['email']): ?><p><?= e($profile['email']) ?></p><?php endif; ?>
        <?php if ($profile['phone']): ?><p><?= e($profile['phone']) ?></p><?php endif; ?>

        <div class="invoice-from__pay">
          <p class="invoice-from__label">Payment details</p>
          <?php if ($profile['iban']): ?>
            <?php if ($profile['bank_name']): ?><p><?= e($profile['bank_name']) ?></p><?php endif; ?>
            <p class="num">IBAN <?= e(format_iban($profile['iban'])) ?></p>
            <?php if ($profile['swift_bic']): ?><p>SWIFT <?= e($profile['swift_bic']) ?></p><?php endif; ?>
          <?php else: ?>
            <p class="muted">Add your IBAN so clients know where to pay.</p>
          <?php endif; ?>
        </div>

        <dl class="invoice-from__meta">
          <div><dt>Currency</dt><dd><?= e($profile['currency']) ?></dd></div>
          <div><dt>Terms</dt><dd><?= (int) $profile['payment_terms_days'] ?> days</dd></div>
          <div><dt>Next number</dt><dd class="num"><?= e(preview_next_invoice_number($userId, $profile['invoice_prefix'])) ?></dd></div>
        </dl>
      </div>
    </section>
  </aside>
</div>

<?php elseif ($tab === 'account'): ?>

<form class="card settings-narrow" method="post" action="<?= e(url('settings/?tab=account')) ?>" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="form" value="account">
  <div class="card__header">
    <div>
      <h3 class="card__title">Account</h3>
      <p class="card__subtitle">Your name and the email you log in with.</p>
    </div>
  </div>
  <div class="card__body stack">
    <?= form_field('name', 'Your name', $user['name'], ['required' => true, 'maxlength' => 100, 'autocomplete' => 'name']) ?>
    <?= form_field('email', 'Login email', $user['email'], ['type' => 'email', 'required' => true, 'maxlength' => 190, 'autocomplete' => 'email']) ?>
    <?= form_field('current_password', 'Current password', '', ['type' => 'password', 'autocomplete' => 'current-password', 'hint' => 'Only needed when you change your email.']) ?>
  </div>
  <div class="card__footer form-actions">
    <button class="btn btn--primary" type="submit"><?= icon('check') ?>Save account</button>
  </div>
</form>

<?php else: ?>

<form class="card settings-narrow" method="post" action="<?= e(url('settings/?tab=password')) ?>" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="form" value="password">
  <div class="card__header">
    <div>
      <h3 class="card__title">Change password</h3>
      <p class="card__subtitle">At least 8 characters, with a letter and a number.</p>
    </div>
  </div>
  <div class="card__body stack">
    <?= form_field('current_password', 'Current password', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'current-password']) ?>
    <?= form_field('password', 'New password', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'minlength' => 8]) ?>
    <?= form_field('password_confirmation', 'Confirm new password', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password']) ?>
  </div>
  <div class="card__footer form-actions">
    <button class="btn btn--primary" type="submit"><?= icon('check') ?>Change password</button>
  </div>
</form>

<?php endif; ?>

<?php require BASE_PATH . '/includes/footer.php'; ?>
