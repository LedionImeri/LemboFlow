<?php
/**
 * Invoices: money calculation, numbering, saving, status changes and lists.
 *
 * Money is never calculated with floats. Every amount is converted to an integer
 * number of hundredths (cents, or 0.01 of a quantity/percent), calculated with
 * integers, and rounded half-up. assets/js/invoice-form.js uses exactly the same
 * steps with BigInt, so the live preview and the saved invoice always agree.
 *
 * Calculation order:
 *   line subtotal = quantity × unit price
 *   line tax      = line subtotal × tax rate
 *   subtotal + tax − discount = total   (discount applied after tax, fixed or percent)
 */

declare(strict_types=1);

require_once __DIR__ . '/activity.php';

const INVOICE_STATUSES = [
    'draft'     => 'Draft',
    'sent'      => 'Sent',
    'overdue'   => 'Overdue',
    'paid'      => 'Paid',
    'cancelled' => 'Cancelled',
];

/** Statuses whose content can still be edited. Paid and cancelled invoices are frozen. */
const INVOICE_EDITABLE = ['draft', 'sent', 'overdue'];

const INVOICE_DISCOUNT_TYPES = ['none' => 'No discount', 'fixed' => 'Fixed amount', 'percent' => 'Percent'];

const INVOICE_SORTS = [
    'newest'     => 'Newest first',
    'oldest'     => 'Oldest first',
    'due'        => 'Due date',
    'total_desc' => 'Highest total',
];

const INVOICE_MAX_LINES    = 100;
const INVOICE_MAX_QTY      = '99999.99';
const INVOICE_MAX_PRICE    = '9999999.99';  // with INVOICE_MAX_QTY, keeps every intermediate value inside a 64-bit integer
const INVOICE_MAX_TOTAL    = 999999999999; // cents: 9,999,999,999.99, the DECIMAL(12,2) limit

// ---------------------------------------------------------------------------
// Integer money helpers
// ---------------------------------------------------------------------------

/**
 * "12.5" → 1250. The value must already be validated (non-negative, ≤ 2 decimals).
 * Parsed as a string, so no float rounding is involved.
 */
function to_hundredths(string $value): int
{
    $value = normalize_decimal($value);
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    $whole    = ltrim($whole, '+') === '' ? '0' : ltrim($whole, '+');
    $fraction = str_pad(substr($fraction, 0, 2), 2, '0');
    return (int) $whole * 100 + (int) $fraction;
}

/** 123456 → "1234.56" (for the database). */
function hundredths_to_decimal(int $value): string
{
    return sprintf('%d.%02d', intdiv($value, 100), $value % 100);
}

/** Divide two non-negative integers and round half-up: div_round(5, 2) = 3. */
function div_round(int $numerator, int $denominator): int
{
    $quotient  = intdiv($numerator, $denominator);
    $remainder = $numerator % $denominator;
    return $remainder * 2 >= $denominator ? $quotient + 1 : $quotient;
}

/** True when a string is a non-negative number with at most 2 decimals. */
function is_money_string(string $value): bool
{
    return (bool) preg_match('/^\d+(\.\d{1,2})?$/', normalize_decimal($value));
}

/**
 * Calculate every line and the invoice totals.
 *
 * @param array $items  Lines with string keys quantity, unit_price, tax_rate
 * @return array{items: array, subtotal: int, tax_total: int, gross: int, discount_amount: int, total: int}
 *               All amounts in cents. Each item gets line_subtotal, line_tax, line_total (cents).
 */
