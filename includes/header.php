<?php
/**
 * App layout: opening half. Set these before requiring it:
 *
 *   $pageTitle   string  Shown in the topbar and the <title>
 *   $activeNav   string  dashboard | invoices | clients | products | payments | settings
 *   $pageScripts array   Optional extra JS files under assets/js, e.g. ['invoice-form.js']
 *
 * Close the page with includes/footer.php.
 */

$pageTitle   ??= 'LemboFlow';
$activeNav   ??= '';
$pageScripts ??= [];
$user          = current_user();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <meta name="app-url" content="<?= e(url()) ?>">
  <meta name="theme-color" content="#15212B">
  <title><?= e($pageTitle) ?> · <?= e(config('app_name', 'LemboFlow')) ?></title>
  <link rel="icon" href="<?= e(asset('images/logo.svg')) ?>" type="image/svg+xml">
  <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>

<div class="app" data-app>
  <?php require __DIR__ . '/sidebar.php'; ?>
  <div class="app__backdrop" data-sidebar-close></div>

  <div class="app__main">
    <?php require __DIR__ . '/topbar.php'; ?>
    <?php if ($user && $user['is_demo']): ?>
      <div class="demo-banner" role="note">
        <span><?= icon('sparkle', 'icon--sm') ?><strong>Demo mode.</strong> This is sample data, only visible to you, and it’s deleted when you log out.</span>
        <a class="btn btn--sm demo-banner__cta" href="<?= e(url('auth/register.php')) ?>">Create your own account</a>
      </div>
    <?php endif; ?>
    <main id="main" class="page" tabindex="-1">
