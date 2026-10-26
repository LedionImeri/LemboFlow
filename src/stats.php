<?php
/**
 * Dashboard figures (also used by LemboAI in Phase 10).
 *
 * Revenue is always money received: SUM(payments.amount) by payment date.
 * Invoice totals are never counted as revenue.
 */

declare(strict_types=1);

require_once __DIR__ . '/invoices.php';

/** Total received between two dates (inclusive). */
function revenue_between(int $userId, string $from, string $to): float
{
    $stmt = db()->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE user_id = ? AND paid_on BETWEEN ? AND ?');
    $stmt->execute([$userId, $from, $to]);
    return (float) $stmt->fetchColumn();
}

function revenue_all_time(int $userId): float
{
    $stmt = db()->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE user_id = ?');
    $stmt->execute([$userId]);
    return (float) $stmt->fetchColumn();
}

/**
 * Revenue per month for the last $months months, oldest first, including
 * months with no payments (0).
 *
 * @return list<array{month: string, label: string, short: string, total: float, payments: int, current: bool}>
 */
function revenue_by_month(int $userId, int $months = 12): array
{
    $months = max(1, min(24, $months));
    $start  = (new DateTimeImmutable('first day of this month'))->modify('-' . ($months - 1) . ' months');

    $stmt = db()->prepare(
        "SELECT DATE_FORMAT(paid_on, '%Y-%m') AS ym, SUM(amount) AS total, COUNT(*) AS payments
           FROM payments
          WHERE user_id = ? AND paid_on >= ?
       GROUP BY ym"
    );
    $stmt->execute([$userId, $start->format('Y-m-d')]);
    $byMonth = [];
    foreach ($stmt->fetchAll() as $row) {
        $byMonth[$row['ym']] = $row;
    }

    $current = date('Y-m');
    $series  = [];
    for ($i = 0; $i < $months; $i++) {
        $m  = $start->modify("+{$i} months");
        $ym = $m->format('Y-m');
        $series[] = [
            'month'    => $ym,
            'label'    => $m->format('F Y'),
            'short'    => $m->format('M'),
            'total'    => (float) ($byMonth[$ym]['total'] ?? 0),
            'payments' => (int) ($byMonth[$ym]['payments'] ?? 0),
            'current'  => $ym === $current,
        ];
    }
    return $series;
}

/**
 * Change between two amounts as a percentage, or null when there is no base
 * to compare with (last month was 0).
 */
function percent_change(float $current, float $previous): ?float
{
    if ($previous <= 0) {
        return null;
    }
    return round(($current - $previous) / $previous * 100, 1);
}

/** The four KPI cards. */
function dashboard_kpis(int $userId): array
{
    $thisMonthStart = date('Y-m-01');
    $lastMonthStart = date('Y-m-01', strtotime('first day of last month'));
    $lastMonthEnd   = date('Y-m-t', strtotime('first day of last month'));

    $thisMonth = revenue_between($userId, $thisMonthStart, date('Y-m-d'));
    // Same number of days into last month, so early in a month the comparison is fair.
    $dayOfMonth     = (int) date('j');
    $lastMonthSameDay = min($lastMonthEnd, date('Y-m-d', strtotime($lastMonthStart . ' +' . ($dayOfMonth - 1) . ' days')));
    $lastMonthToDate  = revenue_between($userId, $lastMonthStart, $lastMonthSameDay);
    $lastMonthFull    = revenue_between($userId, $lastMonthStart, $lastMonthEnd);

    $stmt = db()->prepare(
        "SELECT
            SUM(status = 'paid')                                             AS paid_count,
            COALESCE(SUM(CASE WHEN status = 'paid' THEN total END), 0)       AS paid_amount,
            SUM(status = 'sent')                                             AS pending_count,
            COALESCE(SUM(CASE WHEN status = 'sent' THEN total - amount_paid END), 0)    AS pending_amount,
            SUM(status = 'overdue')                                          AS overdue_count,
            COALESCE(SUM(CASE WHEN status = 'overdue' THEN total - amount_paid END), 0) AS overdue_amount,
            SUM(status = 'draft')                                            AS draft_count
           FROM invoices
          WHERE user_id = ?"
    );
    $stmt->execute([$userId]);
    $inv = $stmt->fetch();

    return [
        'revenue_this_month'  => $thisMonth,
        'revenue_last_month'  => $lastMonthFull,
        'revenue_change'      => percent_change($thisMonth, $lastMonthToDate),
        'revenue_all_time'    => revenue_all_time($userId),
        'paid_count'          => (int) $inv['paid_count'],
        'paid_amount'         => (float) $inv['paid_amount'],
        'pending_count'       => (int) $inv['pending_count'],
        'pending_amount'      => (float) $inv['pending_amount'],
        'overdue_count'       => (int) $inv['overdue_count'],
        'overdue_amount'      => (float) $inv['overdue_amount'],
        'draft_count'         => (int) $inv['draft_count'],
    ];
}

