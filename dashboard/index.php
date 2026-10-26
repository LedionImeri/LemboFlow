<?php
/**
 * Dashboard: KPI cards, 12-month revenue chart, recent invoices and activity.
 * Every money figure comes from recorded payments or invoice balances.
 */

declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
require BASE_PATH . '/src/stats.php';

$userId = require_login();
$user   = current_user();
mark_overdue_invoices($userId);

$currency   = $user['currency'] ?? 'EUR';
$kpis       = dashboard_kpis($userId);
$revenue    = revenue_by_month($userId, 12);
$invoices   = recent_invoices($userId, 6);
$activities = recent_activities($userId, 8);
$steps      = onboarding_steps($userId);
$stepsDone  = count(array_filter(array_column($steps, 3)));
$showSteps  = $stepsDone < count($steps);

$hour     = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

$change = $kpis['revenue_change'];

/** Icon and tone for an activity type. */
$activityStyle = function (string $type): array {
    return match (true) {
        $type === 'payment.recorded', $type === 'invoice.paid' => ['wallet', 'success'],
        $type === 'invoice.overdue'                            => ['alert', 'danger'],
        str_starts_with($type, 'payment.')                     => ['card', 'neutral'],
        str_starts_with($type, 'invoice.')                     => ['invoice', 'info'],
        str_starts_with($type, 'client.')                      => ['users', 'neutral'],
        str_starts_with($type, 'product.')                     => ['box', 'neutral'],
        default                                                => ['settings', 'neutral'],
    };
};

$pageTitle   = 'Dashboard';
$activeNav   = 'dashboard';
$pageScripts = ['dashboard-chart.js'];
require BASE_PATH . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h2 class="page-header__title"><?= e($greeting) ?>, <?= e(strtok($user['name'], ' ')) ?></h2>
    <p class="page-header__subtitle"><?= e(date('l, F j')) ?> · <?= e($user['business_name'] ?? '') ?></p>
  </div>
  <div class="page-header__actions">
    <a class="btn btn--primary" href="<?= e(url('invoices/create.php')) ?>"><?= icon('plus') ?>New invoice</a>
  </div>
</div>

