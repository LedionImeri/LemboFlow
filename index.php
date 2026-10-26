<?php
/**
 * Public landing page: what LemboFlow does, Get started and View demo.
 */

declare(strict_types=1);

require __DIR__ . '/config/bootstrap.php';

$loggedIn = is_logged_in() && current_user() !== null;
$year     = date('Y');

$features = [
    ['invoice', 'Invoices & PDF',          'Line items from your catalog, discounts, tax per line, automatic numbering and a clean PDF in one click.'],
    ['wallet',  'Payments that add up',     'Record full or partial payments. Balances, “paid” and “overdue” update themselves, with no manual status juggling.'],
    ['users',   'Clients & catalog',        'Keep clients and the services you sell in one place, with what each client has been billed and still owes.'],
    ['trend-up','A dashboard you can trust','Revenue is built from money actually received, month by month, next to what’s pending and overdue.'],
    ['sparkle', 'LemboAI',                  'Ask “Which invoices are overdue?” or “How did this month compare?” and get answers from your own data.'],
    ['check-circle', 'Built to be correct', 'Integer-cent maths, invoice numbers that can never repeat, and every query scoped to your account.'],
];

$steps = [
    ['Set up your business', 'Add your address, VAT number and IBAN once. They appear on every invoice.'],
    ['Send an invoice',       'Pick a client, add lines from your catalog, and download the PDF.'],
    ['Get paid',              'Record payments as they arrive. Overdue invoices flag themselves.'],
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="description" content="LemboFlow: invoicing, payments and revenue tracking for small businesses, with an AI assistant that answers from your own data.">
  <meta name="theme-color" content="#15212B">
  <meta name="app-url" content="<?= e(url()) ?>">
  <title>LemboFlow · Invoicing for small businesses</title>
  <link rel="icon" href="<?= e(asset('images/logo.svg')) ?>" type="image/svg+xml">
  <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('css/landing.css')) ?>">
</head>
<body class="landing">
<a class="skip-link" href="#main">Skip to content</a>

<header class="l-nav">
  <div class="l-container l-nav__inner">
    <a class="brand brand--dark" href="<?= e(url()) ?>">
      <img class="brand__mark" src="<?= e(asset('images/logo.svg')) ?>" alt="" width="32" height="32">
      <span class="brand__name">Lembo<span>Flow</span></span>
    </a>
    <nav class="l-nav__links" aria-label="Page sections">
      <a href="#features">Features</a>
      <a href="#how">How it works</a>
    </nav>
    <div class="l-nav__actions">
      <?php if ($loggedIn): ?>
        <a class="btn btn--primary" href="<?= e(url('dashboard/')) ?>">Go to dashboard</a>
      <?php else: ?>
        <a class="btn btn--ghost l-hide-sm" href="<?= e(url('auth/login.php')) ?>">Log in</a>
        <a class="btn btn--primary" href="<?= e(url('auth/register.php')) ?>">Get started</a>
      <?php endif; ?>
    </div>
  </div>
</header>

