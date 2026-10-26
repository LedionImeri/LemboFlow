<?php
/**
 * Client list results: table + pagination, or an empty state.
 * Rendered by clients/index.php and by api/clients.php for live search.
 *
 * Expects: $result (from list_clients), $search, $sort, $currency, $hasAnyClients
 */

if (!defined('BASE_PATH')) {
    exit;
}

$rows = $result['rows'];
?>
<?php if (!$rows): ?>
  <?php if ($search !== ''): ?>
    <div class="empty">
      <span class="empty__icon"><?= icon('search') ?></span>
      <p class="empty__title">No clients match “<?= e($search) ?>”</p>
      <p class="empty__text">Try a different name, email, city or VAT number.</p>
    </div>
  <?php elseif (!$hasAnyClients): ?>
    <div class="empty">
      <span class="empty__icon"><?= icon('users') ?></span>
      <p class="empty__title">No clients yet</p>
      <p class="empty__text">Add the businesses and people you work for. You'll pick them when you create an invoice.</p>
      <div class="empty__actions">
        <a class="btn btn--primary" href="<?= e(url('clients/create.php')) ?>"><?= icon('plus') ?>Add your first client</a>
      </div>
    </div>
  <?php endif; ?>
<?php else: ?>
  <div class="table-wrap">
    <table class="table table--stack">
      <thead>
        <tr>
          <th scope="col">Client</th>
          <th scope="col">Location</th>
          <th scope="col" class="num">Invoices</th>
          <th scope="col" class="num">Billed</th>
          <th scope="col" class="num">Outstanding</th>
          <th scope="col" class="actions"><span class="sr-only">Actions</span></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $c): ?>
          <?php $location = implode(', ', array_filter([$c['city'], $c['country']])); ?>
          <tr>
            <td data-label="">
              <a class="table__primary" href="<?= e(url('clients/view.php?id=' . $c['id'])) ?>"><?= e($c['name']) ?></a>
              <span class="table__secondary"><?= e($c['contact_name'] ?: ($c['email'] ?: '—')) ?></span>
            </td>
            <td data-label="Location"><?= e($location ?: '—') ?></td>
            <td data-label="Invoices" class="num"><?= (int) $c['invoice_count'] ?></td>
            <td data-label="Billed" class="num"><?= e(money($c['billed'], $currency)) ?></td>
            <td data-label="Outstanding" class="num">
              <?php if ((float) $c['outstanding'] > 0): ?>
                <strong><?= e(money($c['outstanding'], $currency)) ?></strong>
              <?php else: ?>
                <span class="muted"><?= e(money(0, $currency)) ?></span>
              <?php endif; ?>
            </td>
            <td data-label="" class="actions">
              <a class="btn btn--ghost btn--sm btn--icon" href="<?= e(url('clients/edit.php?id=' . $c['id'])) ?>"
                 aria-label="Edit <?= e($c['name']) ?>" title="Edit"><?= icon('edit') ?></a>
              <a class="btn btn--ghost btn--sm btn--icon" href="<?= e(url('clients/view.php?id=' . $c['id'])) ?>"
                 aria-label="View <?= e($c['name']) ?>" title="View"><?= icon('chevron-right') ?></a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?= render_pagination($result['pagination'], 'clients/', ['q' => $search, 'sort' => $sort === 'name' ? '' : $sort], 'clients') ?>
