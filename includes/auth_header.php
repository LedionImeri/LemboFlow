<?php
/**
 * Layout for guest pages (login, register): brand panel + form card.
 * Set $pageTitle before requiring it. Close with includes/auth_footer.php.
 */

$pageTitle ??= 'Welcome';
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
<body class="auth-body">
<div class="auth">
  <aside class="auth__brand" aria-hidden="true">
    <a class="brand" href="<?= e(url()) ?>" tabindex="-1">
      <img class="brand__mark" src="<?= e(asset('images/logo.svg')) ?>" alt="" width="32" height="32">
      <span class="brand__name">Lembo<span>Flow</span></span>
    </a>

    <div class="auth__pitch">
      <h2>Invoices out, payments in, nothing lost in between.</h2>
      <ul class="auth__features">
        <li><?= icon('invoice') ?>Professional invoices and PDFs in a minute</li>
        <li><?= icon('card') ?>Partial payments tracked automatically</li>
        <li><?= icon('trend-up') ?>Revenue you can trust, from real payments</li>
        <li><?= icon('sparkle') ?>LemboAI answers questions about your business</li>
      </ul>
    </div>

    <p class="auth__legal">&copy; <?= date('Y') ?> LemboFlow</p>
  </aside>

  <main class="auth__main" id="main">
    <div class="auth__card">
      <a class="brand brand--dark auth__mobile-brand" href="<?= e(url()) ?>">
        <img class="brand__mark" src="<?= e(asset('images/logo.svg')) ?>" alt="" width="32" height="32">
        <span class="brand__name">Lembo<span>Flow</span></span>
      </a>
