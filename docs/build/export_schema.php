<?php
/**
 * Export the structure of the live database as JSON so the documents (entity relationship
 * diagrams, data dictionary, size estimates) are generated from the real schema.
 *
 *   php docs/build/export_schema.php > docs/build/schema.json
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('APP_ROOT', dirname(__DIR__, 2));
require_once APP_ROOT . '/includes/core.php';

$tables = [];
foreach (db_all("SELECT table_name AS n, engine, table_collation AS coll, table_rows AS approx_rows, avg_row_length AS avg_row, data_length, index_length
                   FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name") as $t) {
    $name = $t['n'];
    $cols = db_all(
        "SELECT column_name AS name, column_type AS type, is_nullable AS nullable, column_key AS ckey, column_default AS dflt, extra,
                character_maximum_length AS maxlen, numeric_precision AS prec, numeric_scale AS scale, generation_expression AS genexpr
           FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position",
        [$name]
    );
    $fks = db_all(
        "SELECT k.column_name AS col, k.referenced_table_name AS ref_table, k.referenced_column_name AS ref_col, r.delete_rule, k.constraint_name AS cname
           FROM information_schema.key_column_usage k
           JOIN information_schema.referential_constraints r ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name AND r.table_name = k.table_name
          WHERE k.table_schema = DATABASE() AND k.table_name = ? AND k.referenced_table_name IS NOT NULL ORDER BY k.constraint_name, k.ordinal_position",
        [$name]
    );
    $idx = [];
    foreach (db_all(
        "SELECT index_name AS name, non_unique, column_name AS col, seq_in_index AS seq FROM information_schema.statistics
          WHERE table_schema = DATABASE() AND table_name = ? ORDER BY index_name, seq_in_index",
        [$name]
    ) as $i) {
        $idx[$i['name']]['name'] = $i['name'];
        $idx[$i['name']]['unique'] = !(int) $i['non_unique'];
        $idx[$i['name']]['columns'][] = $i['col'];
    }
    $checks = [];
    foreach (db_all(
        "SELECT cc.constraint_name AS name, cc.check_clause AS clause FROM information_schema.check_constraints cc
          JOIN information_schema.table_constraints tc ON tc.constraint_schema = cc.constraint_schema AND tc.constraint_name = cc.constraint_name
         WHERE tc.table_schema = DATABASE() AND tc.table_name = ?",
        [$name]
    ) as $c) {
        $checks[] = $c;
    }
    $tables[$name] = [
        'engine' => $t['engine'], 'collation' => $t['coll'], 'columns' => $cols, 'foreign_keys' => $fks,
        'indexes' => array_values($idx), 'checks' => $checks,
    ];
}
echo json_encode(['database' => config('db.name'), 'server' => db_value('SELECT VERSION()'), 'tables' => $tables], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