function calculate_invoice_totals(array $items, string $discountType, string $discountValue): array
{
    $subtotal = 0;
    $taxTotal = 0;

    foreach ($items as $i => $item) {
        $qty   = to_hundredths($item['quantity']);    // 1.5 h  → 150
        $price = to_hundredths($item['unit_price']);  // €65.50 → 6550
        $rate  = to_hundredths($item['tax_rate']);    // 20%    → 2000

        $lineSubtotal = div_round($qty * $price, 100);
        $lineTax      = div_round($lineSubtotal * $rate, 10000);

        $items[$i]['line_subtotal'] = $lineSubtotal;
        $items[$i]['line_tax']      = $lineTax;
        $items[$i]['line_total']    = $lineSubtotal + $lineTax;

        $subtotal += $lineSubtotal;
        $taxTotal += $lineTax;
    }

    $gross = $subtotal + $taxTotal;

    // An over-limit invoice is rejected by validation; skip the discount maths so it can't overflow.
    if ($gross > INVOICE_MAX_TOTAL) {
        $discountType = 'none';
    }

    $discount = match ($discountType) {
        'fixed'   => min(to_hundredths($discountValue ?: '0'), $gross),
        'percent' => div_round($gross * min(to_hundredths($discountValue ?: '0'), 10000), 10000),
        default   => 0,
    };

    return [
        'items'           => $items,
        'subtotal'        => $subtotal,
        'tax_total'       => $taxTotal,
        'gross'           => $gross,
        'discount_amount' => $discount,
        'total'           => $gross - $discount,
    ];
}

// ---------------------------------------------------------------------------
// Numbering
// ---------------------------------------------------------------------------

/**
 * Reserve the next invoice number for a user and year: INV-2026-0001.
 *
 * Must run inside the transaction that inserts the invoice. The upsert locks the
 * sequence row, so two invoices saved at the same moment wait for each other and
 * can never get the same number. LAST_INSERT_ID(expr) hands the new value back on
 * this connection only. If the transaction rolls back, the number is released.
 */
function next_invoice_number(int $userId, string $prefix, int $year): string
{
    $pdo = db();
    $pdo->prepare(
        'INSERT INTO invoice_sequences (user_id, year, last_number)
         VALUES (?, ?, LAST_INSERT_ID(1))
         ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)'
    )->execute([$userId, $year]);

    $number = (int) $pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();

    return sprintf('%s-%d-%04d', $prefix, $year, $number);
}

// ---------------------------------------------------------------------------
// Input & validation
// ---------------------------------------------------------------------------

/** Read the posted form. Completely empty lines are dropped. */
function invoice_input(array $post): array
{
    $str = fn (array $a, string $k) => is_string($a[$k] ?? null) ? trim($a[$k]) : '';

    $items = [];
    foreach (is_array($post['items'] ?? null) ? $post['items'] : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $item = [
            'product_id'  => $str($row, 'product_id'),
            'name'        => $str($row, 'name'),
            'description' => $str($row, 'description'),
            'unit'        => $str($row, 'unit'),
            'quantity'    => normalize_decimal($str($row, 'quantity')),
            'unit_price'  => normalize_decimal($str($row, 'unit_price')),
            'tax_rate'    => normalize_decimal($str($row, 'tax_rate')),
        ];
        if ($item['name'] === '' && $item['description'] === '' && $item['unit_price'] === '' && $item['product_id'] === '') {
            continue;
        }
        $items[] = $item;
    }

    $discountType = $str($post, 'discount_type');

    return [
        'client_id'      => $str($post, 'client_id'),
        'issue_date'     => $str($post, 'issue_date'),
        'due_date'       => $str($post, 'due_date'),
        'discount_type'  => $discountType,
        'discount_value' => $discountType === 'none' ? '0' : normalize_decimal($str($post, 'discount_value')),
        'notes'          => $str($post, 'notes'),
        'items'          => $items,
    ];
}

/**
 * Validate the form. Line errors use keys like "items.2.quantity".
 * $existing is the saved invoice when editing, null when creating.
 *
 * @return array{0: array, 1: ?array}  [errors, calculated totals or null]
 */
