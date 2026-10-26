<?php
/**
 * Client rules and queries. Every query is scoped to the logged-in user.
 *
 * Money definitions (used here and on the dashboard):
 *   billed       total of issued invoices (not draft, not cancelled)
 *   paid         money actually received (invoices.amount_paid, kept in sync with payments)
 *   outstanding  what is still owed on sent and overdue invoices
 */

declare(strict_types=1);

require_once __DIR__ . '/activity.php';

const CLIENT_FIELDS = [
    'name', 'contact_name', 'email', 'phone',
    'address_line1', 'address_line2', 'city', 'postal_code', 'country',
    'vat_number', 'notes',
];

const CLIENT_SORTS = [
    'name'        => 'Name A–Z',
    'recent'      => 'Newest first',
    'outstanding' => 'Most outstanding',
];

/** SQL pieces for the money columns, for an invoices alias "i". */
const CLIENT_MONEY_SQL = "
    COUNT(i.id) AS invoice_count,
    COALESCE(SUM(CASE WHEN i.status NOT IN ('draft','cancelled') THEN i.total END), 0) AS billed,
    COALESCE(SUM(i.amount_paid), 0) AS paid,
    COALESCE(SUM(CASE WHEN i.status IN ('sent','overdue') THEN i.total - i.amount_paid END), 0) AS outstanding,
    MAX(i.issue_date) AS last_invoice_date";

// ---------------------------------------------------------------------------
// Input & validation
// ---------------------------------------------------------------------------

function client_input(array $post): array
{
    $data = [];
    foreach (CLIENT_FIELDS as $field) {
        $data[$field] = is_string($post[$field] ?? null) ? trim($post[$field]) : '';
    }
    $data['email'] = mb_strtolower($data['email']);
    return $data;
}

function validate_client(array $data): array
{
    return validate($data, [
        'name'          => 'required|max:150',
        'contact_name'  => 'max:100',
        'email'         => 'email|max:190',
        'phone'         => 'max:40',
        'address_line1' => 'max:150',
        'address_line2' => 'max:150',
        'city'          => 'max:100',
        'postal_code'   => 'max:20',
        'country'       => 'max:100',
        'vat_number'    => 'max:40',
        'notes'         => 'max:2000',
    ], [
        'name'         => 'Client name',
        'contact_name' => 'Contact person',
        'vat_number'   => 'VAT number',
    ]);
}

/** Empty optional fields become NULL. */
function client_params(array $data): array
{
    return array_map(
        fn (string $field) => $data[$field] === '' && $field !== 'name' ? null : $data[$field],
        CLIENT_FIELDS
    );
}

// ---------------------------------------------------------------------------
// Reads
// ---------------------------------------------------------------------------

/** One client owned by the user, or null. */
function find_client(int $userId, int $clientId): ?array
{
    $stmt = db()->prepare('SELECT * FROM clients WHERE id = ? AND user_id = ?');
    $stmt->execute([$clientId, $userId]);
    return $stmt->fetch() ?: null;
}

/** Like find_client(), but stops with a 404, also for clients of other users. */
function find_client_or_404(int $userId, int $clientId): array
{
    return find_client($userId, $clientId) ?? abort(404);
}

/**
 * One page of clients with their invoice totals.
 * @return array{rows: array, pagination: array}
 */
function list_clients(int $userId, string $search = '', string $sort = 'name', int $page = 1, int $perPage = 15): array
{
    $where  = 'c.user_id = ?';
    $params = [$userId];

    if ($search !== '') {
        $where .= ' AND (c.name LIKE ? OR c.contact_name LIKE ? OR c.email LIKE ? OR c.city LIKE ? OR c.vat_number LIKE ?)';
        array_push($params, ...array_fill(0, 5, like_escape($search)));
    }

    $count = db()->prepare("SELECT COUNT(*) FROM clients c WHERE {$where}");
    $count->execute($params);
    $pagination = paginate((int) $count->fetchColumn(), $page, $perPage);

    $orderBy = match ($sort) {
        'recent'      => 'c.created_at DESC, c.id DESC',
        'outstanding' => 'outstanding DESC, c.name ASC',
        default       => 'c.name ASC, c.id ASC',
    };

    // LIMIT values are integers we computed, so they are safe to inline.
    $stmt = db()->prepare(
        'SELECT c.id, c.name, c.contact_name, c.email, c.city, c.country, ' . CLIENT_MONEY_SQL . "
           FROM clients c
      LEFT JOIN invoices i ON i.client_id = c.id AND i.user_id = c.user_id
          WHERE {$where}
       GROUP BY c.id
       ORDER BY {$orderBy}
          LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}"
    );
    $stmt->execute($params);

    return ['rows' => $stmt->fetchAll(), 'pagination' => $pagination];
}

