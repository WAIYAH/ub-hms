<?php
/**
 * Minimal test toolkit for UB-HMS: registration, assertions, fixtures and a
 * clean database before every test. No external libraries are needed.
 */
declare(strict_types=1);

final class TestFailure extends Exception
{
}

/** @var array<int, array{id:string,suite:string,name:string,fn:callable}> */
$GLOBALS['__tests'] = [];
$GLOBALS['__suite'] = 'General';

function suite(string $name): void
{
    $GLOBALS['__suite'] = $name;
}

/** Register a test. $id is a stable code such as UT-PAT-001 used in the test plan. */
function test(string $id, string $name, callable $fn): void
{
    $GLOBALS['__tests'][] = ['id' => $id, 'suite' => $GLOBALS['__suite'], 'name' => $name, 'fn' => $fn];
}

/* ---------- Assertions ---------- */

function assert_true($value, string $msg = 'Expected true'): void
{
    if ($value !== true) {
        throw new TestFailure($msg . ' (got ' . var_export($value, true) . ')');
    }
}

function assert_false($value, string $msg = 'Expected false'): void
{
    if ($value !== false) {
        throw new TestFailure($msg . ' (got ' . var_export($value, true) . ')');
    }
}

function assert_eq($expected, $actual, string $msg = ''): void
{
    if ($expected != $actual || (is_array($expected) !== is_array($actual))) {
        throw new TestFailure(($msg !== '' ? $msg . ': ' : '') . 'expected ' . var_export($expected, true) . ' but got ' . var_export($actual, true));
    }
}

function assert_same($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new TestFailure(($msg !== '' ? $msg . ': ' : '') . 'expected ' . var_export($expected, true) . ' but got ' . var_export($actual, true));
    }
}

function assert_null($value, string $msg = 'Expected null'): void
{
    if ($value !== null) {
        throw new TestFailure($msg . ' (got ' . var_export($value, true) . ')');
    }
}

function assert_not_null($value, string $msg = 'Expected a value'): void
{
    if ($value === null) {
        throw new TestFailure($msg);
    }
}

function assert_contains(string $needle, string $haystack, string $msg = ''): void
{
    if (strpos($haystack, $needle) === false) {
        throw new TestFailure(($msg !== '' ? $msg . ': ' : '') . "'$needle' not found in '" . mb_substr($haystack, 0, 200) . "'");
    }
}

function assert_not_contains(string $needle, string $haystack, string $msg = ''): void
{
    if (strpos($haystack, $needle) !== false) {
        throw new TestFailure(($msg !== '' ? $msg . ': ' : '') . "'$needle' should not appear");
    }
}

function assert_count(int $expected, $countable, string $msg = ''): void
{
    if (count($countable) !== $expected) {
        throw new TestFailure(($msg !== '' ? $msg . ': ' : '') . "expected $expected items but got " . count($countable));
    }
}

function assert_has_key($key, array $arr, string $msg = ''): void
{
    if (!array_key_exists($key, $arr)) {
        throw new TestFailure(($msg !== '' ? $msg . ': ' : '') . "key '$key' missing");
    }
}

function assert_throws(callable $fn, string $msg = 'Expected an exception'): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof TestFailure) {
            throw $e;
        }
        return $e;
    }
    throw new TestFailure($msg);
}

/* ---------- Database fixtures ---------- */

/** Run schema.sql on the test database. Called once at start. */
function load_schema(): void
{
    $sql = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    foreach (preg_split('/;\s*\n/', $sql) ?: [] as $stmt) {
        $stmt = trim($stmt);
        if ($stmt !== '') {
            db()->exec($stmt);
        }
    }
    // Remember the default price lists so reset_state() can restore them.
    foreach (['services', 'lab_tests'] as $table) {
        if (preg_match('/INSERT INTO ' . $table . '\b.*?\);/s', $sql, $m)) {
            $GLOBALS['__catalogue_sql_' . $table] = $m[0];
        }
    }
}

/** Empty every data table, restore the default price lists and clear the fake session and clock. */
function reset_state(): void
{
    $pdo = db();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['data_requests', 'refund_requests', 'doctor_leave', 'doctor_schedules', 'public_requests', 'audit_log', 'payments', 'invoice_items', 'invoices', 'stock_movements', 'prescription_items', 'prescriptions',
              'medicine_batches', 'medicines', 'lab_requests', 'visit_services', 'consultations', 'triage', 'visits',
              'appointments', 'patients', 'op_sequences', 'users', 'services', 'lab_tests'] as $t) {
        // DELETE is far faster than TRUNCATE (which rebuilds the table) for the small tables used in tests.
        $pdo->exec("DELETE FROM `$t`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    foreach (['services', 'lab_tests'] as $t) {
        if (!empty($GLOBALS['__catalogue_sql_' . $t])) {
            $pdo->exec($GLOBALS['__catalogue_sql_' . $t]);
        }
    }
    $_SESSION = [];
    unset($GLOBALS['__now']);
}

/** Freeze the application clock, e.g. freeze_time('2026-11-02 10:00:00'). */
function freeze_time(string $when): void
{
    $GLOBALS['__now'] = $when;
}

/* ---------- Factories ---------- */

/** Ids change between tests (the price lists are re-inserted), so always look them up by name. */
function service_id(string $name): int
{
    return (int) db_value('SELECT id FROM services WHERE name = ?', [$name]);
}

function lab_test_id(string $name): int
{
    return (int) db_value('SELECT id FROM lab_tests WHERE name = ?', [$name]);
}

const TEST_PASSWORD = 'Test#Passw0rd!';

function make_user(string $role, ?string $username = null, array $o = []): int
{
    static $n = 0;
    $n++;
    $username = $username ?? $role . $n;
    return db_insert(
        'INSERT INTO users (full_name, username, password_hash, role_id, phone, is_active, must_change_password) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$o['full_name'] ?? ucfirst($role) . " Tester $n", $username, hash_password(TEST_PASSWORD),
         role_id_by_code($role), $o['phone'] ?? null, $o['is_active'] ?? 1, $o['must_change'] ?? 0]
    );
}

/** Pretend the given user is signed in (sets the session the way a login would). */
function sign_in_as(int $userId): array
{
    $u = get_user($userId);
    $_SESSION['user'] = ['id' => (int) $u['id'], 'username' => $u['username'], 'full_name' => $u['full_name'], 'role' => $u['role'], 'must_change_password' => (int) $u['must_change_password']];
    return $_SESSION['user'];
}

function make_patient(array $o = [], ?int $userId = null): int
{
    static $n = 0;
    $n++;
    $userId = $userId ?? (int) (db_value("SELECT id FROM users WHERE role_id = 3 LIMIT 1") ?: make_user('receptionist'));
    $r = register_patient($o + [
        'first_name' => 'Test', 'last_name' => 'Patient' . str_repeat(chr(65 + $n % 26), 1 + intdiv($n, 26)), 'sex' => 'Female',
        'date_of_birth' => '1990-05-15', 'national_id' => (string) (88000000 + $n), 'phone' => '0700 100 ' . str_pad((string) $n, 3, '0', STR_PAD_LEFT),
    ], $userId, true);
    if (!$r['ok']) {
        throw new RuntimeException('make_patient failed: ' . json_encode($r['errors']));
    }
    return (int) $r['id'];
}
