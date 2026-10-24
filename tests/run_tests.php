<?php
/**
 * UB-HMS unit and module test runner.
 *
 *   php tests/run_tests.php [--filter=text] [--quiet]
 *
 * Tests run against a separate database (uhai_bora_hms_test by default), which
 * is rebuilt from database/schema.sql at the start of every run. The real
 * database is never touched. Results are also written to
 * tests/output/unit-results.json for the test report.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('APP_ROOT', dirname(__DIR__));
putenv('UBHMS_DB_NAME=' . (getenv('UBHMS_TEST_DB') ?: 'uhai_bora_hms_test'));
putenv('UBHMS_BCRYPT_COST=4'); // fast hashing; production uses the cost in config/config.php

require_once APP_ROOT . '/includes/core.php';
require_once __DIR__ . '/lib.php';

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}

if (config('db.name') === 'uhai_bora_hms') {
    fwrite(STDERR, "Refusing to run: tests must not use the live database.\n");
    exit(2);
}

$pdo = new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4', config('db.host'), config('db.port')), config('db.user'), config('db.pass'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE IF NOT EXISTS `' . config('db.name') . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

load_schema();

foreach (glob(__DIR__ . '/unit/*_test.php') ?: [] as $file) {
    require $file;
}

$filter = isset($opts['filter']) && is_string($opts['filter']) ? strtolower($opts['filter']) : '';
$results = [];
$pass = $fail = 0;
$currentSuite = '';
$started = microtime(true);

foreach ($GLOBALS['__tests'] as $t) {
    if ($filter !== '' && strpos(strtolower($t['id'] . ' ' . $t['suite'] . ' ' . $t['name']), $filter) === false) {
        continue;
    }
    if (!isset($opts['quiet']) && $t['suite'] !== $currentSuite) {
        $currentSuite = $t['suite'];
        echo "\n== {$t['suite']}\n";
    }
    reset_state();
    $t0 = microtime(true);
    try {
        ($t['fn'])();
        $status = 'PASS';
        $message = '';
        $pass++;
    } catch (Throwable $e) {
        $status = 'FAIL';
        $message = ($e instanceof TestFailure ? '' : get_class($e) . ': ') . $e->getMessage()
            . ($e instanceof TestFailure ? '' : ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        $fail++;
    }
    $ms = (int) round((microtime(true) - $t0) * 1000);
    $results[] = ['id' => $t['id'], 'suite' => $t['suite'], 'name' => $t['name'], 'status' => $status, 'message' => $message, 'ms' => $ms];
    if (!isset($opts['quiet']) || $status === 'FAIL') {
        echo sprintf("  %s  %-11s %s%s\n", $status, $t['id'], $t['name'], $message !== '' ? "\n        -> $message" : '');
    }
}

reset_state();
$total = $pass + $fail;
$seconds = round(microtime(true) - $started, 2);
echo "\n----------------------------------------\n";
echo "Tests run: $total   Passed: $pass   Failed: $fail   Time: {$seconds}s\n";

@mkdir(__DIR__ . '/output', 0777, true);
file_put_contents(__DIR__ . '/output/unit-results.json', json_encode([
    'run_at' => date('Y-m-d H:i:s'), 'php' => PHP_VERSION, 'database' => config('db.name'),
    'total' => $total, 'passed' => $pass, 'failed' => $fail, 'seconds' => $seconds, 'results' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

exit($fail > 0 ? 1 : 0);
