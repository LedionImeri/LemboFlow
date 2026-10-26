<?php
/**
 * Shared helpers: output escaping, URLs, redirects, flash messages, old input,
 * validation, formatting and small view helpers.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------

/** Escape for HTML output. Use on every value printed into a page. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Absolute URL inside the app: url('invoices/view.php?id=5'). */
function url(string $path = ''): string
{
    return rtrim((string) config('app_url'), '/') . '/' . ltrim($path, '/');
}

/** URL to a file in /assets with a cache-busting version from its modification time. */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = BASE_PATH . '/assets/' . $path;
    $v    = is_file($file) ? (string) filemtime($file) : '1';
    return url('assets/' . $path) . '?v=' . $v;
}

/** Inline reference to an icon in the local SVG sprite. */
function icon(string $name, string $class = ''): string
{
    $class = trim('icon ' . $class);
    return '<svg class="' . e($class) . '" aria-hidden="true" focusable="false">'
         . '<use href="' . e(url('assets/images/icons.svg') . '#i-' . $name) . '"></use></svg>';
}

// ---------------------------------------------------------------------------
// Requests & responses
// ---------------------------------------------------------------------------

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** True for fetch() calls that want JSON back, and for anything under /api/. */
function is_json_request(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $uri    = $_SERVER['REQUEST_URI'] ?? '';
    return str_contains($accept, 'application/json') || str_contains($uri, '/api/');
}

