<?php
/**
 * Authentication and role-based access control.
 *
 * Every user has exactly one of eight roles. A role is allowed a fixed set of
 * permissions (see permission_matrix()). Each page asks for the permission it
 * needs with require_permission(), so access never depends on hiding a link.
 */
declare(strict_types=1);

/** Hash checked when a username does not exist, so response time does not reveal which names are real. */
const TIMING_DUMMY_HASH = '$2y$11$s.P4..0KtsinXErDMYFvJ.sP.ELi8FiBlTQzB0TtVEbm7SzPlJqXG';

/* ---------- Roles and permissions ---------- */

/** Role code => display name. */
function role_labels(): array
{
    return [
        'admin'        => 'System Administrator',
        'manager'      => 'Hospital Manager',
        'receptionist' => 'Receptionist / Records',
        'nurse'        => 'Nurse',
        'doctor'       => 'Doctor / Clinical Officer',
        'lab'          => 'Laboratory Technologist',
        'pharmacist'   => 'Pharmacist',
        'cashier'      => 'Cashier / Billing Clerk',
    ];
}

/** Permission => human description. Used by the user manual and the tests. */
function permission_catalogue(): array
{
    return [
        'patients.view'       => 'Search and open patient records',
        'patients.register'   => 'Register new patients',
        'patients.edit'       => 'Correct patient details',
        'patients.import'     => 'Import patients from a CSV file',
        'appointments.view'   => 'See the appointment diary',
        'appointments.book'   => 'Book appointments',
        'appointments.update' => 'Mark appointments attended, missed or cancelled',
        'visits.checkin'      => 'Check patients in and open visits',
        'visits.view'         => 'See visit queues and visit records',
        'triage.record'       => 'Record triage and vital signs',
        'services.record'     => 'Record procedures and other services',
        'consult.record'      => 'Record consultations and close visits',
        'lab.request'         => 'Request laboratory tests and read their results',
        'lab.worklist'        => 'See the laboratory worklist',
        'lab.result'          => 'Enter laboratory results',
        'pharmacy.prescribe'  => 'Prescribe medicines',
        'pharmacy.dispense'   => 'Dispense prescribed medicines',
        'pharmacy.stock'      => 'View medicine stock and alerts',
        'pharmacy.manage'     => 'Receive batches, adjust stock and edit the medicine list',
        'billing.view'        => 'See invoices and receipts',
        'billing.pay'         => 'Record payments',
        'reports.attendance'  => 'View the attendance report',
        'reports.revenue'     => 'View the revenue report',
        'reports.stock'       => 'View the stock report',
        'reports.lab'         => 'View the laboratory workload report',
        'users.manage'        => 'Create and manage user accounts',
        'requests.manage'     => 'Review staff access requests and contact messages',
        'audit.view'          => 'View the audit trail',
        'settings.manage'     => 'Change service and test prices',
        'system.health'       => 'View system health and backup status',
    ];
}

/** Role code => list of permissions. This is the single source of truth for access control. */
function permission_matrix(): array
{
    return [
        'admin' => ['users.manage', 'requests.manage', 'audit.view', 'settings.manage', 'system.health'],
        'manager' => [
            'audit.view', 'reports.attendance', 'reports.revenue', 'reports.stock', 'reports.lab',
            'pharmacy.stock',
        ],
        'receptionist' => [
            'patients.view', 'patients.register', 'patients.edit', 'patients.import',
            'appointments.view', 'appointments.book', 'appointments.update',
            'visits.checkin', 'visits.view',
        ],
        'nurse' => [
            'patients.view', 'appointments.view', 'visits.view', 'triage.record', 'services.record',
        ],
        'doctor' => [
            'patients.view', 'appointments.view', 'visits.view', 'consult.record', 'services.record',
            'lab.request', 'pharmacy.prescribe', 'pharmacy.stock',
        ],
        'lab' => ['lab.worklist', 'lab.result'],
        'pharmacist' => ['pharmacy.dispense', 'pharmacy.stock', 'pharmacy.manage', 'reports.stock'],
        'cashier' => ['billing.view', 'billing.pay'],
    ];
}

