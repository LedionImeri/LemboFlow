<?php
/** Top bar: menu toggle (mobile), page title, quick actions, user menu. */
?>
<header class="topbar">
  <button class="btn btn--icon btn--ghost topbar__menu" type="button"
          data-sidebar-open aria-controls="sidebar" aria-expanded="false" aria-label="Open menu">
    <?= icon('menu') ?>
  </button>

  <h1 class="topbar__title"><?= e($pageTitle) ?></h1>

  <div class="topbar__actions">
    <a class="btn btn--primary" href="<?= e(url('invoices/create.php')) ?>">
      <?= icon('plus') ?><span class="btn__label">New invoice</span>
    </a>

    <button class="btn btn--secondary btn--ai" type="button"
            data-drawer-open="lemboai" aria-controls="lemboai" aria-expanded="false">
      <?= icon('sparkle') ?><span class="btn__label">LemboAI</span>
    </button>

    <?php if ($user): ?>
      <details class="dropdown" data-dropdown>
        <summary class="dropdown__toggle" aria-label="Account menu">
          <span class="avatar avatar--sm" aria-hidden="true"><?= e(initials($user['name'])) ?></span>
          <?= icon('chevron-down', 'icon--sm hide-mobile') ?>
        </summary>
        <div class="dropdown__menu" role="menu">
          <div class="dropdown__header">
            <strong><?= e($user['name']) ?></strong>
            <small><?= e($user['email']) ?></small>
          </div>
          <a class="dropdown__item" role="menuitem" href="<?= e(url('settings/')) ?>"><?= icon('settings') ?>Settings</a>
          <form method="post" action="<?= e(url('auth/logout.php')) ?>">
            <?= csrf_field() ?>
            <button class="dropdown__item" role="menuitem" type="submit"><?= icon('logout') ?>Log out</button>
          </form>
        </div>
      </details>
    <?php endif; ?>
  </div>
</header>
