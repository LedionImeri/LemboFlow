<?php
/** Sidebar navigation. Uses $activeNav and $user from header.php. */

$navItems = [
    'dashboard' => ['Dashboard',           'dashboard/', 'dashboard'],
    'invoices'  => ['Invoices',            'invoices/',  'invoice'],
    'clients'   => ['Clients',             'clients/',   'users'],
    'products'  => ['Products & Services', 'products/',  'box'],
    'payments'  => ['Payments',            'payments/',  'card'],
];
?>
<aside class="sidebar" id="sidebar" aria-label="Main navigation">
  <div class="sidebar__brand">
    <a class="brand" href="<?= e(url('dashboard/')) ?>">
      <img class="brand__mark" src="<?= e(asset('images/logo.svg')) ?>" alt="" width="32" height="32">
      <span class="brand__name">Lembo<span>Flow</span></span>
    </a>
    <button class="btn btn--icon btn--ghost-inverse sidebar__close" type="button" data-sidebar-close aria-label="Close menu">
      <?= icon('x') ?>
    </button>
  </div>

  <nav class="sidebar__nav">
    <ul class="nav">
      <?php foreach ($navItems as $key => [$label, $href, $iconName]): ?>
        <li>
          <a class="nav__link<?= $activeNav === $key ? ' is-active' : '' ?>"
             href="<?= e(url($href)) ?>"
             <?= $activeNav === $key ? 'aria-current="page"' : '' ?>>
            <?= icon($iconName) ?>
            <span><?= e($label) ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </nav>

  <div class="sidebar__footer">
    <a class="nav__link<?= $activeNav === 'settings' ? ' is-active' : '' ?>"
       href="<?= e(url('settings/')) ?>"
       <?= $activeNav === 'settings' ? 'aria-current="page"' : '' ?>>
      <?= icon('settings') ?>
      <span>Settings</span>
    </a>

    <?php if ($user): ?>
      <div class="sidebar__user">
        <span class="avatar" aria-hidden="true"><?= e(initials($user['name'])) ?></span>
        <span class="sidebar__user-text">
          <strong><?= e($user['name']) ?></strong>
          <small><?= e($user['business_name'] ?? $user['email']) ?></small>
        </span>
        <form method="post" action="<?= e(url('auth/logout.php')) ?>">
          <?= csrf_field() ?>
          <button class="btn btn--icon btn--ghost-inverse" type="submit" aria-label="Log out" title="Log out">
            <?= icon('logout') ?>
          </button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</aside>