function role_can(string $role, string $permission): bool
{
    return in_array($permission, permission_matrix()[$role] ?? [], true);
}

/* ---------- Current user ---------- */

function current_user(): ?array
{
    $u = $_SESSION['user'] ?? null;
    return is_array($u) && isset($u['id'], $u['role']) ? $u : null;
}

function user_can(string $permission): bool
{
    $u = current_user();
    return $u !== null && role_can($u['role'], $permission);
}

/** Send the visitor to the sign-in page unless they are signed in. */
function require_login(): array
{
    $u = current_user();
    if ($u === null) {
        $next = $_SERVER['REQUEST_URI'] ?? '';
        redirect('login.php', $next !== '' && strlen($next) < 300 ? ['next' => $next] : []);
    }
    if (!empty($u['must_change_password']) && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'password.php'
        && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'logout.php') {
        flash('warning', 'Please choose a new password before you continue.');
        redirect('password.php');
    }
    return $u;
}

/** Require sign-in and a permission; show a 403 page (and log it) otherwise. */
function require_permission(string $permission): array
{
    $u = require_login();
    if (!role_can($u['role'], $permission)) {
        audit('access_denied', null, null, 'Permission ' . $permission . ' on ' . ($_SERVER['SCRIPT_NAME'] ?? ''));
        abort(403, 'Your account does not have permission to open this page.');
    }
    return $u;
}

/* ---------- Passwords ---------- */

/** Return a list of problems with a proposed password (empty list = acceptable). */
function password_problems(string $password, string $username = ''): array
{
    $problems = [];
    $min = (int) config('security.min_password_length', 10);
    if (mb_strlen($password) < $min) {
        $problems[] = "Use at least $min characters.";
    }
    if (!preg_match('/[a-z]/', $password)) {
        $problems[] = 'Include a lower-case letter.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $problems[] = 'Include an upper-case letter.';
    }
    if (!preg_match('/\d/', $password)) {
        $problems[] = 'Include a number.';
    }
    if ($username !== '' && stripos($password, $username) !== false) {
        $problems[] = 'Do not include your username.';
    }
    if (strlen($password) > 72) {
        $problems[] = 'Use at most 72 characters.';
    }
    return $problems;
}

/** bcrypt work factor. Tests lower it through UBHMS_BCRYPT_COST so they run quickly. */
function bcrypt_cost(): int
{
    $env = getenv('UBHMS_BCRYPT_COST');
    return $env !== false && $env !== '' ? (int) $env : (int) config('security.bcrypt_cost', 11);
}

function hash_password(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => bcrypt_cost()]);
}

/** Generate a one-time password that satisfies the password rules. */
function generate_temp_password(): string
{
    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower = 'abcdefghijkmnopqrstuvwxyz';
    $digits = '23456789';
    $pick = static fn (string $set): string => $set[random_int(0, strlen($set) - 1)];
    $chars = [$pick($upper), $pick($upper), $pick($lower), $pick($lower), $pick($lower), $pick($lower),
              $pick($digits), $pick($digits), $pick($digits), $pick($lower), $pick($upper), $pick($digits)];
    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
    }
    return implode('', $chars);
}

/**
 * Has a signed-in session run out of time? It expires after $idle seconds without
 * activity or $absolute seconds after sign-in, whichever comes first.
 */
function session_has_expired(array $session, int $now, int $idle, int $absolute): bool
{
    if (!isset($session['user'])) {
        return false;
    }
    return ($now - (int) ($session['last_activity'] ?? 0)) > $idle
        || ($now - (int) ($session['signed_in_at'] ?? 0)) > $absolute;
}

/* ---------- Sign-in ---------- */

