<?php
/**
 * Log in.
 * GET shows the form; POST checks the credentials and starts the session.
 */

declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
require BASE_PATH . '/src/accounts.php';
require BASE_PATH . '/src/demo.php';

end_demo_session_if_any();
redirect_if_logged_in();

if (is_post()) {
    verify_csrf();

    $email    = input('email');
    $password = (string) ($_POST['password'] ?? '');

    $locked = login_lock_remaining();
    if ($locked > 0) {
        with_input(['email' => $email]);
        flash('error', "Too many attempts. Try again in {$locked} seconds.");
        redirect('auth/login.php');
    }

    $errors = validate(['email' => $email, 'password' => $password], [
        'email'    => 'required|email',
        'password' => 'required',
    ]);

    if ($errors) {
        with_input(['email' => $email], $errors);
        redirect('auth/login.php');
    }

    $userId = authenticate($email, $password);

    if ($userId === null) {
        record_failed_login();
        // One message for both cases, so the form never reveals which emails have accounts.
        with_input(['email' => $email], ['password' => 'Email or password is incorrect.']);
        redirect('auth/login.php');
    }

    login_user($userId);
    redirect(intended_url());
}

$pageTitle = 'Log in';
require BASE_PATH . '/includes/auth_header.php';
?>

<div class="auth__heading">
  <h1>Welcome back</h1>
  <p>Log in to manage your invoices and payments.</p>
</div>

<form class="stack" method="post" action="<?= e(url('auth/login.php')) ?>" novalidate>
  <?= csrf_field() ?>

  <div class="field">
    <label class="field__label" for="email">Email</label>
    <input class="input" id="email" name="email" type="email" autocomplete="email" required
           value="<?= e(old('email')) ?>"<?= field_attrs('email') ?> <?= isset(errors()['password']) ? '' : 'autofocus' ?>>
    <?= field_error('email') ?>
  </div>

  <div class="field">
    <label class="field__label" for="password">Password</label>
    <div class="password-field">
      <input class="input" id="password" name="password" type="password" autocomplete="current-password" required
             <?= field_attrs('password') ?> <?= isset(errors()['password']) ? 'autofocus' : '' ?>>
      <button class="password-field__toggle" type="button" data-password-toggle aria-label="Show password" aria-pressed="false">
        <?= icon('eye') ?>
      </button>
    </div>
    <?= field_error('password') ?>
  </div>

  <button class="btn btn--primary btn--block btn--lg" type="submit">Log in</button>
</form>

<p class="auth__switch">New to LemboFlow? <a href="<?= e(url('auth/register.php')) ?>">Create an account</a></p>

<form class="auth__demo" method="post" action="<?= e(url('auth/demo.php')) ?>">
  <?= csrf_field() ?>
  <span>or</span>
  <button class="btn btn--secondary btn--block" type="submit"><?= icon('eye') ?>Explore the demo, no sign-up needed</button>
</form>

<?php require BASE_PATH . '/includes/auth_footer.php'; ?>
