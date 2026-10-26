<?php
/**
 * Product list results: table + pagination, or an empty state.
 * Rendered by products/index.php and by api/products.php for live search.
 *
 * Expects: $result (from list_products), $filters (q, type, status, sort), $currency, $hasAnyProducts
 */

if (!defined('BASE_PATH')) {
    exit;
}

$rows      = $result['rows'];
$filtering = $filters['q'] !== '' || $filters['type'] !== '' || $filters['status'] !== 'active';
?>
<?php if (!$rows): ?>
  <?php if (!$hasAnyProducts): ?>
    <div class="empty">
      <span class="empty__icon"><?= icon('box') ?></span>
      <p class="empty__title">No products or services yet</p>
      <p class="empty__text">Save what you sell with its price and tax rate, and add it to invoices in one click.</p>
      <div class="empty__actions">
        <a class="btn btn--primary" href="<?= e(url('products/create.php')) ?>"><?= icon('plus') ?>Add your first item</a>
      </div>
    </div>
  <?php elseif ($filtering): ?>
    <div class="empty">
      <span class="empty__icon"><?= icon('search') ?></span>
      <p class="empty__title">Nothing matches these filters</p>
      <p class="empty__text">
        <?= $filters['q'] !== '' ? 'No results for “' . e($filters['q']) . '”. ' : '' ?>Try another search, type or status.
      </p>
    </div>
  <?php else: ?>
    <div class="empty">
      <span class="empty__icon"><?= icon('box') ?></span>
      <p class="empty__title">All items are archived</p>
      <p class="empty__text">Switch the status filter to “Archived” to see and restore them.</p>
    </div>
  <?php endif; ?>
<?php else: ?>
  <div class="table-wrap">
    <table class="table table--stack">
      <thead>
        <tr>
          <th scope="col">Name</th>
          <th scope="col">Type</th>
          <th scope="col" class="num">Price</th>
          <th scope="col" class="num">Tax</th>
          <th scope="col" class="num">Used</th>
          <th scope="col" class="actions"><span class="sr-only">Actions</span></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $p): ?>
          <tr class="<?= $p['is_active'] ? '' : 'is-archived' ?>">
            <td data-label="">
              <a class="table__primary" href="<?= e(url('products/edit.php?id=' . $p['id'])) ?>"><?= e($p['name']) ?></a>
              <?php if (!$p['is_active']): ?><span class="badge badge--cancelled-plain">Archived</span><?php endif; ?>
              <?php if ($p['description']): ?>
                <span class="table__secondary table__clamp"><?= e($p['description']) ?></span>
              <?php endif; ?>
            </td>
            <td data-label="Type">
              <span class="type-tag type-tag--<?= e($p['type']) ?>"><?= icon($p['type'] === 'service' ? 'clock' : 'box', 'icon--sm') ?><?= e(PRODUCT_TYPES[$p['type']]) ?></span>
            </td>
            <td data-label="Price" class="num">
              <span><strong><?= e(money($p['unit_price'], $currency)) ?></strong><span class="muted"> / <?= e($p['unit']) ?></span></span>
            </td>
            <td data-label="Tax" class="num"><?= e(trim_decimal($p['tax_rate'])) ?>%</td>
            <td data-label="Used" class="num">
              <?= (int) $p['usage_count'] > 0
                  ? (int) $p['usage_count'] . ' ' . ((int) $p['usage_count'] === 1 ? 'line' : 'lines')
                  : '<span class="muted">—</span>' ?>
            </td>
            <td data-label="" class="actions">
              <a class="btn btn--ghost btn--sm btn--icon" href="<?= e(url('products/edit.php?id=' . $p['id'])) ?>"
                 aria-label="Edit <?= e($p['name']) ?>" title="Edit"><?= icon('edit') ?></a>
              <form class="inline-form" method="post" action="<?= e(url('products/archive.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <input type="hidden" name="active" value="<?= $p['is_active'] ? '0' : '1' ?>">
                <button class="btn btn--ghost btn--sm btn--icon" type="submit"
                        aria-label="<?= $p['is_active'] ? 'Archive' : 'Restore' ?> <?= e($p['name']) ?>"
                        title="<?= $p['is_active'] ? 'Archive' : 'Restore' ?>">
                  <?= icon($p['is_active'] ? 'archive' : 'restore') ?>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?= render_pagination($result['pagination'], 'products/', [
    'q'      => $filters['q'],
    'type'   => $filters['type'],
    'status' => $filters['status'] === 'active' ? '' : $filters['status'],
    'sort'   => $filters['sort'] === 'name' ? '' : $filters['sort'],
], 'items') ?>