/** Trimmed string from POST (or GET when not posting). */
function input(string $key, string $default = ''): string
{
    $source = is_post() ? $_POST : $_GET;
    $value  = $source[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

/** Positive integer id from the query string, or 0. */
function query_id(string $key = 'id'): int
{
    $id = filter_var($_GET[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? 0 : (int) $id;
}

function redirect(string $path): never
{
    $target = preg_match('#^https?://#', $path) ? $path : url($path);
    header('Location: ' . $target, true, 303);
    exit;
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Stop with an error page (or JSON for API calls). */
function abort(int $status = 404, ?string $message = null): never
{
    $titles = [
        400 => 'Bad request',
        403 => 'Access denied',
        404 => 'Page not found',
        405 => 'Method not allowed',
        419 => 'Session expired',
        500 => 'Something went wrong',
    ];
    $title   = $titles[$status] ?? 'Error';
    $message ??= match ($status) {
        404     => 'The page you are looking for does not exist or was moved.',
        419     => 'Your session expired. Go back, refresh the page and try again.',
        default => 'The request could not be completed.',
    };

    if (is_json_request()) {
        json_response(['ok' => false, 'error' => $message], $status);
    }

    render_error_page($status, $title, $message);
    exit;
}

/** Standalone error page. Does not touch the database or the session layout. */
function render_error_page(int $status, string $title, string $message): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
    }
    $css     = e(asset('css/app.css'));
    $home    = e(url('dashboard/'));
    $title   = e($title);
    $message = e($message);
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$status} · LemboFlow</title>
<link rel="stylesheet" href="{$css}">
</head>
<body class="error-body">
<main class="error-page">
  <p class="error-page__code">{$status}</p>
  <h1 class="error-page__title">{$title}</h1>
  <p class="error-page__text">{$message}</p>
  <a class="btn btn--primary" href="{$home}">Back to dashboard</a>
</main>
</body>
</html>
HTML;
}

// ---------------------------------------------------------------------------
// Flash messages, old input and validation errors
// Everything stored here survives exactly one redirect.
// ---------------------------------------------------------------------------

/** Queue a toast for the next page: flash('success', 'Client saved.'). */
function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

/** @return array<int, array{type: string, message: string}> */
function pull_flashes(): array
{
    $flashes = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $flashes;
}

/** Keep the submitted form values and errors for the page we redirect back to. */
function with_input(array $input, array $errors = []): void
{
    unset($input['_csrf'], $input['password'], $input['password_confirmation'], $input['current_password']);
    $_SESSION['_old']    = $input;
    $_SESSION['_errors'] = $errors;
}

/** Old input and errors from the previous request, loaded once per request. */
function form_state(): array
{
    static $state = null;
    if ($state === null) {
        $state = [
            'old'    => $_SESSION['_old'] ?? [],
            'errors' => $_SESSION['_errors'] ?? [],
        ];
        unset($_SESSION['_old'], $_SESSION['_errors']);
    }
    return $state;
}

/** Previous value for a field, falling back to $default (e.g. the saved record). */
function old(string $key, mixed $default = ''): mixed
{
    $old = form_state()['old'];
    return array_key_exists($key, $old) ? $old[$key] : $default;
}

/** @return array<string, string> */
function errors(): array
{
    return form_state()['errors'];
}

function field_error(string $key): string
{
    $msg = errors()[$key] ?? null;
    return $msg === null ? '' : '<p class="field__error" id="' . e($key) . '-error">' . e($msg) . '</p>';
}

/** Adds the invalid state + aria attributes to an input when the field has an error. */
function field_attrs(string $key): string
{
    return isset(errors()[$key])
        ? ' aria-invalid="true" aria-describedby="' . e($key) . '-error"'
        : '';
}

/**
 * Render a labelled form field with its old value, error and hint.
 *
 *   form_field('email', 'Email', $client['email'], ['type' => 'email', 'required' => true]);
 *   form_field('notes', 'Notes', $notes, ['type' => 'textarea', 'class' => 'span-2']);
 *   form_field('currency', 'Currency', 'EUR', ['type' => 'select', 'options' => ['EUR' => 'Euro']]);
 *   form_field('tax_rate', 'Tax rate', 20, ['type' => 'number', 'suffix' => '%', 'step' => '0.01']);
 *
 * Options: type, required, optional (shows "optional"), hint, placeholder,
 * autocomplete, maxlength, minlength, min, max, step, inputmode, list, suffix, class, options, rows, autofocus
 */
function form_field(string $name, string $label, mixed $value = '', array $o = []): string
{
    $type     = $o['type'] ?? 'text';
    $id       = $o['id'] ?? $name;
    $value    = old($name, $value ?? '');
    $hasError = isset(errors()[$name]);
    $hintId   = $id . '-hint';

    $attrs = '';
    foreach (['placeholder', 'autocomplete', 'maxlength', 'minlength', 'min', 'max', 'step', 'inputmode', 'rows', 'list'] as $attr) {
        if (isset($o[$attr])) {
            $attrs .= ' ' . $attr . '="' . e($o[$attr]) . '"';
        }
    }
    if (!empty($o['required'])) {
        $attrs .= ' required';
    }
    if (!empty($o['autofocus'])) {
        $attrs .= ' autofocus';
    }
    if ($hasError) {
        $attrs .= ' aria-invalid="true" aria-describedby="' . e($name) . '-error"';
    } elseif (!empty($o['hint'])) {
        $attrs .= ' aria-describedby="' . e($hintId) . '"';
    }

    $numClass = in_array($type, ['number'], true) ? ' input--num' : '';

    $control = match ($type) {
        'textarea' => '<textarea class="textarea" id="' . e($id) . '" name="' . e($name) . '"' . $attrs . '>' . e($value) . '</textarea>',
        'select'   => (function () use ($id, $name, $attrs, $value, $o): string {
            $html = '<select class="select" id="' . e($id) . '" name="' . e($name) . '"' . $attrs . '>';
            foreach ($o['options'] ?? [] as $optValue => $optLabel) {
                $selected = (string) $optValue === (string) $value ? ' selected' : '';
                $html    .= '<option value="' . e($optValue) . '"' . $selected . '>' . e($optLabel) . '</option>';
            }
            return $html . '</select>';
        })(),
        default    => '<input class="input' . $numClass . '" id="' . e($id) . '" name="' . e($name) . '" type="' . e($type) . '" value="' . e($value) . '"' . $attrs . '>',
    };

    if (!empty($o['suffix'])) {
        $control = '<div class="input-group">' . $control . '<span class="input-group__addon">' . e($o['suffix']) . '</span></div>';
    }

    $optional = !empty($o['optional']) ? ' <span class="optional">(optional)</span>' : '';
    $hint     = (!$hasError && !empty($o['hint'])) ? '<p class="field__hint" id="' . e($hintId) . '">' . e($o['hint']) . '</p>' : '';
    $class    = trim('field ' . ($o['class'] ?? ''));

    return '<div class="' . e($class) . '">'
         . '<label class="field__label" for="' . e($id) . '">' . e($label) . $optional . '</label>'
         . $control . field_error($name) . $hint
         . '</div>';
}

/**
 * Validate an array against pipe-separated rules. Returns field => first error.
 *
 *   validate($_POST, [
 *       'email' => 'required|email|max:190',
 *       'total' => 'required|numeric|min:0',
 *       'type'  => 'required|in:service,product',
 *   ], ['email' => 'Email address']);
 *
 * Rules: required, email, numeric, integer, date, min:n, max:n, in:a,b, same:field
 * min/max compare length for text and value for numeric/integer fields.
 *
 * @return array<string, string>
 */
function validate(array $data, array $rules, array $labels = []): array
{
    $errors = [];

    foreach ($rules as $field => $ruleString) {
        $label   = $labels[$field] ?? ucfirst(str_replace('_', ' ', $field));
        $raw     = $data[$field] ?? '';
        $value   = is_string($raw) ? trim($raw) : $raw;
        $rules_  = explode('|', $ruleString);
        $numeric = in_array('numeric', $rules_, true) || in_array('integer', $rules_, true);

        if ($value === '' || $value === null) {
            if (in_array('required', $rules_, true)) {
                $errors[$field] = "{$label} is required.";
            }
            continue;
        }

        foreach ($rules_ as $rule) {
            [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);

            $error = match ($name) {
                'required' => null,
                'email'    => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : "{$label} must be a valid email address.",
                'numeric'  => is_numeric($value) ? null : "{$label} must be a number.",
                'integer'  => filter_var($value, FILTER_VALIDATE_INT) !== false ? null : "{$label} must be a whole number.",
                'date'     => is_valid_date((string) $value) ? null : "{$label} must be a valid date.",
                'min'      => $numeric
                    ? (is_numeric($value) && (float) $value >= (float) $param ? null : "{$label} must be at least {$param}.")
                    : (mb_strlen((string) $value) >= (int) $param ? null : "{$label} must be at least {$param} characters."),
                'max'      => $numeric
                    ? (is_numeric($value) && (float) $value <= (float) $param ? null : "{$label} may not be greater than {$param}.")
                    : (mb_strlen((string) $value) <= (int) $param ? null : "{$label} may not be longer than {$param} characters."),
                'in'       => in_array((string) $value, explode(',', (string) $param), true) ? null : "{$label} has an invalid value.",
                'same'     => (string) $value === trim((string) ($data[$param] ?? '')) ? null : "{$label} does not match.",
                default    => throw new InvalidArgumentException("Unknown validation rule: {$name}"),
            };

            if ($error !== null) {
                $errors[$field] = $error;
                break;
            }
        }
    }

    return $errors;
}

/** "1.234,50" or "1234,5" → "1234.50"-style string; anything else is returned trimmed. */
function normalize_decimal(string $value): string
{
    $value = str_replace([' ', "\u{00A0}"], '', trim($value));
    if (str_contains($value, ',') && str_contains($value, '.')) {
        // Whichever separator comes last is the decimal one.
        $value = strrpos($value, ',') > strrpos($value, '.')
            ? str_replace(['.', ','], ['', '.'], $value)
            : str_replace(',', '', $value);
    } elseif (str_contains($value, ',')) {
        $value = str_replace(',', '.', $value);
    }
    return $value;
}

/** "20.00" → "20", "1.50" → "1.5", "0.00" → "0": a stored decimal shown without trailing zeros. */
function trim_decimal(string $value): string
{
    if (!str_contains($value, '.')) {
        return $value === '' ? '0' : $value;
    }
    $trimmed = rtrim(rtrim($value, '0'), '.');
    return $trimmed === '' ? '0' : $trimmed;
}

/** Strict Y-m-d check (rejects 2026-02-30). */
function is_valid_date(string $value): bool
{
    $d = DateTime::createFromFormat('!Y-m-d', $value);
    return $d !== false && $d->format('Y-m-d') === $value;
}

// ---------------------------------------------------------------------------
// Lists: search & pagination
// ---------------------------------------------------------------------------

/** Escape % and _ so user input is matched literally inside LIKE '%…%'. */
function like_escape(string $value): string
{
    return '%' . addcslashes($value, '%_\\') . '%';
}

/** Current page number from ?page=, at least 1. */
function current_page(): int
{
    return max(1, (int) ($_GET['page'] ?? 1));
}

/**
 * Pagination numbers for a list.
 * @return array{page:int, pages:int, per_page:int, offset:int, total:int, from:int, to:int}
 */
function paginate(int $total, int $page, int $perPage = 15): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    $page  = min(max(1, $page), $pages);
    $offset = ($page - 1) * $perPage;

    return [
        'page'     => $page,
        'pages'    => $pages,
        'per_page' => $perPage,
        'offset'   => $offset,
        'total'    => $total,
        'from'     => $total === 0 ? 0 : $offset + 1,
        'to'       => min($offset + $perPage, $total),
    ];
}

/**
 * Pagination bar. $path is the list page (e.g. 'clients/'), $query the current
 * filters, which every page link keeps.
 */
function render_pagination(array $p, string $path, array $query = [], string $noun = 'results'): string
{
    if ($p['total'] === 0) {
        return '';
    }

    $link = function (int $page) use ($path, $query): string {
        $q = array_filter($query + ['page' => $page], fn ($v) => $v !== '' && $v !== null);
        if ($page === 1) {
            unset($q['page']);
        }
        return url($path . ($q ? '?' . http_build_query($q) : ''));
    };

    $html  = '<nav class="pagination" aria-label="Pagination">';
    $html .= '<span>Showing ' . $p['from'] . '–' . $p['to'] . ' of ' . $p['total'] . ' ' . e($noun) . '</span>';

    if ($p['pages'] > 1) {
        $html .= '<div class="pagination__links">';
        $html .= $p['page'] > 1
            ? '<a class="btn btn--secondary btn--sm btn--icon" href="' . e($link($p['page'] - 1)) . '" aria-label="Previous page">' . icon('chevron-left') . '</a>'
            : '<span class="btn btn--secondary btn--sm btn--icon" aria-disabled="true">' . icon('chevron-left') . '</span>';
        $html .= '<span class="pagination__current">Page ' . $p['page'] . ' of ' . $p['pages'] . '</span>';
        $html .= $p['page'] < $p['pages']
            ? '<a class="btn btn--secondary btn--sm btn--icon" href="' . e($link($p['page'] + 1)) . '" aria-label="Next page">' . icon('chevron-right') . '</a>'
            : '<span class="btn btn--secondary btn--sm btn--icon" aria-disabled="true">' . icon('chevron-right') . '</span>';
        $html .= '</div>';
    }

    return $html . '</nav>';
}

// ---------------------------------------------------------------------------
// Formatting
// ---------------------------------------------------------------------------

/** money(1234.5, 'EUR') → "€1,234.50" · money(1234.5, 'ALL') → "1,234.50 L" */
function money(float|int|string|null $amount, string $currency = 'EUR'): string
{
    static $symbols = [
        'EUR' => ['€', 'before'],
        'USD' => ['$', 'before'],
        'GBP' => ['£', 'before'],
        'CHF' => ['CHF ', 'before'],
        'ALL' => [' L', 'after'],
    ];

    $amount   = (float) ($amount ?? 0);
    $negative = $amount < 0;
    $number   = number_format(abs($amount), 2, '.', ',');
    [$symbol, $position] = $symbols[strtoupper($currency)] ?? [' ' . strtoupper($currency), 'after'];

    $formatted = $position === 'before' ? $symbol . $number : $number . $symbol;
    return ($negative ? '−' : '') . $formatted;
}

/** format_date('2026-10-24') → "Oct 24, 2026". Empty input gives "—". */
function format_date(?string $date, string $format = 'M j, Y'): string
{
    if ($date === null || $date === '') {
        return '—';
    }
    $ts = strtotime($date);
    return $ts === false ? '—' : date($format, $ts);
}

/** Initials for avatars: "Jane Doe" → "JD". */
function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $first = mb_substr($parts[0] ?? '', 0, 1);
    $last  = count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) : '';
    return mb_strtoupper($first . $last) ?: '?';
}

/** Status badge for invoices and payment states. */
function status_badge(string $status): string
{
    $labels = [
        'draft'     => 'Draft',
        'sent'      => 'Sent',
        'paid'      => 'Paid',
        'partial'   => 'Partial',
        'unpaid'    => 'Unpaid',
        'overdue'   => 'Overdue',
        'cancelled' => 'Cancelled',
    ];
    $label = $labels[$status] ?? ucfirst($status);
    return '<span class="badge badge--' . e($status) . '">' . e($label) . '</span>';
}