/**
 * Check a username and password. Returns ['ok' => true, 'user' => row] or
 * ['ok' => false, 'error' => message]. The error never says whether the
 * username or the password was wrong.
 */
function attempt_login(string $username, string $password, string $ip): array
{
    $generic = 'The username or password is not correct.';
    $username = strtolower(trim($username));
    if ($username === '' || $password === '' || strlen($username) > 40) {
        return ['ok' => false, 'error' => $generic];
    }

    $ipFailures = (int) db_value(
        "SELECT COUNT(*) FROM audit_log WHERE action = 'login_failed' AND ip_address = ? AND created_at > (NOW() - INTERVAL 15 MINUTE)",
        [$ip]
    );
    if ($ipFailures >= (int) config('security.ip_failure_limit', 20)) {
        audit('login_blocked', 'user', null, 'Too many failed sign-ins from this computer', null, $ip);
        return ['ok' => false, 'error' => 'Too many failed attempts from this computer. Please wait 15 minutes and try again.'];
    }

    $user = db_one(
        'SELECT u.*, r.code AS role, (u.locked_until IS NOT NULL AND u.locked_until > NOW()) AS is_locked
           FROM users u JOIN roles r ON r.id = u.role_id WHERE u.username = ?',
        [$username]
    );

    $passwordOk = password_verify($password, $user['password_hash'] ?? TIMING_DUMMY_HASH);

    if ($user && $user['is_locked']) {
        audit('login_failed', 'user', (int) $user['id'], 'Account is locked', (int) $user['id'], $ip);
        return ['ok' => false, 'error' => 'This account is temporarily locked after too many wrong passwords. Try again later or ask the administrator.'];
    }

    if (!$user || !$passwordOk) {
        if ($user) {
            $max = (int) config('security.max_failed_logins', 5);
            $minutes = (int) config('security.lockout_minutes', 15);
            // locked_until is assigned first: MySQL/MariaDB evaluate SET clauses left to right,
            // so it still sees the count from before this failure.
            db_exec(
                'UPDATE users SET locked_until = IF(failed_attempts + 1 >= ?, NOW() + INTERVAL ? MINUTE, locked_until),
                        failed_attempts = failed_attempts + 1
                  WHERE id = ?',
                [$max, $minutes, $user['id']]
            );
            audit('login_failed', 'user', (int) $user['id'], 'Wrong password', (int) $user['id'], $ip);
        } else {
            audit('login_failed', 'user', null, 'Unknown username', null, $ip);
        }
        return ['ok' => false, 'error' => $generic];
    }

    if (!(int) $user['is_active']) {
        audit('login_failed', 'user', (int) $user['id'], 'Account is deactivated', (int) $user['id'], $ip);
        return ['ok' => false, 'error' => 'This account has been deactivated. Please contact the administrator.'];
    }

    db_exec('UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?', [$user['id']]);
    if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => bcrypt_cost()])) {
        db_exec('UPDATE users SET password_hash = ? WHERE id = ?', [hash_password($password), $user['id']]);
    }
    return ['ok' => true, 'user' => $user];
}

/** Start a fresh session for a verified user. */
function start_user_session(array $user): void
{
    if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true); // stops session fixation
    }
    $_SESSION['user'] = [
        'id'                   => (int) $user['id'],
        'username'             => $user['username'],
        'full_name'            => $user['full_name'],
        'role'                 => $user['role'],
        'must_change_password' => (int) $user['must_change_password'],
    ];
    $_SESSION['signed_in_at'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function end_user_session(): void
{
    $_SESSION = [];
    if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Strict']);
        }
        session_destroy();
    }
}

/** Change a user's password and (optionally) force a change at the next sign-in. */
function set_user_password(int $userId, string $password, bool $mustChange): void
{
    db_exec(
        'UPDATE users SET password_hash = ?, must_change_password = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?',
        [hash_password($password), $mustChange ? 1 : 0, $userId]
    );
}
