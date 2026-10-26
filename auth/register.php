<?php
/**
 * Create an account.
 * One transaction creates the user and their business profile, then logs them in.
 */

declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
require BASE_PATH . '/src/accounts.php';
require BASE_PATH . '/src/demo.php';

end_demo_session_if_any();
redirect_if_logged_in();

if (is_post()) {
    verify_csrf();

    $data = [
        'name'                  => input('name'),
        'business_name'         => input('business_name'),
        'email'                 => input('email'),
        'password'              => (string) ($_POST['password'] ?? ''),
        'password_confirmation' => (string) ($_POST['password_confirmation'] ?? ''),
    ];

    $errors = validate_registration($data);

    if ($errors) {
        with_input($data, $errors);
        redirect('auth/register.php');
    }

    try {
        $userId = register_account($data['name'], $data['business_name'], $data['email'], $data['password']);
    } catch (PDOException $e) {
        // Two sign-ups with the same email at the same moment: the UNIQUE key catches the second.
        if ($e->getCode() === '23000') {
            with_input($data, ['email' => 'An account with this email already exists.']);
            redirect('auth/register.php');
        }
        throw $e;
    }

    login_user($userId);
    flash('success', 'Welcome to LemboFlow, ' . $data['name'] . '! Your account is ready.');
    redirect('dashboard/');
}

$pageTitle = 'Create account';
require BASE_PATH . '/includes/auth_header.php';
?>

<div class="auth__heading">
  <h1>Create your account</h1>
  <p>Start sending invoices in a couple of minutes.</p>
</div>

<form class="stack" method="post" action="<?= e(url('auth/register.php')) ?>" novalidate>
  <?= csrf_field() ?>

  <div class="form-grid">
    <div class="field">
      <label class="field__label" for="name">Your name</label>
      <input class="input" id="name" name="name" type="text" autocomplete="name" required maxlength="100"
             value="<?= e(old('name')) ?>"<?= field_attrs('name') ?> autofocus>
      <?= field_error('name') ?>
    </div>

    <div class="field">
      <label class="field__label" for="business_name">Business name</label>
      <input class="input" id="business_name" name="business_name" type="text" autocomplete="organization" required maxlength="150"
             value="<?= e(old('business_name')) ?>"<?= field_attrs('business_name') ?>>
      <?= field_error('business_name') ?>
    </div>
  </div>

  <div class="field">
    <label class="field__label" for="email">Email</label>
    <input class="input" id="email" name="email" type="email" autocomplete="email" required maxlength="190"
           value="<?= e(old('email')) ?>"<?= field_attrs('email') ?>>
    <?= field_error('email') ?>
  </div>

  <div class="field">
    <label class="field__label" for="password">Password</label>
    <div class="password-field">
      <input class="input" id="password" name="password" type="password" autocomplete="new-password" required minlength="8"
             <?= isset(errors()['password']) ? field_attrs('password') : 'aria-describedby="password-hint"' ?>>
      <button class="password-field__toggle" type="button" data-password-toggle aria-label="Show password" aria-pressed="false">
        <?= icon('eye') ?>
      </button>
    </div>
    <?= field_error('password') ?: '<p class="field__hint" id="password-hint">At least 8 characters, with a letter and a number.</p>' ?>
  </div>

  <div class="field">
    <label class="field__label" for="password_confirmation">Confirm password</label>
    <input class="input" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required
           <?= field_attrs('password_confirmation') ?>>
    <?= field_error('password_confirmation') ?>
  </div>

  <button class="btn btn--primary btn--block btn--lg" type="submit">Create account</button>
</form>

<p class="auth__switch">Already have an account? <a href="<?= e(url('auth/login.php')) ?>">Log in</a></p>

<?php require BASE_PATH . '/includes/auth_footer.php'; ?>
