<?php
/**
 * Module 6: user accounts. Used by the administrator pages.
 */
declare(strict_types=1);

function role_id_by_code(string $code): ?int
{
    $id = db_value('SELECT id FROM roles WHERE code = ?', [$code]);
    return $id === null ? null : (int) $id;
}

function get_user(int $id): ?array
{
    return db_one(
        'SELECT u.*, r.code AS role, r.name AS role_name,
                (u.locked_until IS NOT NULL AND u.locked_until > NOW()) AS is_locked
           FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?',
        [$id]
    );
}

function list_users(string $search = '', string $role = ''): array
{
    $sql = 'SELECT u.id, u.full_name, u.username, u.phone, u.is_active, u.last_login_at,
                   r.code AS role, r.name AS role_name,
                   (u.locked_until IS NOT NULL AND u.locked_until > NOW()) AS is_locked
              FROM users u JOIN roles r ON r.id = u.role_id WHERE 1 = 1';
    $params = [];
    if ($search !== '') {
        $sql .= ' AND (u.full_name LIKE ? OR u.username LIKE ?)';
        $like = '%' . like_escape($search) . '%';
        array_push($params, $like, $like);
    }
    if ($role !== '') {
        $sql .= ' AND r.code = ?';
        $params[] = $role;
    }
    return db_all($sql . ' ORDER BY u.is_active DESC, u.full_name', $params);
}

/** Active doctors, for appointment booking and visit assignment. */
function list_doctors(): array
{
    return db_all(
        "SELECT u.id, u.full_name FROM users u JOIN roles r ON r.id = u.role_id
          WHERE r.code = 'doctor' AND u.is_active = 1 ORDER BY u.full_name"
    );
}

function count_active_admins(): int
{
    return (int) db_value("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'admin' AND u.is_active = 1");
}

/**
 * Validate the account form. Returns [clean, errors]. $editingId is the user
 * being edited (null when creating) so the username check ignores them.
 */
function validate_user_input(array $in, ?int $editingId = null): array
{
    $errors = [];
    $clean = [
        'full_name' => clean_text($in['full_name'] ?? '', 120),
        'username'  => strtolower(clean_text($in['username'] ?? '', 40)),
        'role'      => clean_text($in['role'] ?? '', 30),
        'phone'     => clean_text($in['phone'] ?? '', 20),
    ];

    if (mb_strlen($clean['full_name']) < 3) {
        $errors['full_name'] = 'Enter the full name (at least 3 characters).';
    }
    if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,39}$/', $clean['username'])) {
        $errors['username'] = 'Use 3 to 40 letters, numbers, dots, dashes or underscores.';
    } elseif (db_value('SELECT id FROM users WHERE username = ? AND id <> ?', [$clean['username'], $editingId ?? 0]) !== null) {
        $errors['username'] = 'That username is already taken.';
    }
    if (!array_key_exists($clean['role'], role_labels())) {
        $errors['role'] = 'Choose a role.';
    }
    if ($clean['phone'] !== '') {
        $phone = normalize_phone($clean['phone']);
        if ($phone === null) {
            $errors['phone'] = 'Enter a Kenyan mobile number such as 0712 345 678.';
        } else {
            $clean['phone'] = $phone;
        }
    }
    return [$clean, $errors];
}

/** Create an account with a one-time password the person must change at first sign-in. */
function create_user(array $clean, string $password, int $actorId): int
{
    return db_tx(function () use ($clean, $password, $actorId): int {
        $id = db_insert(
            'INSERT INTO users (full_name, username, password_hash, role_id, phone, must_change_password) VALUES (?, ?, ?, ?, ?, 1)',
            [$clean['full_name'], $clean['username'], hash_password($password), role_id_by_code($clean['role']), $clean['phone'] !== '' ? $clean['phone'] : null]
        );
        audit('create', 'user', $id, 'Account created with role ' . $clean['role'], $actorId);
        return $id;
    });
}

/** Update name, role and phone. Refuses to remove the last active administrator. */
function update_user(int $id, array $clean, int $actorId): ?string
{
    $current = get_user($id);
    if (!$current) {
        return 'User not found.';
    }
    if ($current['role'] === 'admin' && $clean['role'] !== 'admin' && (int) $current['is_active'] && count_active_admins() <= 1) {
        return 'There must be at least one active administrator.';
    }
    db_exec(
        'UPDATE users SET full_name = ?, username = ?, role_id = ?, phone = ? WHERE id = ?',
        [$clean['full_name'], $clean['username'], role_id_by_code($clean['role']), $clean['phone'] !== '' ? $clean['phone'] : null, $id]
    );
    audit('update', 'user', $id, 'Account details updated' . ($current['role'] !== $clean['role'] ? '; role changed to ' . $clean['role'] : ''), $actorId);
    return null;
}

