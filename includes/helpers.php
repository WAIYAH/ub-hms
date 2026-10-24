<?php
/**
 * General helpers: configuration, escaping, URLs, flash messages, input
 * reading, validation and formatting.
 */
declare(strict_types=1);

/* ---------- Configuration and time ---------- */

/** Read a setting such as config('clinic.slot_minutes'). */
function config(string $key, $default = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require APP_ROOT . '/config/config.php';
        $local = APP_ROOT . '/config/config.local.php';
        if (is_file($local)) {
            $cfg = array_replace_recursive($cfg, require $local);
        }
    }
    $value = $cfg;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

/** Current time. Tests can freeze it by setting $GLOBALS['__now']. */
function app_now(): DateTimeImmutable
{
    return isset($GLOBALS['__now']) ? new DateTimeImmutable($GLOBALS['__now']) : new DateTimeImmutable('now');
}

function app_today(): string
{
    return app_now()->format('Y-m-d');
}

/* ---------- Output escaping ---------- */

/** Escape text for HTML. Use on every value printed into a page. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ---------- URLs and redirects ---------- */

/** Folder the application is served from, e.g. "/ub-hms" or "" (document root). */
function base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $root = str_replace('\\', '/', APP_ROOT);
    $name = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script !== '' && strncasecmp($script, $root, strlen($root)) === 0) {
        $relative = substr($script, strlen($root));
        if ($relative !== '' && substr($name, -strlen($relative)) === $relative) {
            return $base = rtrim(substr($name, 0, -strlen($relative)), '/');
        }
    }
    return $base = '';
}

function url(string $path = '', array $query = []): string
{
    $u = base_path() . '/' . ltrim($path, '/');
    return $query ? $u . '?' . http_build_query($query) : $u;
}

function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/'));
}

function redirect(string $path, array $query = []): void
{
    header('Location: ' . url($path, $query), true, 303);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function is_https(): bool
{
    return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
}

/** Address of the computer making the request (used in the audit trail). */
function client_ip(): string
{
    if (PHP_SAPI === 'cli') {
        return '127.0.0.1';
    }
    if (config('security.trusted_proxy') && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $first = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/* ---------- Flash messages and remembered form input ---------- */

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $all = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $all;
}

/** Keep submitted values and errors for one redirect so a form can be refilled. */
function remember_form(array $values, array $errors): void
{
    $_SESSION['form'] = ['values' => $values, 'errors' => $errors];
}

function take_form(): array
{
    $form = $_SESSION['form'] ?? ['values' => [], 'errors' => []];
    unset($_SESSION['form']);
    return $form;
}

/* ---------- Reading input ---------- */

function post_str(string $key, int $max = 255): string
{
    return clean_text($_POST[$key] ?? '', $max);
}

function post_int(string $key): ?int
{
    $v = $_POST[$key] ?? '';
    return is_string($v) && preg_match('/^\d{1,10}$/', trim($v)) ? (int) $v : null;
}

function get_str(string $key, int $max = 255): string
{
    return clean_text($_GET[$key] ?? '', $max);
}

function get_int(string $key): ?int
{
    $v = $_GET[$key] ?? '';
    return is_string($v) && preg_match('/^\d{1,10}$/', trim($v)) ? (int) $v : null;
}

/** Trim, remove control characters, collapse spaces and cut to $max characters. */
function clean_text($value, int $max = 255): string
{
    if (!is_string($value)) {
        return '';
    }
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    $value = trim(preg_replace('/[ \t]+/u', ' ', $value) ?? '');
    return mb_substr($value, 0, $max, 'UTF-8');
}

/** Like clean_text but keeps line breaks (for notes and clinical text). */
function clean_multiline($value, int $max = 2000): string
{
    if (!is_string($value)) {
        return '';
    }
    $value = str_replace("\r\n", "\n", $value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    return mb_substr(trim($value), 0, $max, 'UTF-8');
}

/* ---------- Validation ---------- */

function is_valid_date(string $value): bool
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $d !== false && $d->format('Y-m-d') === $value;
}

function is_valid_time(string $value): bool
{
    return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $value);
}

/**
 * Turn a Kenyan phone number into +254XXXXXXXXX, or return null if it is not valid.
 * Accepts 07xx xxx xxx, 01xx xxx xxx, 7xxxxxxxx, 254xxxxxxxxx and +254xxxxxxxxx.
 */
function normalize_phone(string $raw): ?string
{
    $digits = preg_replace('/[\s\-().]/', '', $raw) ?? '';
    if (preg_match('/^(?:\+?254|0)?([17]\d{8})$/', $digits, $m)) {
        return '+254' . $m[1];
    }
    return null;
}

/** Whole years between two dates. */
function age_on(string $dateOfBirth, string $onDate = ''): int
{
    $dob = new DateTimeImmutable($dateOfBirth);
    $on = new DateTimeImmutable($onDate !== '' ? $onDate : app_today());
    return (int) $dob->diff($on)->y;
}

/** "3 years", "7 months" or "12 days" for display. */
function age_label(string $dateOfBirth): string
{
    $diff = (new DateTimeImmutable($dateOfBirth))->diff(app_now());
    if ($diff->y >= 2) {
        return $diff->y . ' years';
    }
    if ($diff->y === 1) {
        return '1 year' . ($diff->m ? ', ' . $diff->m . ' mo' : '');
    }
    if ($diff->m >= 1) {
        return plural($diff->m, 'month');
    }
    return plural((int) $diff->days, 'day');
}

/* ---------- Formatting ---------- */

function fmt_date(?string $date): string
{
    return $date ? (new DateTimeImmutable($date))->format('d M Y') : '';
}

function fmt_datetime(?string $dt): string
{
    return $dt ? (new DateTimeImmutable($dt))->format('d M Y, H:i') : '';
}

function fmt_time(?string $time): string
{
    return $time ? substr($time, 0, 5) : '';
}

function fmt_money($amount): string
{
    return config('billing.currency', 'KSh') . ' ' . number_format((float) $amount, 2);
}

/** Convert a money value to whole cents so totals are compared without rounding errors. */
function to_cents($amount): int
{
    return (int) round(((float) $amount) * 100);
}

function patient_full_name(array $p): string
{
    return implode(' ', array_filter([$p['first_name'], $p['middle_name'] ?? '', $p['last_name']], static fn ($part) => $part !== ''));
}

/** Build the part of an HTML class attribute for a status badge, e.g. "badge-booked". */
function badge(string $status): string
{
    $class = 'badge-' . strtolower(preg_replace('/[^a-z0-9]+/i', '-', $status) ?? '');
    return '<span class="badge ' . e($class) . '">' . e($status) . '</span>';
}

function plural(int $n, string $one, string $many = ''): string
{
    return $n . ' ' . ($n === 1 ? $one : ($many !== '' ? $many : $one . 's'));
}

/** Write a line to the application log (never contains patient information). */
function log_message(string $level, string $message): void
{
    $dir = config('paths.logs');
    if (!is_dir($dir)) {
        return;
    }
    $line = sprintf("[%s] %s %s\n", date('Y-m-d H:i:s'), strtoupper($level), $message);
    @file_put_contents($dir . '/app.log', $line, FILE_APPEND | LOCK_EX);
}