<div class="stack-lg">

  <?php if ($showSteps): ?>
    <section class="card onboarding">
      <div class="card__header">
        <div>
          <h3 class="card__title">Get set up</h3>
          <p class="card__subtitle"><?= $stepsDone ?> of <?= count($steps) ?> done</p>
        </div>
        <div class="progress onboarding__progress" role="progressbar" aria-valuemin="0" aria-valuemax="<?= count($steps) ?>" aria-valuenow="<?= $stepsDone ?>" aria-label="Setup progress">
          <span style="width: <?= (int) round($stepsDone / count($steps) * 100) ?>%"></span>
        </div>
      </div>
      <ol class="onboarding__list">
        <?php foreach ($steps as [$title, $text, $href, $done]): ?>
          <li class="<?= $done ? 'is-done' : '' ?>">
            <span class="onboarding__check" aria-hidden="true"><?= $done ? icon('check') : '' ?></span>
            <span class="onboarding__text">
              <?php if ($done): ?>
                <strong><?= e($title) ?></strong><span class="sr-only"> (done)</span>
              <?php else: ?>
                <a href="<?= e(url($href)) ?>"><strong><?= e($title) ?></strong></a>
              <?php endif; ?>
              <small><?= e($text) ?></small>
            </span>
          </li>
        <?php endforeach; ?>
      </ol>
    </section>
  <?php endif; ?>

  <section class="kpi-grid" aria-label="Key figures">
    <article class="card kpi">
      <p class="kpi__label"><?= icon('wallet') ?>Revenue this month</p>
      <p class="kpi__value"><?= e(money($kpis['revenue_this_month'], $currency)) ?></p>
      <p class="kpi__meta">
        <?php if ($change === null): ?>
          <span class="muted">No payments yet at this point last month</span>
        <?php else: ?>
          <span class="trend <?= $change >= 0 ? 'trend--up' : 'trend--down' ?>">
            <?= icon($change >= 0 ? 'trend-up' : 'trend-down') ?><?= ($change >= 0 ? '+' : '−') . e(number_format(abs($change), 1)) ?>%
          </span>
          vs. same point last month
        <?php endif; ?>
      </p>
    </article>

    <article class="card kpi">
      <p class="kpi__label"><?= icon('check-circle') ?>Paid invoices</p>
      <p class="kpi__value"><?= (int) $kpis['paid_count'] ?></p>
      <p class="kpi__meta"><?= e(money($kpis['paid_amount'], $currency)) ?> collected · <?= e(money($kpis['revenue_all_time'], $currency)) ?> received all time</p>
    </article>

    <a class="card kpi kpi--link" href="<?= e(url('invoices/?status=sent')) ?>">
      <p class="kpi__label"><?= icon('clock') ?>Pending</p>
      <p class="kpi__value"><?= e(money($kpis['pending_amount'], $currency)) ?></p>
      <p class="kpi__meta"><?= (int) $kpis['pending_count'] ?> <?= $kpis['pending_count'] === 1 ? 'invoice' : 'invoices' ?> awaiting payment</p>
    </a>

    <a class="card kpi kpi--link<?= $kpis['overdue_count'] > 0 ? ' kpi--alert' : '' ?>" href="<?= e(url('invoices/?status=overdue')) ?>">
      <p class="kpi__label"><?= icon('alert') ?>Overdue</p>
      <p class="kpi__value"><?= e(money($kpis['overdue_amount'], $currency)) ?></p>
      <p class="kpi__meta">
        <?= $kpis['overdue_count'] > 0
            ? (int) $kpis['overdue_count'] . ' ' . ($kpis['overdue_count'] === 1 ? 'invoice' : 'invoices') . ' past due date'
            : 'Nothing overdue' ?>
      </p>
    </a>
  </section>

  <section class="card">
    <div class="card__header">
      <div>
        <h3 class="card__title" id="revenue-title">Revenue</h3>
        <p class="card__subtitle">Payments received per month · <span data-revenue-total><?= e(money(array_sum(array_column($revenue, 'total')), $currency)) ?></span> in this period</p>
      </div>
      <div class="segmented segmented--sm" role="group" aria-label="Period">
        <button class="segmented__btn" type="button" data-range="6" aria-pressed="false">6M</button>
        <button class="segmented__btn is-active" type="button" data-range="12" aria-pressed="true">12M</button>
      </div>
    </div>
    <div class="card__body">
      <div class="chart" data-revenue-chart
           data-endpoint="<?= e(url('api/revenue-chart.php')) ?>"
           data-money-template="<?= e(money(0, $currency)) ?>"
           role="group" aria-labelledby="revenue-title">
        <div class="chart__skeleton" aria-hidden="true"></div>
      </div>
      <script type="application/json" id="revenue-data"><?= json_encode($revenue, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>

      <details class="chart-table">
        <summary>Show as table</summary>
        <table class="table">
          <thead><tr><th scope="col">Month</th><th scope="col" class="num">Payments</th><th scope="col" class="num">Received</th></tr></thead>
          <tbody data-revenue-table>
            <?php foreach (array_reverse($revenue) as $m): ?>
              <tr><td><?= e($m['label']) ?></td><td class="num"><?= (int) $m['payments'] ?></td><td class="num"><?= e(money($m['total'], $currency)) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </details>
    </div>
  </section>

  <div class="grid-2">
    <section class="card">
      <div class="card__header">
        <h3 class="card__title">Recent invoices</h3>
        <a class="btn btn--ghost btn--sm" href="<?= e(url('invoices/')) ?>">View all<?= icon('chevron-right', 'icon--sm') ?></a>
      </div>
      <?php if ($invoices): ?>
        <div class="table-wrap">
          <table class="table table--stack">
            <thead>
              <tr><th scope="col">Invoice</th><th scope="col">Status</th><th scope="col" class="num">Total</th></tr>
            </thead>
            <tbody>
              <?php foreach ($invoices as $inv): ?>
                <tr>
                  <td data-label="">
                    <a class="table__primary num" href="<?= e(url('invoices/view.php?id=' . $inv['id'])) ?>"><?= e($inv['invoice_number']) ?></a>
                    <span class="table__secondary"><?= e($inv['client_name']) ?> · <?= e(format_date($inv['issue_date'])) ?></span>
                  </td>
                  <td data-label="Status"><span class="badges"><?= invoice_badges($inv) ?></span></td>
                  <td data-label="Total" class="num"><?= e(money($inv['total'], $inv['currency'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="empty empty--compact">
          <span class="empty__icon"><?= icon('invoice') ?></span>
          <p class="empty__title">No invoices yet</p>
          <div class="empty__actions">
            <a class="btn btn--primary btn--sm" href="<?= e(url('invoices/create.php')) ?>"><?= icon('plus') ?>Create invoice</a>
          </div>
        </div>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card__header">
        <h3 class="card__title">Activity</h3>
      </div>
      <?php if ($activities): ?>
        <ul class="activity">
          <?php foreach ($activities as $a): ?>
            <?php [$iconName, $tone] = $activityStyle($a['type']); ?>
            <li class="activity__item">
              <span class="activity__icon activity__icon--<?= e($tone) ?>"><?= icon($iconName, 'icon--sm') ?></span>
              <span class="activity__text">
                <?php if ($a['subject_type'] === 'invoice' && $a['subject_id'] && $a['type'] !== 'invoice.deleted'): ?>
                  <a href="<?= e(url('invoices/view.php?id=' . $a['subject_id'])) ?>"><?= e($a['description']) ?></a>
                <?php else: ?>
                  <?= e($a['description']) ?>
                <?php endif; ?>
              </span>
              <time class="activity__time" datetime="<?= e(date('c', strtotime($a['created_at']))) ?>"><?= e(time_ago($a['created_at'])) ?></time>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="card__body muted">Nothing yet. Your actions will show up here.</p>
      <?php endif; ?>
    </section>
  </div>
</div>

<?php require BASE_PATH . '/includes/footer.php'; ?>
