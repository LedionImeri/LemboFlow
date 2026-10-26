<?php
/**
 * Payments: recording, deleting and listing.
 *
 * Revenue in LemboFlow is the sum of recorded payments. After every change the
 * invoice's amount_paid, payment_status and status are recalculated from the
 * payments table by sync_invoice_payment_state(), so they can never drift.
 */

declare(strict_types=1);

require_once __DIR__ . '/invoices.php';

const PAYMENT_METHODS = [
    'bank_transfer' => 'Bank transfer',
    'cash'          => 'Cash',
    'card'          => 'Card',
    'paypal'        => 'PayPal',
    'other'         => 'Other',
];

const PAYMENT_SORTS = [
    'newest'      => 'Newest first',
    'oldest'      => 'Oldest first',
    'amount_desc' => 'Largest first',
];

function payment_input(array $post): array
{
    $str = fn (string $k) => is_string($post[$k] ?? null) ? trim($post[$k]) : '';

    return [
        'amount'    => normalize_decimal($str('amount')),
        'paid_on'   => $str('paid_on'),
        'method'    => $str('method'),
        'reference' => $str('reference'),
        'notes'     => $str('notes'),
    ];
}

/** Validate a payment against what is still owed on the invoice. */
function validate_payment(array $data, array $invoice): array
{
    $errors = validate($data, [
        'amount'    => 'required',
        'paid_on'   => 'required|date',
        'method'    => 'required|in:' . implode(',', array_keys(PAYMENT_METHODS)),
        'reference' => 'max:100',
        'notes'     => 'max:255',
    ], ['paid_on' => 'Payment date']);

    if (!isset($errors['amount'])) {
        $balance = invoice_balance_cents($invoice);
        if (!is_money_string($data['amount']) || to_hundredths($data['amount']) === 0) {
            $errors['amount'] = 'Enter an amount above 0, with at most 2 decimals.';
        } elseif (to_hundredths($data['amount']) > $balance) {
            $errors['amount'] = 'That is more than the balance due (' . money(hundredths_to_decimal($balance), $invoice['currency']) . ').';
        }
    }

    if (!isset($errors['paid_on']) && $data['paid_on'] > date('Y-m-d')) {
        $errors['paid_on'] = 'The payment date can’t be in the future.';
    }

    return $errors;
}

/** Balance in cents, for any invoice that can receive payments (drafts count their full total). */
function invoice_balance_cents(array $invoice): int
{
    return max(0, to_hundredths((string) $invoice['total']) - to_hundredths((string) $invoice['amount_paid']));
}

/** Why an invoice can't receive a payment, or null if it can. */
function payment_blocker(array $invoice): ?string
{
    return match (true) {
        $invoice['status'] === 'cancelled'   => 'Cancelled invoices can’t receive payments.',
        $invoice['status'] === 'paid'        => 'This invoice is already paid in full.',
        (float) $invoice['total'] <= 0       => 'This invoice has nothing to pay.',
        default                              => null,
    };
}

/**
 * Record a payment. The invoice row is locked and re-read inside the transaction,
 * so two payments submitted at once can't together exceed the balance.
 *
 * @return array{0: ?int, 1: array}  [payment ID or null, errors]
 */
function record_payment(int $userId, int $invoiceId, array $data): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ? AND user_id = ? FOR UPDATE');
        $stmt->execute([$invoiceId, $userId]);
        $invoice = $stmt->fetch();

        if (!$invoice) {
            $pdo->rollBack();
            abort(404);
        }

        $blocker = payment_blocker($invoice);
        $errors  = $blocker !== null ? ['amount' => $blocker] : validate_payment($data, $invoice);
        if ($errors) {
            $pdo->rollBack();
            return [null, $errors];
        }

        // A client paying a draft means it was issued: mark it as sent first.
        if ($invoice['status'] === 'draft') {
            $pdo->prepare("UPDATE invoices SET status = 'sent', sent_at = NOW() WHERE id = ?")->execute([$invoiceId]);
            log_activity($userId, 'invoice.sent', "Invoice {$invoice['invoice_number']} marked as sent", 'invoice', $invoiceId);
        }

        $amount = hundredths_to_decimal(to_hundredths($data['amount']));
        $pdo->prepare(
            'INSERT INTO payments (user_id, invoice_id, amount, paid_on, method, reference, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId,
            $invoiceId,
            $amount,
            $data['paid_on'],
            $data['method'],
            $data['reference'] === '' ? null : $data['reference'],
            $data['notes'] === '' ? null : $data['notes'],
        ]);
        $paymentId = (int) $pdo->lastInsertId();

        sync_invoice_payment_state($invoiceId);

        log_activity(
            $userId,
            'payment.recorded',
            'Payment of ' . money($amount, $invoice['currency']) . " received for {$invoice['invoice_number']}",
            'payment',
            $paymentId
        );

        $state = $pdo->prepare('SELECT status FROM invoices WHERE id = ?');
        $state->execute([$invoiceId]);
        if ($state->fetchColumn() === 'paid') {
            log_activity($userId, 'invoice.paid', "Invoice {$invoice['invoice_number']} paid in full", 'invoice', $invoiceId);
        }

        $pdo->commit();
        return [$paymentId, []];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** "Mark as paid": record one payment for whatever is still owed. */
