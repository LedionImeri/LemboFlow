<?php
/**
 * Client list with live search, sorting and pagination.
 * Works without JavaScript (plain GET form); search.js upgrades it to live results.
 */

declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';
require BASE_PATH . '/src/clients.php';

$userId = require_login();
$user   = current_user();

$search   = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$sort     = array_key_exists($_GET['sort'] ?? '', CLIENT_SORTS) ? $_GET['sort'] : 'name';
$currency = $user['currency'] ?? 'EUR';

$result        = list_clients($userId, $search, $sort, current_page());
$hasAnyClients = $result['pagination']['total'] > 0 || client_has_any($userId);

$pageTitle   = 'Clients';
$activeNav   = 'clients';
$pageScripts = ['search.js'];
require BASE_PATH . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <h2 class="page-header__title">Clients</h2>
    <p class="page-header__subtitle">Everyone you invoice, with what they've been billed and still owe.</p>
  </div>
  <div class="page-header__actions">
    <a class="btn btn--primary" href="<?= e(url('clients/create.php')) ?>"><?= icon('plus') ?>Add client</a>
  </div>
</div>

<section class="card">
  <?php if ($hasAnyClients): ?>
    <form class="toolbar" method="get" action="<?= e(url('clients/')) ?>" role="search"
          data-live-search data-endpoint="<?= e(url('api/clients.php')) ?>" data-target="#client-results">
      <div class="input-icon">
        <?= icon('search') ?>
        <label class="sr-only" for="client-search">Search clients</label>
        <input class="input" id="client-search" name="q" type="search" value="<?= e($search) ?>"
               placeholder="Search name, email, city or VAT…" autocomplete="off">
      </div>
      <label class="sr-only" for="client-sort">Sort by</label>
      <select class="select toolbar__select" id="client-sort" name="sort">
        <?php foreach (CLIENT_SORTS as $value => $label): ?>
          <option value="<?= e($value) ?>"<?= $sort === $value ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <noscript><button class="btn btn--secondary" type="submit">Search</button></noscript>
    </form>
  <?php endif; ?>

  <div id="client-results" class="results" aria-live="polite">
    <?php require __DIR__ . '/_table.php'; ?>
  </div>
</section>

<?php require BASE_PATH . '/includes/footer.php'; ?>