<main id="main">
  <section class="l-hero">
    <div class="l-container l-hero__inner">
      <div class="l-hero__copy">
        <p class="l-eyebrow">Invoicing for small businesses</p>
        <h1>Invoices out, payments in, nothing lost in between.</h1>
        <p class="l-lead">
          LemboFlow keeps your clients, invoices and payments in one place, shows you what’s really been earned,
          and answers questions about your business in plain language.
        </p>
        <div class="l-hero__ctas">
          <?php if ($loggedIn): ?>
            <a class="btn btn--primary btn--lg" href="<?= e(url('dashboard/')) ?>">Go to your dashboard</a>
          <?php else: ?>
            <a class="btn btn--primary btn--lg" href="<?= e(url('auth/register.php')) ?>">Get started free</a>
            <form method="post" action="<?= e(url('auth/demo.php')) ?>">
              <?= csrf_field() ?>
              <button class="btn btn--secondary btn--lg" type="submit"><?= icon('eye') ?>View demo</button>
            </form>
          <?php endif; ?>
        </div>
        <?php if (!$loggedIn): ?>
          <p class="l-note"><?= icon('check', 'icon--sm') ?>The demo needs no sign-up: a private copy with a year of sample data.</p>
        <?php endif; ?>
      </div>

      <!-- Product preview, built from the app's own components -->
      <div class="l-preview" aria-hidden="true">
        <div class="l-preview__window">
          <div class="l-preview__bar"><span></span><span></span><span></span></div>
          <div class="l-preview__body">
            <div class="l-preview__kpis">
              <div><small>Revenue this month</small><strong>€8,420.00</strong><em class="trend trend--up"><?= icon('trend-up') ?>+18.2%</em></div>
              <div><small>Pending</small><strong>€3,150.00</strong><em>4 invoices</em></div>
              <div class="is-alert"><small>Overdue</small><strong>€1,276.80</strong><em>2 invoices</em></div>
            </div>
            <div class="l-preview__chart">
              <?php foreach ([38, 52, 30, 61, 47, 72, 55, 80, 66, 74, 58, 90] as $i => $h): ?>
                <span style="height: <?= $h ?>%"<?= $i === 11 ? ' class="is-current"' : '' ?>></span>
              <?php endforeach; ?>
            </div>
            <div class="l-preview__rows">
              <div><b>INV-<?= $year ?>-0048</b><span>Blue Harbor Hotel</span><?= status_badge('paid') ?><i>€2,040.00</i></div>
              <div><b>INV-<?= $year ?>-0047</b><span>Northwind Traders</span><?= status_badge('partial') ?><i>€1,560.00</i></div>
              <div><b>INV-<?= $year ?>-0046</b><span>Kafe Dëshmorët</span><?= status_badge('overdue') ?><i>€738.00</i></div>
            </div>
          </div>
        </div>
        <div class="l-preview__ai">
          <p class="l-preview__q">Which invoices are overdue?</p>
          <p class="l-preview__a"><b>2 invoices, €1,276.80 in total.</b> The oldest is INV-<?= $year ?>-0046 for Kafe Dëshmorët, 12 days late.</p>
        </div>
      </div>
    </div>
  </section>

  <section class="l-section" id="features">
    <div class="l-container">
      <div class="l-section__head">
        <p class="l-eyebrow">Features</p>
        <h2>Everything between “send invoice” and “money in the bank”.</h2>
      </div>
      <div class="l-features">
        <?php foreach ($features as [$iconName, $title, $text]): ?>
          <article class="l-feature">
            <span class="l-feature__icon"><?= icon($iconName) ?></span>
            <h3><?= e($title) ?></h3>
            <p><?= e($text) ?></p>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="l-section l-section--alt" id="how">
    <div class="l-container">
      <div class="l-section__head">
        <p class="l-eyebrow">How it works</p>
        <h2>Up and running in three steps.</h2>
      </div>
      <ol class="l-steps">
        <?php foreach ($steps as $i => [$title, $text]): ?>
          <li class="l-step">
            <span class="l-step__num"><?= $i + 1 ?></span>
            <h3><?= e($title) ?></h3>
            <p><?= e($text) ?></p>
          </li>
        <?php endforeach; ?>
      </ol>
    </div>
  </section>

  <section class="l-cta">
    <div class="l-container l-cta__inner">
      <div>
        <h2>See it with real-looking data.</h2>
        <p>Open the demo: a private studio account with a year of invoices, payments and clients.</p>
      </div>
      <div class="l-hero__ctas">
        <?php if ($loggedIn): ?>
          <a class="btn btn--lg l-cta__primary" href="<?= e(url('dashboard/')) ?>">Go to dashboard</a>
        <?php else: ?>
          <form method="post" action="<?= e(url('auth/demo.php')) ?>">
            <?= csrf_field() ?>
            <button class="btn btn--lg l-cta__primary" type="submit"><?= icon('eye') ?>View demo</button>
          </form>
          <a class="btn btn--lg l-cta__secondary" href="<?= e(url('auth/register.php')) ?>">Create an account</a>
        <?php endif; ?>
      </div>
    </div>
  </section>
</main>

<footer class="l-footer">
  <div class="l-container l-footer__inner">
    <span>&copy; <?= $year ?> LemboFlow</span>
    <span>Built with PHP, MySQL and vanilla JavaScript</span>
  </div>
</footer>

<div class="toasts" data-toasts role="region" aria-live="polite" aria-label="Notifications"></div>
<script type="application/json" id="flash-data"><?= json_encode(pull_flashes(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