function validate_invoice(int $userId, array $data, ?array $existing = null): array
{
    $errors = validate($data, [
        'client_id'     => 'required|integer',
        'issue_date'    => 'required|date',
        'due_date'      => 'required|date',
        'discount_type' => 'required|in:' . implode(',', array_keys(INVOICE_DISCOUNT_TYPES)),
        'notes'         => 'max:2000',
    ], ['client_id' => 'Client']);

    if (!isset($errors['client_id']) && !owns_client($userId, (int) $data['client_id'])) {
        $errors['client_id'] = 'Choose one of your clients.';
    }
    if (!isset($errors['issue_date']) && !isset($errors['due_date']) && $data['due_date'] < $data['issue_date']) {
        $errors['due_date'] = 'Due date can’t be before the issue date.';
    }

    if ($data['discount_type'] !== 'none' && !isset($errors['discount_type'])) {
        if (!is_money_string($data['discount_value'])) {
            $errors['discount_value'] = 'Discount must be a positive number with at most 2 decimals.';
        } elseif ($data['discount_type'] === 'percent' && to_hundredths($data['discount_value']) > 10000) {
            $errors['discount_value'] = 'A percent discount can’t be more than 100%.';
        }
    }

    // Lines
    $items = $data['items'];
    if (!$items) {
        $errors['items'] = 'Add at least one line.';
    } elseif (count($items) > INVOICE_MAX_LINES) {
        $errors['items'] = 'An invoice can have at most ' . INVOICE_MAX_LINES . ' lines.';
    }

    $ownedProducts = owned_product_ids($userId, array_column($items, 'product_id'));

    foreach ($items as $i => $item) {
        $key = "items.{$i}";
        if ($item['name'] === '') {
            $errors["{$key}.name"] = 'Describe this line.';
        } elseif (mb_strlen($item['name']) > 150) {
            $errors["{$key}.name"] = 'At most 150 characters.';
        }
        if (mb_strlen($item['description']) > 1000) {
            $errors["{$key}.description"] = 'At most 1000 characters.';
        }
        if (mb_strlen($item['unit']) > 20) {
            $errors["{$key}.unit"] = 'At most 20 characters.';
        }
        if (!is_money_string($item['quantity']) || to_hundredths($item['quantity']) === 0) {
            $errors["{$key}.quantity"] = 'Enter a quantity above 0.';
        } elseif (to_hundredths($item['quantity']) > to_hundredths(INVOICE_MAX_QTY)) {
            $errors["{$key}.quantity"] = 'Quantity is too large.';
        }
        if (!is_money_string($item['unit_price'])) {
            $errors["{$key}.unit_price"] = 'Enter a price (0 or more, 2 decimals).';
        } elseif (to_hundredths($item['unit_price']) > to_hundredths(INVOICE_MAX_PRICE)) {
            $errors["{$key}.unit_price"] = 'Price is too large.';
        }
        if (!is_money_string($item['tax_rate']) || to_hundredths($item['tax_rate']) > 10000) {
            $errors["{$key}.tax_rate"] = 'Tax must be between 0 and 100.';
        }
        // A product ID that isn't the user's is quietly dropped; the line keeps its own values.
        if ($item['product_id'] !== '' && !in_array((int) $item['product_id'], $ownedProducts, true)) {
            $data['items'][$i]['product_id'] = '';
        }
    }

    if ($errors) {
        return [$errors, null];
    }

    $totals = calculate_invoice_totals($data['items'], $data['discount_type'], $data['discount_value']);

    if ($data['discount_type'] === 'fixed' && to_hundredths($data['discount_value']) > $totals['gross']) {
        $errors['discount_value'] = 'The discount can’t be more than the invoice amount.';
    }
    if ($totals['total'] > INVOICE_MAX_TOTAL) {
        $errors['items'] = 'The invoice total is too large.';
    }
    if ($existing !== null && $totals['total'] < to_hundredths((string) $existing['amount_paid'])) {
        $errors['items'] = sprintf(
            'The new total (%s) is less than what has already been paid (%s). Delete a payment first, or keep the total higher.',
            money(hundredths_to_decimal($totals['total']), $existing['currency']),
            money($existing['amount_paid'], $existing['currency'])
        );
    }

    $totals['items'] = array_map(
        fn ($item, $calc) => $calc + ['product_id' => $item['product_id']],
        $data['items'],
        $totals['items']
    );

    return [$errors, $errors ? null : $totals];
}

