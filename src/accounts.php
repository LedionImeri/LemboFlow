<?php
/**
 * Account rules: registration and credential checks.
 * Pages call these functions; they never write SQL for users themselves.
 */

declare(strict_types=1);

require_once __DIR__ . '/activity.php';

/** Emails are stored and compared in one form. */
function normalize_email(string $email): string
{
    return mb_strtolower(trim($email));
}

/**
 * Validate registration input. Returns field => message.
 * Passwords are read raw (not trimmed) and limited to 72 bytes, the bcrypt maximum.
 */
function validate_registration(array $input): array
{
    $errors = validate($input, [
        'name'                  => 'required|max:100',
        'business_name'         => 'required|max:150',
        'email'                 => 'required|email|max:190',
        'password'              => 'required|min:8',
        'password_confirmation' => 'required|same:password',
    ], [
        'name'                  => 'Your name',
        'business_name'         => 'Business name',
        'email'                 => 'Email',
        'password'              => 'Password',
        'password_confirmation' => 'Password confirmation',
    ]);

    if (!isset($errors['password'])) {
        $error = password_strength_error((string) $input['password'], 'Password');
        if ($error !== null) {
            $errors['password'] = $error;
        }
    }

    if (!isset($errors['email']) && str_ends_with(normalize_email((string) $input['email']), '@demo.lemboflow.invalid')) {
        $errors['email'] = 'This email address can’t be used.';
    }

    if (!isset($errors['email']) && email_exists(normalize_email((string) $input['email']))) {
        $errors['email'] = 'An account with this email already exists.';
    }

    return $errors;
}

/**
 * Rules shared by registration and password change (minimum length is checked
 * by validate()). 72 bytes is the bcrypt maximum.
 */
function password_strength_error(string $password, string $label): ?string
{
    if (strlen($password) > 72) {
        return "{$label} may not be longer than 72 characters.";
    }
    if (!preg_match('/\pL/u', $password) || !preg_match('/\d/', $password)) {
        return "{$label} must contain at least one letter and one number.";
    }
    return null;
}

function email_exists(string $email): bool
{
    $stmt = db()->prepare('SELECT 1 FROM users WHERE email = ?');
    $stmt->execute([$email]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Create the user and their business profile in one transaction.
 * Either both rows exist afterwards, or neither does.
 *
 * @return int The new user ID
 */
function register_account(string $name, string $businessName, string $email, string $password): int
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)')
            ->execute([$name, normalize_email($email), password_hash($password, PASSWORD_DEFAULT)]);
        $userId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO business_profiles (user_id, business_name, email) VALUES (?, ?, ?)')
            ->execute([$userId, $businessName, normalize_email($email)]);

        log_activity($userId, 'account.created', "Account created for {$businessName}");

        $pdo->commit();
        return $userId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Check an email and password. Returns the user ID, or null when either is wrong.
 *
 * When the email is unknown a dummy hash is still verified, so the response
 * time does not reveal which emails have accounts.
 */
function authenticate(string $email, string $password): ?int
{
    $stmt = db()->prepare('SELECT id, password_hash FROM users WHERE email = ?');
    $stmt->execute([normalize_email($email)]);
    $user = $stmt->fetch();

    if (!$user) {
        password_verify($password, password_hash('lemboflow-timing-dummy', PASSWORD_DEFAULT));
        return null;
    }

    if (!password_verify($password, $user['password_hash'])) {
        return null;
    }

    // Upgrade the hash when PHP's default algorithm or cost changes.
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }

    return (int) $user['id'];
}
