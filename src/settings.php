<?php
/**
 * Settings rules: business profile, logo upload, account details, password.
 */

declare(strict_types=1);

require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/accounts.php';

/** Currencies offered in the profile. Code => label. */
const SUPPORTED_CURRENCIES = [
    'EUR' => 'EUR — Euro (€)',
    'USD' => 'USD — US dollar ($)',
    'GBP' => 'GBP — British pound (£)',
    'CHF' => 'CHF — Swiss franc',
    'ALL' => 'ALL — Albanian lek (L)',
];

const LOGO_MAX_BYTES = 2 * 1024 * 1024;
const LOGO_TYPES     = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

// ---------------------------------------------------------------------------
// Business profile
// ---------------------------------------------------------------------------

function get_business_profile(int $userId): array
{
    $stmt = db()->prepare('SELECT * FROM business_profiles WHERE user_id = ?');
    $stmt->execute([$userId]);
    $profile = $stmt->fetch();

    if (!$profile) {
        throw new RuntimeException("Business profile missing for user {$userId}.");
    }
    return $profile;
}

/** Fields the business form may change. */
const BUSINESS_FIELDS = [
    'business_name', 'email', 'phone', 'website',
    'address_line1', 'address_line2', 'city', 'postal_code', 'country',
    'vat_number', 'bank_name', 'iban', 'swift_bic',
    'currency', 'invoice_prefix', 'payment_terms_days', 'default_tax_rate', 'default_notes',
];

/** Trim, normalise and pick only the allowed fields from the submitted form. */
function business_input(array $post): array
{
    $data = [];
    foreach (BUSINESS_FIELDS as $field) {
        $data[$field] = is_string($post[$field] ?? null) ? trim($post[$field]) : '';
    }

    $data['email']          = normalize_email($data['email']);
    $data['iban']           = strtoupper(preg_replace('/\s+/', '', $data['iban']));
    $data['swift_bic']      = strtoupper(preg_replace('/\s+/', '', $data['swift_bic']));
    $data['invoice_prefix'] = strtoupper($data['invoice_prefix']);
    $data['currency']       = strtoupper($data['currency']);

    if ($data['website'] !== '' && !preg_match('#^https?://#i', $data['website'])) {
        $data['website'] = 'https://' . $data['website'];
    }

    return $data;
}

function validate_business_profile(array $data): array
{
    $errors = validate($data, [
        'business_name'      => 'required|max:150',
        'email'              => 'email|max:190',
        'phone'              => 'max:40',
        'website'            => 'max:190',
        'address_line1'      => 'max:150',
        'address_line2'      => 'max:150',
        'city'               => 'max:100',
        'postal_code'        => 'max:20',
        'country'            => 'max:100',
        'vat_number'         => 'max:40',
        'bank_name'          => 'max:100',
        'iban'               => 'max:34',
        'swift_bic'          => 'max:11',
        'currency'           => 'required|in:' . implode(',', array_keys(SUPPORTED_CURRENCIES)),
        'invoice_prefix'     => 'required|max:10',
        'payment_terms_days' => 'required|integer|min:0|max:365',
        'default_tax_rate'   => 'required|numeric|min:0|max:100',
        'default_notes'      => 'max:2000',
    ], [
        'email'              => 'Business email',
        'vat_number'         => 'VAT number',
        'iban'               => 'IBAN',
        'swift_bic'          => 'SWIFT / BIC',
        'payment_terms_days' => 'Payment terms',
        'default_tax_rate'   => 'Default tax rate',
    ]);

    if (!isset($errors['website']) && $data['website'] !== '' && !filter_var($data['website'], FILTER_VALIDATE_URL)) {
        $errors['website'] = 'Website must be a valid address, like example.com.';
    }
    if (!isset($errors['invoice_prefix']) && !preg_match('/^[A-Z0-9]+$/', $data['invoice_prefix'])) {
        $errors['invoice_prefix'] = 'Invoice prefix may only contain letters and numbers.';
    }
    if (!isset($errors['iban']) && $data['iban'] !== '' && !is_valid_iban($data['iban'])) {
        $errors['iban'] = 'This IBAN is not valid. Check it for typos.';
    }
    if (!isset($errors['swift_bic']) && $data['swift_bic'] !== '' && !preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $data['swift_bic'])) {
        $errors['swift_bic'] = 'SWIFT / BIC must be 8 or 11 characters, like NCBAALTX.';
    }

    return $errors;
}

/** ISO 13616 check: country code, check digits, then the mod-97 checksum. */
function is_valid_iban(string $iban): bool
{
    if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) {
        return false;
    }

    $rearranged = substr($iban, 4) . substr($iban, 0, 4);
    $numeric    = '';
    foreach (str_split($rearranged) as $ch) {
        $numeric .= ctype_alpha($ch) ? (string) (ord($ch) - 55) : $ch;
    }

    // Piecewise mod 97, so the long number never overflows an integer.
    $remainder = 0;
    foreach (str_split($numeric, 7) as $chunk) {
        $remainder = (int) ($remainder . $chunk) % 97;
    }
    return $remainder === 1;
}

/** Group an IBAN in blocks of four for display: AL47 2121 1009 … */
function format_iban(?string $iban): string
{
    return $iban ? trim(chunk_split($iban, 4, ' ')) : '';
}

function update_business_profile(int $userId, array $data): void
{
    $sets   = [];
    $params = [];
    foreach (BUSINESS_FIELDS as $field) {
        $sets[]   = "{$field} = ?";
        $value    = $data[$field];
        // Optional text fields are stored as NULL rather than empty strings.
        $params[] = ($value === '' && !in_array($field, ['business_name', 'currency', 'invoice_prefix'], true)) ? null : $value;
    }
    $params[] = $userId;

    db()->prepare('UPDATE business_profiles SET ' . implode(', ', $sets) . ' WHERE user_id = ?')
        ->execute($params);

    log_activity($userId, 'settings.updated', 'Business profile updated');
}

