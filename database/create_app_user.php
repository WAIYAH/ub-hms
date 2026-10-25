<?php
/**
 * Create the least-privilege database account the web application should use.
 *
 *   php database/create_app_user.php --password=SECRET [--user=ubhms_app] [--host=localhost] [--db=NAME]
 *
 * Run it as a database administrator (the same connection settings the installer uses).
 * The new account:
 *   - can read and write the data tables it needs,
 *   - can only ADD rows to the audit trail, the payments table and the stock ledger
 *     (it cannot change or delete them, so a compromised web page cannot rewrite history),
 *   - cannot change the structure of the database, create users or read other databases.
 *
 * Afterwards put the account in config/config.local.php, for example:
 *   <?php return ['db' => ['user' => 'ubhms_app', 'pass' => 'SECRET']];
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('APP_ROOT', dirname(__DIR__));
$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}
if (isset($opts['db']) && is_string($opts['db'])) {
    putenv('UBHMS_DB_NAME=' . $opts['db']);
}
require_once APP_ROOT . '/includes/core.php';

$user = (string) ($opts['user'] ?? 'ubhms_app');
$host = (string) ($opts['host'] ?? 'localhost');
$pass = isset($opts['password']) && is_string($opts['password']) ? $opts['password'] : '';
$db = (string) config('db.name');
if (!preg_match('/^[A-Za-z0-9_]{1,32}$/', $user) || !preg_match('/^[A-Za-z0-9_.%:-]{1,60}$/', $host) || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $db)) {
    fwrite(STDERR, "Invalid user, host or database name.\n");
    exit(1);
}
if (strlen($pass) < 12) {
    fwrite(STDERR, "Choose a password of at least 12 characters with --password=...\n");
    exit(1);
}

/** Tables the application only ever adds to. */
$appendOnly = ['audit_log', 'payments', 'stock_movements'];
/** Tables the application removes rows from (an invoice's lines; a doctor's weekly hours are replaced, and leave can be removed). */
$mayDelete = ['invoice_items', 'doctor_schedules', 'doctor_leave'];

$pdo = db();
$q = static fn (string $s): string => $pdo->quote($s);
$pdo->exec("CREATE USER IF NOT EXISTS {$q($user)}@{$q($host)} IDENTIFIED BY {$q($pass)}");
$pdo->exec("ALTER USER {$q($user)}@{$q($host)} IDENTIFIED BY {$q($pass)}");
$pdo->exec("REVOKE ALL PRIVILEGES, GRANT OPTION FROM {$q($user)}@{$q($host)}");

$tables = array_column(db_all("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE'", [$db]), 't');
foreach ($tables as $t) {
    $privileges = in_array($t, $appendOnly, true) ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE' . (in_array($t, $mayDelete, true) ? ', DELETE' : '');
    $pdo->exec("GRANT $privileges ON `$db`.`$t` TO {$q($user)}@{$q($host)}");
}
$pdo->exec('FLUSH PRIVILEGES');

echo "Account '$user'@'$host' created for database `$db` with " . count($tables) . " table grants.\n";
echo 'Append-only tables: ' . implode(', ', $appendOnly) . "\n";
echo "Next: set ['db' => ['user' => '$user', 'pass' => '...']] in config/config.local.php.\n";