function owns_client(int $userId, int $clientId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM clients WHERE id = ? AND user_id = ?');
    $stmt->execute([$clientId, $userId]);
    return (bool) $stmt->fetchColumn();
}

/** Which of these product IDs belong to the user. */
function owned_product_ids(int $userId, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return [];
    }
    $in   = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT id FROM products WHERE user_id = ? AND id IN ({$in})");
    $stmt->execute([$userId, ...$ids]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// ---------------------------------------------------------------------------
// Writes
// ---------------------------------------------------------------------------

/** Insert all lines for an invoice (after deleting old ones when editing). */
function insert_invoice_items(int $invoiceId, array $items): void
{
    $stmt = db()->prepare(
        'INSERT INTO invoice_items
            (invoice_id, product_id, position, name, description, unit, quantity, unit_price, tax_rate,
             line_subtotal, line_tax, line_total)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach (array_values($items) as $position => $item) {
        $stmt->execute([
            $invoiceId,
            $item['product_id'] === '' ? null : (int) $item['product_id'],
            $position,
            $item['name'],
            $item['description'] === '' ? null : $item['description'],
            $item['unit'] === '' ? null : $item['unit'],
            hundredths_to_decimal(to_hundredths($item['quantity'])),
            hundredths_to_decimal(to_hundredths($item['unit_price'])),
            hundredths_to_decimal(to_hundredths($item['tax_rate'])),
            hundredths_to_decimal($item['line_subtotal']),
            hundredths_to_decimal($item['line_tax']),
            hundredths_to_decimal($item['line_total']),
        ]);
    }
}

/** Header values shared by create and update. */
function invoice_header_params(array $data, array $totals): array
{
    return [
        (int) $data['client_id'],
        $data['issue_date'],
        $data['due_date'],
        hundredths_to_decimal($totals['subtotal']),
        hundredths_to_decimal($totals['tax_total']),
        $data['discount_type'],
        $data['discount_type'] === 'none' ? '0.00' : hundredths_to_decimal(to_hundredths($data['discount_value'])),
        hundredths_to_decimal($totals['discount_amount']),
        hundredths_to_decimal($totals['total']),
        $data['notes'] === '' ? null : $data['notes'],
    ];
}

/** Create an invoice (draft, or sent right away). Returns the new ID. */
function create_invoice(int $userId, array $data, array $totals, array $profile, bool $markSent): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $year   = (int) substr($data['issue_date'], 0, 4);
        $number = next_invoice_number($userId, $profile['invoice_prefix'], $year);

        $pdo->prepare(
            'INSERT INTO invoices
                (user_id, invoice_number, currency, status, sent_at,
                 client_id, issue_date, due_date, subtotal, tax_total,
                 discount_type, discount_value, discount_amount, total, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId,
            $number,
            $profile['currency'],
            $markSent ? 'sent' : 'draft',
            $markSent ? date('Y-m-d H:i:s') : null,
            ...invoice_header_params($data, $totals),
        ]);
        $invoiceId = (int) $pdo->lastInsertId();

        insert_invoice_items($invoiceId, $totals['items']);
        sync_invoice_payment_state($invoiceId);

        $client = client_name($userId, (int) $data['client_id']);
        log_activity($userId, 'invoice.created', "Invoice {$number} created for {$client}", 'invoice', $invoiceId);
        if ($markSent) {
            log_activity($userId, 'invoice.sent', "Invoice {$number} marked as sent", 'invoice', $invoiceId);
        }

        $pdo->commit();
        return $invoiceId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Update an editable invoice. The invoice row is locked and re-checked inside the
 * transaction, so a payment recorded at the same moment can't be undercut.
 *
 * @return ?string Null on success, or a reason why the invoice can't be changed.
 */
function update_invoice(int $userId, int $invoiceId, array $data, array $totals): ?string
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ? AND user_id = ? FOR UPDATE');
        $stmt->execute([$invoiceId, $userId]);
        $invoice = $stmt->fetch();

        if (!$invoice || !in_array($invoice['status'], INVOICE_EDITABLE, true)) {
            $pdo->rollBack();
            return 'This invoice can no longer be edited.';
        }
        if ($totals['total'] < to_hundredths((string) $invoice['amount_paid'])) {
            $pdo->rollBack();
            return 'The new total is less than what has already been paid.';
        }

        $pdo->prepare(
            'UPDATE invoices
                SET client_id = ?, issue_date = ?, due_date = ?, subtotal = ?, tax_total = ?,
                    discount_type = ?, discount_value = ?, discount_amount = ?, total = ?, notes = ?
              WHERE id = ? AND user_id = ?'
        )->execute([...invoice_header_params($data, $totals), $invoiceId, $userId]);

        $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$invoiceId]);
        insert_invoice_items($invoiceId, $totals['items']);
        sync_invoice_payment_state($invoiceId);

        log_activity($userId, 'invoice.updated', "Invoice {$invoice['invoice_number']} updated", 'invoice', $invoiceId);
        $pdo->commit();
        return null;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Recalculate amount_paid and payment_status from the payments table, and move
 * the lifecycle status to match: fully paid → paid; otherwise sent or overdue
 * depending on the due date. Drafts and cancelled invoices keep their status.
 *
 * Called after every change to an invoice's total or payments.
 */
