<?php
/**
 * Database access. Every query in UB-HMS goes through these helpers, which
 * use PDO prepared statements, so user input is never joined into SQL text.
 */
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config('db');
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']);
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
        // Keep database time (NOW()) in step with the hospital's local time.
        $pdo->exec("SET time_zone = '+03:00'");
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
    }
    return $pdo;
}

/** Run a statement and return it (for the helpers below). */
function db_run(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** All rows as a list of associative arrays. */
function db_all(string $sql, array $params = []): array
{
    return db_run($sql, $params)->fetchAll();
}

/** First row, or null when there is none. */
function db_one(string $sql, array $params = []): ?array
{
    $row = db_run($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** First column of the first row, or null. */
function db_value(string $sql, array $params = [])
{
    $v = db_run($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/** Run INSERT/UPDATE/DELETE and return the number of affected rows. */
function db_exec(string $sql, array $params = []): int
{
    return db_run($sql, $params)->rowCount();
}

/** Run INSERT and return the new row id. */
function db_insert(string $sql, array $params = []): int
{
    db_run($sql, $params);
    return (int) db()->lastInsertId();
}

/**
 * Run $fn inside a transaction. Any exception rolls everything back and is
 * re-thrown. Calls can be nested; only the outermost one commits.
 */
function db_tx(callable $fn)
{
    static $depth = 0;
    $pdo = db();
    if ($depth === 0) {
        $pdo->beginTransaction();
    }
    $depth++;
    try {
        $result = $fn();
        $depth--;
        if ($depth === 0) {
            $pdo->commit();
        }
        return $result;
    } catch (Throwable $e) {
        $depth--;
        if ($depth === 0 && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Escape % _ \ so user text can be used safely inside a LIKE pattern. */
function like_escape(string $text): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $text);
}
