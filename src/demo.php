<?php
/**
 * Demo accounts.
 *
 * Every "View demo" click gets its own short-lived account, seeded with a year of
 * realistic data, so visitors never see or break each other's changes. Demo
 * accounts can't log in with a password (random, never shown), can't change
 * their login details, and are deleted on logout or after DEMO_LIFETIME_HOURS.
 *
 * All data goes through the normal app functions (create_invoice, record_payment…),
 * so the demo obeys exactly the same rules as real accounts.
 */

declare(strict_types=1);

require_once __DIR__ . '/payments.php';
require_once __DIR__ . '/clients.php';
require_once __DIR__ . '/products.php';
require_once __DIR__ . '/settings.php';

const DEMO_EMAIL_DOMAIN   = 'demo.lemboflow.invalid'; // .invalid is reserved: these addresses can never receive mail
const DEMO_LIFETIME_HOURS = 24;
const DEMO_MAX_ACCOUNTS   = 200;  // oldest are removed beyond this
const DEMO_STARTS_PER_HOUR = 5;   // per browser session (kept across demo restarts)
const DEMO_STARTS_PER_MINUTE_GLOBAL = 30; // site-wide; holds even for clients that drop cookies

function is_demo_email(string $email): bool
{
    return str_ends_with(mb_strtolower(trim($email)), '@' . DEMO_EMAIL_DOMAIN);
}

/** Seconds until this session may start another demo, or 0. */
function demo_throttle(): int
{
    // Site-wide brake, counted in the database, so it can't be reset by clearing cookies.
    $recent = (int) db()->query('SELECT COUNT(*) FROM users WHERE is_demo = 1 AND created_at > NOW() - INTERVAL 1 MINUTE')->fetchColumn();
    if ($recent >= DEMO_STARTS_PER_MINUTE_GLOBAL) {
        return 60;
    }

    $now    = time();
    $starts = array_filter($_SESSION['_demo_starts'] ?? [], fn ($t) => $t > $now - 3600);
    if (count($starts) >= DEMO_STARTS_PER_HOUR) {
        return min($starts) + 3600 - $now;
    }
    $starts[] = $now;
    $_SESSION['_demo_starts'] = array_values($starts);
    return 0;
}

