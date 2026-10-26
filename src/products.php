<?php
/**
 * Products & services: rules and queries. Every query is scoped to the user.
 *
 * Invoice lines copy name, unit, price and tax from a product when they are
 * created, so editing, archiving or deleting a product never changes an
 * invoice that already exists.
 */

declare(strict_types=1);

require_once __DIR__ . '/activity.php';

const PRODUCT_TYPES = ['service' => 'Service', 'product' => 'Product'];

const PRODUCT_UNITS = ['hour', 'day', 'week', 'month', 'project', 'item', 'piece', 'license', 'kg', 'm²'];

const PRODUCT_SORTS = [
    'name'       => 'Name A–Z',
    'price_desc' => 'Price: high to low',
    'price_asc'  => 'Price: low to high',
    'recent'     => 'Newest first',
];

const PRODUCT_MAX_PRICE = 9999999999.99;

// ---------------------------------------------------------------------------
// Input & validation
// ---------------------------------------------------------------------------

function product_input(array $post): array
{
    $str = fn (string $k) => is_string($post[$k] ?? null) ? trim($post[$k]) : '';

    return [
        'name'        => $str('name'),
        'type'        => $str('type'),
        'unit'        => mb_strtolower($str('unit')),
        'unit_price'  => normalize_decimal($str('unit_price')),
        'tax_rate'    => normalize_decimal($str('tax_rate')),
        'description' => $str('description'),
        'is_active'   => !empty($post['is_active']) ? '1' : '0',
    ];
}

function validate_product(array $data): array
{
    $errors = validate($data, [
        'name'        => 'required|max:150',
        'type'        => 'required|in:' . implode(',', array_keys(PRODUCT_TYPES)),
        'unit'        => 'required|max:20',
        'unit_price'  => 'required|numeric|min:0|max:' . PRODUCT_MAX_PRICE,
        'tax_rate'    => 'required|numeric|min:0|max:100',
        'description' => 'max:1000',
    ], [
        'unit_price' => 'Price',
        'tax_rate'   => 'Tax rate',
    ]);

    foreach (['unit_price', 'tax_rate'] as $field) {
        if (!isset($errors[$field]) && preg_match('/\.\d{3,}$/', $data[$field])) {
            $errors[$field] = ($field === 'unit_price' ? 'Price' : 'Tax rate') . ' may have at most 2 decimals.';
        }
    }

    return $errors;
}

function product_params(array $data): array
{
    return [
        $data['name'],
        $data['description'] === '' ? null : $data['description'],
        $data['type'],
        $data['unit'],
        round((float) $data['unit_price'], 2),
        round((float) $data['tax_rate'], 2),
        (int) $data['is_active'],
    ];
}

/** List filters from the query string, with safe defaults. */
function product_filters_from_query(array $query): array
{
    return [
        'q'      => mb_substr(trim((string) ($query['q'] ?? '')), 0, 100),
        'type'   => isset(PRODUCT_TYPES[$query['type'] ?? '']) ? $query['type'] : '',
        'status' => in_array($query['status'] ?? '', ['active', 'archived', 'all'], true) ? $query['status'] : 'active',
        'sort'   => array_key_exists($query['sort'] ?? '', PRODUCT_SORTS) ? $query['sort'] : 'name',
    ];
}

// ---------------------------------------------------------------------------
// Reads
// ---------------------------------------------------------------------------

function find_product(int $userId, int $productId): ?array
{
    $stmt = db()->prepare('SELECT * FROM products WHERE id = ? AND user_id = ?');
    $stmt->execute([$productId, $userId]);
    return $stmt->fetch() ?: null;
}

function find_product_or_404(int $userId, int $productId): array
{
    return find_product($userId, $productId) ?? abort(404);
}

/**
 * One page of products.
 * $type: '' | service | product     $status: active | archived | all
 * @return array{rows: array, pagination: array}
 */