/**
 * The number the next invoice will get, for display only (INV-2026-0001).
 * The real number is reserved atomically when an invoice is saved (Phase 6).
 */
function preview_next_invoice_number(int $userId, string $prefix): string
{
    $year = (int) date('Y');
    $stmt = db()->prepare('SELECT last_number FROM invoice_sequences WHERE user_id = ? AND year = ?');
    $stmt->execute([$userId, $year]);
    $next = (int) $stmt->fetchColumn() + 1;

    return sprintf('%s-%d-%04d', $prefix, $year, $next);
}

// ---------------------------------------------------------------------------
// Logo
// ---------------------------------------------------------------------------

/**
 * Validate an uploaded logo from $_FILES. Returns an error message, or null when
 * the file is acceptable (or no file was chosen).
 */
function validate_logo_upload(?array $file): ?string
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || $file['size'] > LOGO_MAX_BYTES) {
        return 'The logo must be 2 MB or smaller.';
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return 'The logo could not be uploaded. Please try again.';
    }

    // Trust the file's contents, never its name or the browser's MIME type.
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset(LOGO_TYPES[$mime]) || @getimagesize($file['tmp_name']) === false) {
        return 'The logo must be a PNG, JPG or WebP image.';
    }

    return null;
}

/** Move a validated upload into uploads/logos/ and return its relative path. */
function store_logo(int $userId, array $file): string
{
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $name = sprintf('%d_%s.%s', $userId, bin2hex(random_bytes(8)), LOGO_TYPES[$mime]);
    $dir  = BASE_PATH . '/uploads/logos';

    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('Could not create the logo folder.');
    }
    if (!move_uploaded_file($file['tmp_name'], "{$dir}/{$name}")) {
        throw new RuntimeException('Could not save the uploaded logo.');
    }

    return 'uploads/logos/' . $name;
}

/** Point the profile at a new logo (or none) and delete the old file. */
function replace_logo(int $userId, ?string $newPath): void
{
    $old = get_business_profile($userId)['logo_path'];

    db()->prepare('UPDATE business_profiles SET logo_path = ? WHERE user_id = ?')->execute([$newPath, $userId]);

    if ($old && $old !== $newPath) {
        delete_logo_file($old);
    }
}

/** Delete a logo file, but only if it really is inside uploads/logos/. */
function delete_logo_file(string $relativePath): void
{
    $dir  = realpath(BASE_PATH . '/uploads/logos');
    $file = realpath(BASE_PATH . '/' . $relativePath);

    if ($dir && $file && str_starts_with($file, $dir . DIRECTORY_SEPARATOR) && is_file($file)) {
        unlink($file);
    }
}

// ---------------------------------------------------------------------------
// Account & password
// ---------------------------------------------------------------------------

/**
 * Validate account changes. Changing the login email requires the current
 * password, so someone at an unlocked computer can't take over the account.
 */
function validate_account(int $userId, array $data, string $currentEmail): array
{
    $errors = validate($data, [
        'name'  => 'required|max:100',
        'email' => 'required|email|max:190',
    ], ['name' => 'Your name']);

    $emailChanged = normalize_email($data['email']) !== $currentEmail;

    if (!isset($errors['email']) && $emailChanged && str_ends_with(normalize_email($data['email']), '@demo.lemboflow.invalid')) {
        $errors['email'] = 'This email address can’t be used.';
    }

    if (!isset($errors['email']) && $emailChanged) {
        $stmt = db()->prepare('SELECT 1 FROM users WHERE email = ? AND id <> ?');
        $stmt->execute([normalize_email($data['email']), $userId]);
        if ($stmt->fetchColumn()) {
            $errors['email'] = 'An account with this email already exists.';
        } elseif ($data['current_password'] === '') {
            $errors['current_password'] = 'Enter your current password to change your email.';
        } elseif (!verify_user_password($userId, $data['current_password'])) {
            $errors['current_password'] = 'Current password is incorrect.';
        }
    }

    return $errors;
}

function update_account(int $userId, string $name, string $email): void
{
    db()->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?')
        ->execute([$name, normalize_email($email), $userId]);

    log_activity($userId, 'account.updated', 'Account details updated');
}

function verify_user_password(int $userId, string $password): bool
{
    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $hash = $stmt->fetchColumn();
    return is_string($hash) && password_verify($password, $hash);
}

function validate_password_change(int $userId, array $data): array
{
    $errors = validate($data, [
        'current_password'      => 'required',
        'password'              => 'required|min:8',
        'password_confirmation' => 'required|same:password',
    ], [
        'current_password'      => 'Current password',
        'password'              => 'New password',
        'password_confirmation' => 'Password confirmation',
    ]);

    if (!isset($errors['current_password']) && !verify_user_password($userId, $data['current_password'])) {
        $errors['current_password'] = 'Current password is incorrect.';
    }

    if (!isset($errors['password'])) {
        $error = password_strength_error($data['password'], 'New password');
        if ($error === null && !isset($errors['current_password']) && $data['password'] === $data['current_password']) {
            $error = 'New password must be different from the current one.';
        }
        if ($error !== null) {
            $errors['password'] = $error;
        }
    }

    return $errors;
}

function change_password(int $userId, string $newPassword): void
{
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);

    log_activity($userId, 'account.password_changed', 'Password changed');

    // New session ID: a stolen copy of the old session cookie stops working.
    session_regenerate_id(true);
}