function sync_invoice_payment_state(int $invoiceId): void
{
    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT i.status, i.total, i.due_date, i.paid_at,
                COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = i.id), 0) AS paid
           FROM invoices i
          WHERE i.id = ?'
    );
    $stmt->execute([$invoiceId]);
    $row = $stmt->fetch();
    if (!$row) {
        return;
    }

    $paid  = to_hundredths((string) $row['paid']);
    $total = to_hundredths((string) $row['total']);

    $paymentStatus = match (true) {
        $paid <= 0      => 'unpaid',
        $paid >= $total => 'paid',
        default         => 'partial',
    };

    $status = $row['status'];
    if (!in_array($status, ['draft', 'cancelled'], true)) {
        $status = match (true) {
            $paymentStatus === 'paid'          => 'paid',
            $row['due_date'] < date('Y-m-d')   => 'overdue',
            default                            => 'sent',
        };
    }

    $paidAt = $status === 'paid' ? ($row['paid_at'] ?? date('Y-m-d H:i:s')) : null;

    $pdo->prepare(
        'UPDATE invoices SET amount_paid = ?, payment_status = ?, status = ?, paid_at = ? WHERE id = ?'
    )->execute([hundredths_to_decimal($paid), $paymentStatus, $status, $paidAt, $invoiceId]);
}