function client_has_any(int $userId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM clients WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    return (bool) $stmt->fetchColumn();
}

/** Totals for one client's page. */
function client_totals(int $userId, int $clientId): array
{
    $stmt = db()->prepare(
        'SELECT ' . CLIENT_MONEY_SQL . '
           FROM invoices i
          WHERE i.user_id = ? AND i.client_id = ?'
    );
    $stmt->execute([$userId, $clientId]);
    return $stmt->fetch();
}

function client_invoices(int $userId, int $clientId): array
{
    $stmt = db()->prepare(
        'SELECT id, invoice_number, status, payment_status, issue_date, due_date, currency, total, amount_paid
           FROM invoices
          WHERE user_id = ? AND client_id = ?
       ORDER BY issue_date DESC, id DESC'
    );
    $stmt->execute([$userId, $clientId]);
    return $stmt->fetchAll();
}

/** Small result set for pickers (invoice form): id, name, email. */
function search_clients_for_picker(int $userId, string $search, int $limit = 10): array
{
    $sql    = 'SELECT id, name, email, city FROM clients WHERE user_id = ?';
    $params = [$userId];
    if ($search !== '') {
        $sql     .= ' AND (name LIKE ? OR email LIKE ? OR contact_name LIKE ?)';
        array_push($params, ...array_fill(0, 3, like_escape($search)));
    }
    $sql .= ' ORDER BY name ASC LIMIT ' . max(1, min(50, $limit));

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------------
// Writes
// ---------------------------------------------------------------------------

function create_client(int $userId, array $data): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $columns      = implode(', ', CLIENT_FIELDS);
        $placeholders = implode(', ', array_fill(0, count(CLIENT_FIELDS), '?'));
        $pdo->prepare("INSERT INTO clients (user_id, {$columns}) VALUES (?, {$placeholders})")
            ->execute([$userId, ...client_params($data)]);
        $clientId = (int) $pdo->lastInsertId();

        log_activity($userId, 'client.created', "Client {$data['name']} added", 'client', $clientId);
        $pdo->commit();
        return $clientId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function update_client(int $userId, int $clientId, array $data): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $sets = implode(', ', array_map(fn ($f) => "{$f} = ?", CLIENT_FIELDS));
        $pdo->prepare("UPDATE clients SET {$sets} WHERE id = ? AND user_id = ?")
            ->execute([...client_params($data), $clientId, $userId]);

        log_activity($userId, 'client.updated', "Client {$data['name']} updated", 'client', $clientId);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Delete a client that has no invoices.
 * Returns null on success, or a message explaining why it can't be deleted.
 */
function delete_client(int $userId, array $client): ?string
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM invoices WHERE user_id = ? AND client_id = ?');
    $stmt->execute([$userId, $client['id']]);
    $invoiceCount = (int) $stmt->fetchColumn();

    $blocked = sprintf(
        '%s has %d %s, so it can’t be deleted. Invoices must keep their client for your records.',
        $client['name'],
        $invoiceCount,
        $invoiceCount === 1 ? 'invoice' : 'invoices'
    );

    if ($invoiceCount > 0) {
        return $blocked;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM clients WHERE id = ? AND user_id = ?')->execute([$client['id'], $userId]);
        log_activity($userId, 'client.deleted', "Client {$client['name']} deleted", 'client', (int) $client['id']);
        $pdo->commit();
        return null;
    } catch (PDOException $e) {
        $pdo->rollBack();
        // An invoice was created for this client a moment ago: the RESTRICT foreign key stopped the delete.
        if ($e->getCode() === '23000') {
            return sprintf('%s has invoices, so it can’t be deleted.', $client['name']);
        }
        throw $e;
    }
}