/** Delete one demo account with everything it owns (including its logo file). */
function delete_demo_account(int $userId): void
{
    $pdo  = db();
    $stmt = $pdo->prepare('SELECT u.is_demo, bp.logo_path FROM users u LEFT JOIN business_profiles bp ON bp.user_id = u.id WHERE u.id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row || !(int) $row['is_demo']) {
        return; // never touch a real account
    }

    $pdo->beginTransaction();
    try {
        // Invoices first: clients are protected by ON DELETE RESTRICT while invoices exist.
        $pdo->prepare('DELETE FROM invoices WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM users WHERE id = ? AND is_demo = 1')->execute([$userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    if ($row['logo_path']) {
        delete_logo_file($row['logo_path']);
    }
}

/** Remove expired demo accounts, and the oldest ones beyond DEMO_MAX_ACCOUNTS. */
function cleanup_demo_accounts(): int
{
    $expired = db()->prepare('SELECT id FROM users WHERE is_demo = 1 AND created_at < NOW() - INTERVAL ? HOUR');
    $expired->execute([DEMO_LIFETIME_HOURS]);
    $ids = $expired->fetchAll(PDO::FETCH_COLUMN);

    $overflow = db()->query(
        'SELECT id FROM users WHERE is_demo = 1 ORDER BY created_at DESC, id DESC LIMIT 1000000 OFFSET ' . (DEMO_MAX_ACCOUNTS - 1)
    )->fetchAll(PDO::FETCH_COLUMN);

    $ids = array_unique(array_merge($ids, $overflow));
    foreach ($ids as $id) {
        delete_demo_account((int) $id);
    }
    return count($ids);
}

/** Create a new demo account with seeded data. Returns the user ID. */
function create_demo_account(): int
{
    $pdo   = db();
    $email = 'demo-' . bin2hex(random_bytes(6)) . '@' . DEMO_EMAIL_DOMAIN;

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO users (name, email, password_hash, is_demo) VALUES (?, ?, ?, 1)')
            ->execute(['Demo User', $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
        $userId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO business_profiles (user_id, business_name) VALUES (?, ?)')->execute([$userId, 'Studio Lembo']);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    try {
        seed_demo_business($userId);
    } catch (Throwable $e) {
        delete_demo_account($userId);
        throw $e;
    }

    return $userId;
}

/**
 * Fill an account with a design & development studio's last 12 months:
 * clients, products, ~45 invoices in every status, and their payments.
 * Deterministic (fixed random seed) but always relative to today.
 */
function seed_demo_business(int $userId): void
{
    mt_srand(2026);
    $today = new DateTimeImmutable('today');

    // --- Business profile -------------------------------------------------------
    update_business_profile($userId, business_input([
        'business_name'      => 'Studio Lembo',
        'email'              => 'hello@studiolembo.al',
        'phone'              => '+355 69 123 4567',
        'website'            => 'studiolembo.al',
        'address_line1'      => 'Rruga e Kavajës 10',
        'address_line2'      => '',
        'city'               => 'Tiranë',
        'postal_code'        => '1001',
        'country'            => 'Shqipëri',
        'vat_number'         => 'L12345678A',
        'bank_name'          => 'Raiffeisen Bank',
        'iban'               => 'AL47212110090000000235698741',
        'swift_bic'          => 'SGSBALTX',
        'currency'           => 'EUR',
        'invoice_prefix'     => 'INV',
        'payment_terms_days' => '14',
        'default_tax_rate'   => '20',
        'default_notes'      => "Faleminderit! Thank you for your business.\nPlease use the invoice number as the payment reference.",
    ]));
    $profile = get_business_profile($userId);

    // --- Clients ----------------------------------------------------------------
    $clientRows = [
        ['Kafe Dëshmorët sh.p.k.', 'Arbër Hoxha',     'arber@kafedeshmoret.al',  'Bulevardi Dëshmorët e Kombit 5', 'Durrës',  '2001', 'Shqipëri', 'K91234567L'],
        ['Acme Ltd',               'Sarah Collins',   'accounts@acme.co.uk',     '221 Baker Street',               'London',  'NW1 6XE', 'United Kingdom', 'GB123456789'],
        ['Northwind Traders',      'Jonas Weber',     'billing@northwind.de',    'Friedrichstraße 43',             'Berlin',  '10117', 'Germany', 'DE811234567'],
        ['Blue Harbor Hotel',      'Elira Basha',     'office@blueharbor.al',    'Rruga Butrinti 12',              'Sarandë', '9701', 'Shqipëri', 'L72315001B'],
        ['Pixel & Co',             'Giulia Rossi',    'amministrazione@pixelco.it', 'Via Tortona 27',              'Milano',  '20144', 'Italy', 'IT12345670151'],
        ['Teuta Fitness',          'Teuta Krasniqi',  'teuta@teutafitness.al',   'Rruga Myslym Shyri 40',          'Tiranë',  '1001', 'Shqipëri', 'M01234567A'],
        ['Alpine Dental Clinic',   'Dr. Lukas Huber', 'praxis@alpinedental.at',  'Mariahilfer Straße 88',          'Wien',    '1070', 'Austria', 'ATU12345678'],
        ['Rruga Books',            'Dritan Gjoka',    'dritan@rrugabooks.al',    'Rruga Kolë Idromeno 3',          'Shkodër', '4001', 'Shqipëri', ''],
    ];
    $clients = [];
    foreach ($clientRows as [$name, $contact, $email, $address, $city, $postal, $country, $vat]) {
        $clients[] = create_client($userId, client_input([
            'name' => $name, 'contact_name' => $contact, 'email' => $email, 'address_line1' => $address,
            'city' => $city, 'postal_code' => $postal, 'country' => $country, 'vat_number' => $vat,
        ]));
    }

    // --- Products & services ------------------------------------------------------
    $productRows = [
        ['service', 'Website design',          'Design and build of a responsive website, up to 6 pages', 'project', '1250.00', '20'],
        ['service', 'UX audit',                'Review of an existing site or app with a prioritised report', 'project', '650.00', '20'],
        ['service', 'Development',             'Front-end and back-end development',                       'hour',    '55.00',  '20'],
        ['service', 'Consulting',              'Strategy, planning and technical advice',                  'hour',    '65.50',  '20'],
        ['service', 'Logo & brand identity',   'Logo, colour palette, typography and a short brand guide', 'project', '900.00', '20'],
        ['service', 'SEO retainer',            'Monthly technical SEO and content recommendations',        'month',   '300.00', '20'],
        ['product', 'Hosting plan',            'Managed hosting with daily backups',                       'month',   '9.99',   '20'],
        ['product', 'Domain name .al',         'Registration or renewal, one year',                        'item',    '15.00',  '0'],
    ];
    $products = [];
    foreach ($productRows as [$type, $name, $desc, $unit, $price, $tax]) {
        $products[] = ['id' => create_product($userId, product_input([
            'type' => $type, 'name' => $name, 'description' => $desc, 'unit' => $unit,
            'unit_price' => $price, 'tax_rate' => $tax, 'is_active' => '1',
        ])), 'name' => $name, 'description' => $desc, 'unit' => $unit, 'price' => $price, 'tax' => $tax];
    }
    $archived = create_product($userId, product_input([
        'type' => 'product', 'name' => 'Printed flyers (A5, 500 pcs)', 'description' => 'No longer offered',
        'unit' => 'item', 'unit_price' => '120.00', 'tax_rate' => '20', 'is_active' => '1',
    ]));
    set_product_active($userId, find_product($userId, $archived), false);

    // --- Invoices & payments ------------------------------------------------------
    $line = function (array $p, string $qty) {
        return ['product_id' => (string) $p['id'], 'name' => $p['name'], 'description' => $p['description'],
                'unit' => $p['unit'], 'quantity' => $qty, 'unit_price' => $p['price'], 'tax_rate' => $p['tax']];
    };
    $randomLines = function () use ($products, $line): array {
        $pick = mt_rand(1, 10);
        return match (true) {
            $pick <= 2 => [$line($products[0], '1'), $line($products[6], '12'), $line($products[7], '1')],
            $pick <= 3 => [$line($products[1], '1')],
            $pick <= 5 => [$line($products[2], (string) mt_rand(12, 60))],
            $pick <= 7 => [$line($products[3], (string) (mt_rand(4, 24) / 2))],
            $pick <= 8 => [$line($products[4], '1'), $line($products[3], '3')],
            default    => [$line($products[5], '1'), $line($products[2], (string) mt_rand(4, 12))],
        };
    };
    $pay = function (int $invoiceId, DateTimeImmutable $on, ?string $amount = null, string $method = 'bank_transfer') use ($userId, $today) {
        $invoice = find_invoice($userId, $invoiceId);
        $on      = min($on, $today);
        record_payment($userId, $invoiceId, [
            'amount'    => $amount ?? hundredths_to_decimal(invoice_balance_cents($invoice)),
            'paid_on'   => $on->format('Y-m-d'),
            'method'    => $method,
            'reference' => $method === 'bank_transfer' ? 'TRX-' . mt_rand(100000, 999999) : '',
            'notes'     => '',
        ]);
    };

    for ($monthsAgo = 11; $monthsAgo >= 0; $monthsAgo--) {
        $monthStart = $today->modify('first day of this month')->modify("-{$monthsAgo} months");
        $count      = $monthsAgo === 0 ? 5 : mt_rand(3, 5) + (11 - $monthsAgo > 6 ? 1 : 0); // business grows

        for ($k = 0; $k < $count; $k++) {
            $issue = $monthStart->modify('+' . mt_rand(0, 25) . ' days');
            if ($issue > $today) {
                $issue = $today->modify('-' . $k . ' days');
            }
            $due = $issue->modify('+14 days');

            $discount = mt_rand(1, 8) === 1 ? ['percent', '10'] : ['none', '0'];
            $data = [
                'client_id'      => (string) $clients[mt_rand(0, count($clients) - 1)],
                'issue_date'     => $issue->format('Y-m-d'),
                'due_date'       => $due->format('Y-m-d'),
                'discount_type'  => $discount[0],
                'discount_value' => $discount[1],
                'notes'          => $profile['default_notes'],
                'items'          => $randomLines(),
            ];
            [$errors, $totals] = validate_invoice($userId, $data);
            if ($errors) {
                throw new RuntimeException('Demo seed produced an invalid invoice: ' . json_encode($errors));
            }

            // The newest invoice of this month stays a draft; one older one gets cancelled.
            $isDraft     = $monthsAgo === 0 && $k === $count - 1;
            $invoiceId   = create_invoice($userId, $data, $totals, $profile, !$isDraft);
            if ($isDraft) {
                continue;
            }
            if ($monthsAgo === 4 && $k === 0) {
                cancel_invoice($userId, find_invoice($userId, $invoiceId));
                continue;
            }

            $roll = mt_rand(1, 10);
            if ($monthsAgo >= 3) {
                // Older work: paid, mostly on time, occasionally in two parts.
                if ($roll <= 2) {
                    $pay($invoiceId, $issue->modify('+7 days'), '200.00');
                    $pay($invoiceId, $issue->modify('+' . mt_rand(16, 30) . ' days'));
                } else {
                    $pay($invoiceId, $issue->modify('+' . mt_rand(3, 18) . ' days'), null, $roll === 10 ? 'card' : 'bank_transfer');
                }
            } elseif ($monthsAgo >= 1) {
                // Recent work: a mix of paid, partly paid and unpaid (some will be overdue).
                if ($roll <= 5) {
                    $pay($invoiceId, $issue->modify('+' . mt_rand(4, 15) . ' days'));
                } elseif ($roll <= 7) {
                    $pay($invoiceId, $issue->modify('+6 days'), '150.00', 'cash');
                }
            } elseif ($k === 0) {
                // This month: the first invoice is already paid, the rest are still open.
                $pay($invoiceId, $issue->modify('+2 days'));
            }
        }
    }

    mark_overdue_invoices($userId);
    backdate_demo_history($userId);
}

/**
 * Seeding happens "now", so timestamps would all say today. Move them to when
 * each event would really have happened, and rebuild the activity feed to match.
 */
function backdate_demo_history(int $userId): void
{
    $pdo = db();

    $pdo->prepare(
        "UPDATE invoices
            SET created_at = TIMESTAMP(issue_date, '09:12:00'),
                updated_at = TIMESTAMP(issue_date, '09:12:00'),
                sent_at    = IF(sent_at IS NULL, NULL, TIMESTAMP(issue_date, '09:30:00')),
                cancelled_at = IF(cancelled_at IS NULL, NULL, TIMESTAMP(issue_date + INTERVAL 3 DAY, '11:05:00'))
          WHERE user_id = ?"
    )->execute([$userId]);
    $pdo->prepare("UPDATE payments SET created_at = TIMESTAMP(paid_on, '14:20:00') WHERE user_id = ?")->execute([$userId]);
    $pdo->prepare(
        "UPDATE invoices i
            JOIN (SELECT invoice_id, MAX(created_at) AS last_paid FROM payments GROUP BY invoice_id) p ON p.invoice_id = i.id
            SET i.paid_at = p.last_paid
          WHERE i.user_id = ? AND i.status = 'paid'"
    )->execute([$userId]);
    $first = $pdo->prepare('SELECT MIN(issue_date) FROM invoices WHERE user_id = ?');
    $first->execute([$userId]);
    $start = $first->fetchColumn() ?: date('Y-m-d');
    foreach (['clients', 'products'] as $table) {
        $pdo->prepare("UPDATE {$table} SET created_at = TIMESTAMP(? - INTERVAL 7 DAY, '10:00:00'), updated_at = TIMESTAMP(? - INTERVAL 7 DAY, '10:00:00') WHERE user_id = ?")
            ->execute([$start, $start, $userId]);
    }
    // users.created_at is NOT backdated: demo expiry (DEMO_LIFETIME_HOURS) is measured from it.

    // Rebuild the activity feed from the backdated events.
    $pdo->prepare('DELETE FROM activities WHERE user_id = ?')->execute([$userId]);
    $pdo->prepare(
        "INSERT INTO activities (user_id, type, subject_type, subject_id, description, created_at)
         SELECT i.user_id, 'invoice.created', 'invoice', i.id, CONCAT('Invoice ', i.invoice_number, ' created for ', c.name), i.created_at
           FROM invoices i JOIN clients c ON c.id = i.client_id WHERE i.user_id = ?
         UNION ALL
         SELECT p.user_id, 'payment.recorded', 'payment', p.id,
                CONCAT('Payment of €', FORMAT(p.amount, 2), ' received for ', i.invoice_number), p.created_at
           FROM payments p JOIN invoices i ON i.id = p.invoice_id WHERE p.user_id = ?
         UNION ALL
         SELECT i.user_id, 'invoice.paid', 'invoice', i.id, CONCAT('Invoice ', i.invoice_number, ' paid in full'), i.paid_at + INTERVAL 1 SECOND
           FROM invoices i WHERE i.user_id = ? AND i.status = 'paid'
         UNION ALL
         SELECT i.user_id, 'invoice.overdue', 'invoice', i.id, CONCAT('Invoice ', i.invoice_number, ' is overdue'), TIMESTAMP(i.due_date + INTERVAL 1 DAY, '08:00:00')
           FROM invoices i WHERE i.user_id = ? AND i.status = 'overdue'
         UNION ALL
         SELECT i.user_id, 'invoice.cancelled', 'invoice', i.id, CONCAT('Invoice ', i.invoice_number, ' cancelled'), i.cancelled_at
           FROM invoices i WHERE i.user_id = ? AND i.status = 'cancelled'"
    )->execute([$userId, $userId, $userId, $userId, $userId]);
}

/**
 * A demo visitor opening the login or register page wants their own account:
 * end the demo (log out and delete it) so the page shows normally.
 */
function end_demo_session_if_any(): void
{
    $user = is_logged_in() ? current_user() : null;
    if (!$user || !$user['is_demo']) {
        return;
    }
    $starts = $_SESSION['_demo_starts'] ?? [];
    logout_user();
    delete_demo_account((int) $user['id']);
    session_start();
    session_regenerate_id(true);
    $_SESSION['_demo_starts'] = $starts; // ending a demo must not reset the start limit
}