/** Draft → sent. */
function mark_invoice_sent(int $userId, array $invoice): ?string
{
    if ($invoice['status'] !== 'draft') {
        return 'Only draft invoices can be marked as sent.';
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE invoices SET status = 'sent', sent_at = NOW() WHERE id = ? AND user_id = ? AND status = 'draft'")
            ->execute([$invoice['id'], $userId]);
        sync_invoice_payment_state((int) $invoice['id']);
        log_activity($userId, 'invoice.sent', "Invoice {$invoice['invoice_number']} marked as sent", 'invoice', (int) $invoice['id']);
        $pdo->commit();
        return null;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Cancel an invoice that has no payments. Its number stays used, so numbering has no gaps. */
function cancel_invoice(int $userId, array $invoice): ?string
{
    if (!in_array($invoice['status'], INVOICE_EDITABLE, true)) {
        return 'This invoice can’t be cancelled.';
    }
    if ((float) $invoice['amount_paid'] > 0) {
        return 'This invoice has payments. Delete them before cancelling it.';
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE invoices SET status = 'cancelled', cancelled_at = NOW() WHERE id = ? AND user_id = ?")
            ->execute([$invoice['id'], $userId]);
        log_activity($userId, 'invoice.cancelled', "Invoice {$invoice['invoice_number']} cancelled", 'invoice', (int) $invoice['id']);
        $pdo->commit();
        return null;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Only drafts and cancelled invoices without payments can be deleted.
 * Sent invoices are cancelled instead, so issued numbers never disappear.
 */
function invoice_delete_blocker(array $invoice): ?string
{
    if (!in_array($invoice['status'], ['draft', 'cancelled'], true)) {
        return 'Sent invoices can’t be deleted, so your numbering stays complete. Cancel it instead.';
    }
    if ((float) $invoice['amount_paid'] > 0) {
        return 'This invoice has payments, so it can’t be deleted.';
    }
    return null;
}

function delete_invoice(int $userId, array $invoice): ?string
{
    $blocker = invoice_delete_blocker($invoice);
    if ($blocker !== null) {
        return $blocker;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM invoices WHERE id = ? AND user_id = ?')->execute([$invoice['id'], $userId]);
        log_activity($userId, 'invoice.deleted', "Invoice {$invoice['invoice_number']} deleted", 'invoice', (int) $invoice['id']);
        $pdo->commit();
        return null;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Mark sent, unpaid invoices whose due date has passed as overdue, and log each one.
 * Runs when the invoice list, an invoice or the dashboard loads, so no cron job is needed.
 */
function mark_overdue_invoices(int $userId): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT id, invoice_number FROM invoices
              WHERE user_id = ? AND status = 'sent' AND payment_status <> 'paid' AND due_date < CURDATE()
              FOR UPDATE"
        );
        $stmt->execute([$userId]);
        $due = $stmt->fetchAll();

        if ($due) {
            $ids = array_column($due, 'id');
            $in  = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE invoices SET status = 'overdue' WHERE id IN ({$in})")->execute($ids);
            foreach ($due as $inv) {
                log_activity($userId, 'invoice.overdue', "Invoice {$inv['invoice_number']} is overdue", 'invoice', (int) $inv['id']);
            }
        }

        $pdo->commit();
        return count($due);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ---------------------------------------------------------------------------
// Reads
// ---------------------------------------------------------------------------

function client_name(int $userId, int $clientId): string
{
    $stmt = db()->prepare('SELECT name FROM clients WHERE id = ? AND user_id = ?');
    $stmt->execute([$clientId, $userId]);
    return (string) $stmt->fetchColumn();
}

/** Invoice with its client's details, or null. */
function find_invoice(int $userId, int $invoiceId): ?array
{
    $stmt = db()->prepare(
        'SELECT i.*,
                c.name AS client_name, c.contact_name AS client_contact, c.email AS client_email,
                c.address_line1 AS client_address_line1, c.address_line2 AS client_address_line2,
                c.city AS client_city, c.postal_code AS client_postal_code, c.country AS client_country,
                c.vat_number AS client_vat_number
           FROM invoices i
           JOIN clients c ON c.id = i.client_id
          WHERE i.id = ? AND i.user_id = ?'
    );
    $stmt->execute([$invoiceId, $userId]);
    return $stmt->fetch() ?: null;
}

function find_invoice_or_404(int $userId, int $invoiceId): array
{
    return find_invoice($userId, $invoiceId) ?? abort(404);
}

function invoice_items(int $invoiceId): array
{
    $stmt = db()->prepare('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY position, id');
    $stmt->execute([$invoiceId]);
    return $stmt->fetchAll();
}

function invoice_payments(int $invoiceId): array
{
    $stmt = db()->prepare('SELECT * FROM payments WHERE invoice_id = ? ORDER BY paid_on DESC, id DESC');
    $stmt->execute([$invoiceId]);
    return $stmt->fetchAll();
}

/** Status badges for an invoice: lifecycle status, plus "Partial" when partly paid. */
function invoice_badges(array $invoice): string
{
    $html = status_badge($invoice['status']);
    if ($invoice['payment_status'] === 'partial' && in_array($invoice['status'], ['sent', 'overdue'], true)) {
        $html .= ' ' . status_badge('partial');
    }
    return $html;
}

/** What is still owed, in the invoice's currency units. Zero for drafts and cancelled invoices. */
function invoice_balance(array $invoice): float
{
    if (in_array($invoice['status'], ['draft', 'cancelled'], true)) {
        return 0.0;
    }
    return max(0, (float) $invoice['total'] - (float) $invoice['amount_paid']);
}

/** List filters from the query string, with safe defaults. */
function invoice_filters_from_query(array $query): array
{
    $status = (string) ($query['status'] ?? '');
    return [
        'q'      => mb_substr(trim((string) ($query['q'] ?? '')), 0, 100),
        'status' => isset(INVOICE_STATUSES[$status]) || $status === 'partial' ? $status : '',
        'from'   => is_valid_date((string) ($query['from'] ?? '')) ? $query['from'] : '',
        'to'     => is_valid_date((string) ($query['to'] ?? '')) ? $query['to'] : '',
        'sort'   => array_key_exists($query['sort'] ?? '', INVOICE_SORTS) ? $query['sort'] : 'newest',
    ];
}

/**
 * One page of invoices plus totals for everything matching the filters.
 * @return array{rows: array, pagination: array, summary: array}
 */
function list_invoices(int $userId, array $f, int $page = 1, int $perPage = 15): array
{
    $where  = 'i.user_id = ?';
    $params = [$userId];

    if ($f['q'] !== '') {
        $where .= ' AND (i.invoice_number LIKE ? OR c.name LIKE ?)';
        array_push($params, like_escape($f['q']), like_escape($f['q']));
    }
    if ($f['status'] === 'partial') {
        $where .= " AND i.payment_status = 'partial' AND i.status IN ('sent','overdue')";
    } elseif ($f['status'] !== '') {
        $where   .= ' AND i.status = ?';
        $params[] = $f['status'];
    }
    if ($f['from'] !== '') {
        $where   .= ' AND i.issue_date >= ?';
        $params[] = $f['from'];
    }
    if ($f['to'] !== '') {
        $where   .= ' AND i.issue_date <= ?';
        $params[] = $f['to'];
    }

    $summary = db()->prepare(
        "SELECT COUNT(*) AS count,
                COALESCE(SUM(CASE WHEN i.status NOT IN ('draft','cancelled') THEN i.total END), 0) AS billed,
                COALESCE(SUM(CASE WHEN i.status IN ('sent','overdue') THEN i.total - i.amount_paid END), 0) AS outstanding
           FROM invoices i JOIN clients c ON c.id = i.client_id
          WHERE {$where}"
    );
    $summary->execute($params);
    $summaryRow = $summary->fetch();

    $pagination = paginate((int) $summaryRow['count'], $page, $perPage);

    $orderBy = match ($f['sort']) {
        'oldest'     => 'i.issue_date ASC, i.id ASC',
        'due'        => "FIELD(i.status, 'overdue', 'sent', 'draft', 'paid', 'cancelled'), i.due_date ASC",
        'total_desc' => 'i.total DESC, i.id DESC',
        default      => 'i.issue_date DESC, i.id DESC',
    };

    $stmt = db()->prepare(
        "SELECT i.id, i.invoice_number, i.status, i.payment_status, i.issue_date, i.due_date,
                i.currency, i.total, i.amount_paid, c.id AS client_id, c.name AS client_name
           FROM invoices i
           JOIN clients c ON c.id = i.client_id
          WHERE {$where}
       ORDER BY {$orderBy}
          LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}"
    );
    $stmt->execute($params);

    return ['rows' => $stmt->fetchAll(), 'pagination' => $pagination, 'summary' => $summaryRow];
}

function invoice_has_any(int $userId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM invoices WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    return (bool) $stmt->fetchColumn();
}

/** Clients for the invoice form's dropdown. */
function clients_for_select(int $userId): array
{
    $stmt = db()->prepare('SELECT id, name, email FROM clients WHERE user_id = ? ORDER BY name');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/** Active products for the line picker (embedded in the form as JSON). */
function products_for_picker(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT id, name, description, unit, unit_price, tax_rate
           FROM products WHERE user_id = ? AND is_active = 1 ORDER BY name'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}