function recent_invoices(int $userId, int $limit = 6): array
{
    $stmt = db()->prepare(
        'SELECT i.id, i.invoice_number, i.status, i.payment_status, i.issue_date, i.due_date,
                i.currency, i.total, i.amount_paid, c.name AS client_name
           FROM invoices i JOIN clients c ON c.id = i.client_id
          WHERE i.user_id = ?
       ORDER BY i.created_at DESC, i.id DESC
          LIMIT ' . max(1, min(50, $limit))
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function recent_activities(int $userId, int $limit = 8): array
{
    $stmt = db()->prepare(
        'SELECT type, subject_type, subject_id, description, created_at
           FROM activities WHERE user_id = ?
       ORDER BY created_at DESC, id DESC
          LIMIT ' . max(1, min(50, $limit))
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/** Clients ranked by money received. */
function top_clients(int $userId, int $limit = 5): array
{
    $stmt = db()->prepare(
        'SELECT c.id, c.name, COALESCE(SUM(p.amount), 0) AS received, COUNT(DISTINCT i.id) AS invoices
           FROM clients c
           JOIN invoices i ON i.client_id = c.id AND i.user_id = c.user_id
           LEFT JOIN payments p ON p.invoice_id = i.id
          WHERE c.user_id = ?
       GROUP BY c.id
       ORDER BY received DESC
          LIMIT ' . max(1, min(50, $limit))
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/** Which first-run steps are done, for the welcome checklist on an empty dashboard. */
function onboarding_steps(int $userId): array
{
    $profile = db()->prepare('SELECT iban, address_line1 FROM business_profiles WHERE user_id = ?');
    $profile->execute([$userId]);
    $p = $profile->fetch() ?: [];

    $exists = function (string $table) use ($userId): bool {
        $stmt = db()->prepare("SELECT 1 FROM {$table} WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        return (bool) $stmt->fetchColumn();
    };

    return [
        ['Complete your business profile', 'Address and IBAN appear on every invoice.', 'settings/', !empty($p['iban']) && !empty($p['address_line1'])],
        ['Add your first client',          'Who you send invoices to.',                  'clients/create.php', $exists('clients')],
        ['Save a product or service',      'Reuse prices and tax rates in one click.',   'products/create.php', $exists('products')],
        ['Create your first invoice',      'Numbered automatically, ready as PDF.',      'invoices/create.php', $exists('invoices')],
        ['Record a payment',               'Revenue is built from real payments.',       'invoices/?status=sent', $exists('payments')],
    ];
}

/** "3 minutes ago", "Yesterday", "Oct 12". */
function time_ago(string $datetime): string
{
    $then = new DateTimeImmutable($datetime);
    $now  = new DateTimeImmutable();
    $sec  = $now->getTimestamp() - $then->getTimestamp();

    return match (true) {
        $sec < 60                                   => 'Just now',
        $sec < 3600                                 => (int) floor($sec / 60) . ' min ago',
        $sec < 86400 && $then->format('Y-m-d') === $now->format('Y-m-d') => (int) floor($sec / 3600) . ' h ago',
        $then->format('Y-m-d') === $now->modify('-1 day')->format('Y-m-d') => 'Yesterday',
        $then->format('Y') === $now->format('Y')    => $then->format('M j'),
        default                                     => $then->format('M j, Y'),
    };
}
