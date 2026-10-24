<?php
/**
 * Command-line installer.
 *
 *   php database/install.php [--db=NAME] [--admin-user=admin] [--admin-pass=SECRET] [--demo[=basic]] [--force]
 *
 * Creates the database if needed, loads database/schema.sql, creates the first
 * administrator and (with --demo) loads synthetic demonstration data. --demo=basic loads
 * only the demonstration staff and patients; plain --demo adds two weeks of clinic history,
 * medicines and stock as well.
 * Refuses to run when UB-HMS tables already exist unless --force is given,
 * because the schema script drops every table.
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
if (isset($opts['db'])) {
    putenv('UBHMS_DB_NAME=' . $opts['db']);
}

require_once APP_ROOT . '/includes/core.php';

$dbName = (string) config('db.name');
if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $dbName)) {
    fwrite(STDERR, "Invalid database name.\n");
    exit(1);
}

// Connect without a database first so it can be created.
$c = config('db');
$server = new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $c['host'], $c['port']), $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$exists = (int) db_value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = 'users'", [$dbName]);
if ($exists && !isset($opts['force'])) {
    fwrite(STDERR, "Database `$dbName` already contains UB-HMS tables. Installing would DELETE all of its data.\nRun again with --force only if you are sure.\n");
    exit(1);
}

// Load the schema: drop comment lines, then run each statement.
$sql = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
$sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
$statements = 0;
foreach (preg_split('/;\s*\n/', $sql) ?: [] as $stmt) {
    $stmt = trim($stmt);
    if ($stmt !== '') {
        db()->exec($stmt);
        $statements++;
    }
}
echo "Schema loaded ($statements statements) into `$dbName`.\n";

// First administrator
$adminUser = (string) ($opts['admin-user'] ?? 'admin');
$adminPass = isset($opts['admin-pass']) && is_string($opts['admin-pass']) ? $opts['admin-pass'] : generate_temp_password();
$problems = password_problems($adminPass, $adminUser);
if ($problems) {
    fwrite(STDERR, 'Administrator password rejected: ' . implode(' ', $problems) . "\n");
    exit(1);
}
$adminId = db_insert(
    'INSERT INTO users (full_name, username, password_hash, role_id, must_change_password) VALUES (?, ?, ?, ?, 1)',
    ['System Administrator', strtolower($adminUser), hash_password($adminPass), role_id_by_code('admin')]
);
audit('create', 'user', $adminId, 'First administrator created during installation', $adminId);
echo "Administrator created. Username: $adminUser   One-time password: $adminPass\n";
echo "(You will be asked to choose a new password at first sign-in.)\n";

if (isset($opts['demo'])) {
    require_once APP_ROOT . '/database/demo_data.php';
    $summary = seed_demo_data(($opts['demo'] ?? true) !== 'basic');
    echo "Synthetic demonstration data loaded:\n";
    foreach ($summary as $k => $v) {
        echo sprintf("  %-28s %s\n", $k, $v);
    }
    echo 'Demo accounts share the password: ' . DEMO_PASSWORD . "\n";
}
echo "Done.\n";