function mark_invoice_paid(int $userId, array $invoice, string $method = 'bank_transfer'): array
{
    return record_payment($userId, (int) $invoice['id'], [
        'amount'    => hundredths_to_decimal(invoice_balance_cents($invoice)),
        'paid_on'   => date('Y-m-d'),
        'method'    => $method,
        'reference' => '',
        'notes'     => 'Marked as paid',
    ]);
}

/** One payment owned by the user, with its invoice number. */
function find_payment(int $userId, int $paymentId): ?array
{
    $stmt = db()->prepare(
        'SELECT p.*, i.invoice_number, i.currency
           FROM payments p JOIN invoices i ON i.id = p.invoice_id
          WHERE p.id = ? AND p.user_id = ?'
    );
    $stmt->execute([$paymentId, $userId]);
    return $stmt->fetch() ?: null;
}

/** Delete a payment; the invoice falls back to partial/unpaid and sent/overdue as needed. */
function delete_payment(int $userId, array $payment): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT id FROM invoices WHERE id = ? FOR UPDATE')->execute([$payment['invoice_id']]);
        $pdo->prepare('DELETE FROM payments WHERE id = ? AND user_id = ?')->execute([$payment['id'], $userId]);
        sync_invoice_payment_state((int) $payment['invoice_id']);

        log_activity(
            $userId,
            'payment.deleted',
            'Payment of ' . money($payment['amount'], $payment['currency']) . " for {$payment['invoice_number']} deleted",
            'invoice',
            (int) $payment['invoice_id']
        );
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ---------------------------------------------------------------------------
// List
// ---------------------------------------------------------------------------

function payment_filters_from_query(array $query): array
{
    return [
        'q'      => mb_substr(trim((string) ($query['q'] ?? '')), 0, 100),
        'method' => isset(PAYMENT_METHODS[$query['method'] ?? '']) ? $query['method'] : '',
        'from'   => is_valid_date((string) ($query['from'] ?? '')) ? $query['from'] : '',
        'to'     => is_valid_date((string) ($query['to'] ?? '')) ? $query['to'] : '',
        'sort'   => array_key_exists($query['sort'] ?? '', PAYMENT_SORTS) ? $query['sort'] : 'newest',
    ];
}

/** @return array{rows: array, pagination: array, summary: array} */
function list_payments(int $userId, array $f, int $page = 1, int $perPage = 20): array
{
    $where  = 'p.user_id = ?';
    $params = [$userId];

    if ($f['q'] !== '') {
        $where .= ' AND (i.invoice_number LIKE ? OR c.name LIKE ? OR p.reference LIKE ?)';
        array_push($params, ...array_fill(0, 3, like_escape($f['q'])));
    }
    if ($f['method'] !== '') {
        $where   .= ' AND p.method = ?';
        $params[] = $f['method'];
    }
    if ($f['from'] !== '') {
        $where   .= ' AND p.paid_on >= ?';
        $params[] = $f['from'];
    }
    if ($f['to'] !== '') {
        $where   .= ' AND p.paid_on <= ?';
        $params[] = $f['to'];
    }

    $from = 'FROM payments p JOIN invoices i ON i.id = p.invoice_id JOIN clients c ON c.id = i.client_id';

    $summary = db()->prepare("SELECT COUNT(*) AS count, COALESCE(SUM(p.amount), 0) AS received {$from} WHERE {$where}");
    $summary->execute($params);
    $summaryRow = $summary->fetch();

    $pagination = paginate((int) $summaryRow['count'], $page, $perPage);

    $orderBy = match ($f['sort']) {
        'oldest'      => 'p.paid_on ASC, p.id ASC',
        'amount_desc' => 'p.amount DESC, p.id DESC',
        default       => 'p.paid_on DESC, p.id DESC',
    };

    $stmt = db()->prepare(
        "SELECT p.id, p.amount, p.paid_on, p.method, p.reference, p.notes,
                i.id AS invoice_id, i.invoice_number, i.currency, c.id AS client_id, c.name AS client_name
         {$from}
         WHERE {$where}
         ORDER BY {$orderBy}
         LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}"
    );
    $stmt->execute($params);

    return ['rows' => $stmt->fetchAll(), 'pagination' => $pagination, 'summary' => $summaryRow];
}

function payment_has_any(int $userId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM payments WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    return (bool) $stmt->fetchColumn();
}