/** Turn an account on or off. Returns an error message or null. */
function set_user_active(int $id, bool $active, int $actorId): ?string
{
    $user = get_user($id);
    if (!$user) {
        return 'User not found.';
    }
    if (!$active) {
        if ($id === $actorId) {
            return 'You cannot deactivate your own account.';
        }
        if ($user['role'] === 'admin' && (int) $user['is_active'] && count_active_admins() <= 1) {
            return 'There must be at least one active administrator.';
        }
    }
    db_exec('UPDATE users SET is_active = ? WHERE id = ?', [$active ? 1 : 0, $id]);
    audit($active ? 'activate' : 'deactivate', 'user', $id, null, $actorId);
    return null;
}

/** Give a user a new one-time password and clear any lock. */
function reset_user_password(int $id, string $newPassword, int $actorId): void
{
    set_user_password($id, $newPassword, true);
    audit('password_reset', 'user', $id, 'Password reset by administrator', $actorId);
}

function unlock_user(int $id, int $actorId): void
{
    db_exec('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?', [$id]);
    audit('unlock', 'user', $id, null, $actorId);
}

/**
 * Audit trail query. $f may contain: user_id, action, entity, from, to (dates).
 * Returns [rows, total].
 */
function search_audit(array $f, int $limit, int $offset): array
{
    $where = ' WHERE 1 = 1';
    $params = [];
    if (!empty($f['user_id'])) {
        $where .= ' AND a.user_id = ?';
        $params[] = (int) $f['user_id'];
    }
    if (!empty($f['action'])) {
        $where .= ' AND a.action = ?';
        $params[] = $f['action'];
    }
    if (!empty($f['entity'])) {
        $where .= ' AND a.entity = ?';
        $params[] = $f['entity'];
    }
    if (!empty($f['from']) && is_valid_date($f['from'])) {
        $where .= ' AND a.created_at >= ?';
        $params[] = $f['from'] . ' 00:00:00';
    }
    if (!empty($f['to']) && is_valid_date($f['to'])) {
        $where .= ' AND a.created_at <= ?';
        $params[] = $f['to'] . ' 23:59:59';
    }
    $total = (int) db_value('SELECT COUNT(*) FROM audit_log a' . $where, $params);
    $rows = db_all(
        'SELECT a.*, u.username, u.full_name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id'
        . $where . ' ORDER BY a.id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
        $params
    );
    return [$rows, $total];
}

/* ---------- A person's own profile and settings ---------- */

/** A readable name for an audit action, for a person's own activity list. */
function audit_action_label(string $action): string
{
    static $known = [
        'login' => 'Signed in', 'logout' => 'Signed out', 'login_failed' => 'A wrong password was entered',
        'password_change' => 'Changed your password', 'password_change_failed' => 'A password change was refused',
        'session_expired' => 'Signed out automatically', 'create' => 'Created a record', 'update' => 'Changed a record',
        'view' => 'Opened a record', 'payment' => 'Recorded a payment', 'dispense' => 'Dispensed medicines',
        'access_denied' => 'A page was refused (no permission)', 'csrf_rejected' => 'A form was refused (wrong security token)',
        'password_reset' => 'Password reset by the administrator', 'submit' => 'Sent a request or message',
    ];
    return $known[$action] ?? ucfirst(str_replace('_', ' ', $action));
}

/** The newest things a person did (and that happened to their account), newest first. */
function user_recent_activity(int $userId, int $limit = 10): array
{
    return db_all(
        'SELECT action, entity, details, ip_address, created_at FROM audit_log WHERE user_id = ? ORDER BY id DESC LIMIT ' . (int) $limit,
        [$userId]
    );
}

/**
 * Let a person change their own mobile number, or clear it (an empty value). Returns ['ok' => bool, 'error' => ?string].
 * The audit entry says that the number changed, not what it is.
 */
function update_own_phone(int $userId, string $raw): array
{
    $raw = clean_text($raw, 20);
    $phone = null;
    if ($raw !== '') {
        $phone = normalize_phone($raw);
        if ($phone === null) {
            return ['ok' => false, 'error' => 'Enter a Kenyan mobile number such as 0712 345 678, or leave this blank to remove it.'];
        }
    }
    db_exec('UPDATE users SET phone = ? WHERE id = ?', [$phone, $userId]);
    audit('update', 'user', $userId, $phone === null ? 'Own phone number removed' : 'Own phone number changed', $userId);
    return ['ok' => true, 'error' => null];
}