function list_products(
    int $userId,
    string $search = '',
    string $type = '',
    string $status = 'active',
    string $sort = 'name',
    int $page = 1,
    int $perPage = 15
): array {
    $where  = 'p.user_id = ?';
    $params = [$userId];

    if ($search !== '') {
        $where .= ' AND (p.name LIKE ? OR p.description LIKE ?)';
        array_push($params, like_escape($search), like_escape($search));
    }
    if (isset(PRODUCT_TYPES[$type])) {
        $where   .= ' AND p.type = ?';
        $params[] = $type;
    }
    if ($status === 'active' || $status === 'archived') {
        $where   .= ' AND p.is_active = ?';
        $params[] = $status === 'active' ? 1 : 0;
    }

    $count = db()->prepare("SELECT COUNT(*) FROM products p WHERE {$where}");
    $count->execute($params);
    $pagination = paginate((int) $count->fetchColumn(), $page, $perPage);

    $orderBy = match ($sort) {
        'price_desc' => 'p.unit_price DESC, p.name ASC',
        'price_asc'  => 'p.unit_price ASC, p.name ASC',
        'recent'     => 'p.created_at DESC, p.id DESC',
        default      => 'p.name ASC, p.id ASC',
    };

    $stmt = db()->prepare(
        "SELECT p.*,
                (SELECT COUNT(*) FROM invoice_items ii WHERE ii.product_id = p.id) AS usage_count
           FROM products p
          WHERE {$where}
       ORDER BY {$orderBy}
          LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}"
    );
    $stmt->execute($params);

    return ['rows' => $stmt->fetchAll(), 'pagination' => $pagination];
}

function product_has_any(int $userId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM products WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    return (bool) $stmt->fetchColumn();
}

/** Active products for the invoice form's item picker, with everything needed to pre-fill a line. */
function search_products_for_picker(int $userId, string $search, int $limit = 10): array
{
    $sql    = 'SELECT id, name, description, type, unit, unit_price, tax_rate
                 FROM products WHERE user_id = ? AND is_active = 1';
    $params = [$userId];
    if ($search !== '') {
        $sql     .= ' AND (name LIKE ? OR description LIKE ?)';
        array_push($params, like_escape($search), like_escape($search));
    }
    $sql .= ' ORDER BY name ASC LIMIT ' . max(1, min(50, $limit));

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return array_map(fn (array $p) => [
        'id'          => (int) $p['id'],
        'name'        => $p['name'],
        'description' => $p['description'],
        'type'        => $p['type'],
        'unit'        => $p['unit'],
        'unit_price'  => (float) $p['unit_price'],
        'tax_rate'    => (float) $p['tax_rate'],
    ], $stmt->fetchAll());
}

// ---------------------------------------------------------------------------
// Writes
// ---------------------------------------------------------------------------

function create_product(int $userId, array $data): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO products (user_id, name, description, type, unit, unit_price, tax_rate, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$userId, ...product_params($data)]);
        $productId = (int) $pdo->lastInsertId();

        log_activity($userId, 'product.created', PRODUCT_TYPES[$data['type']] . " {$data['name']} added", 'product', $productId);
        $pdo->commit();
        return $productId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function update_product(int $userId, int $productId, array $data): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE products
                SET name = ?, description = ?, type = ?, unit = ?, unit_price = ?, tax_rate = ?, is_active = ?
              WHERE id = ? AND user_id = ?'
        )->execute([...product_params($data), $productId, $userId]);

        log_activity($userId, 'product.updated', PRODUCT_TYPES[$data['type']] . " {$data['name']} updated", 'product', $productId);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Archive (hide from the invoice picker) or restore a product. */
function set_product_active(int $userId, array $product, bool $active): void
{
    if ((bool) $product['is_active'] === $active) {
        return; // already in that state; nothing to change or log
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE products SET is_active = ? WHERE id = ? AND user_id = ?')
            ->execute([(int) $active, $product['id'], $userId]);

        log_activity(
            $userId,
            $active ? 'product.restored' : 'product.archived',
            ($active ? 'Restored ' : 'Archived ') . $product['name'],
            'product',
            (int) $product['id']
        );
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Delete a product. Invoice lines that used it keep their copied details;
 * the database sets their product_id to NULL.
 */
function delete_product(int $userId, array $product): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM products WHERE id = ? AND user_id = ?')->execute([$product['id'], $userId]);
        log_activity($userId, 'product.deleted', PRODUCT_TYPES[$product['type']] . " {$product['name']} deleted", 'product', (int) $product['id']);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
