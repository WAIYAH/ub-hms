<?php
/**
 * System health checks for the administrator: database, disk space, folders,
 * backups, recent errors and security-related counts. Nothing here exposes
 * patient information.
 */
declare(strict_types=1);

/** Returns a list of checks: [name, status ('ok'|'warn'|'fail'), detail]. */
function system_health(): array
{
    $checks = [];
    $add = static function (string $name, string $status, string $detail) use (&$checks): void {
        $checks[] = ['name' => $name, 'status' => $status, 'detail' => $detail];
    };

    try {
        $t0 = microtime(true);
        $version = (string) db_value('SELECT VERSION()');
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $add('Database connection', $ms > 500 ? 'warn' : 'ok', "Connected to $version in $ms ms");
        $tables = (int) db_value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()');
        $add('Database tables', $tables >= 23 ? 'ok' : 'fail', "$tables tables present (23 expected)");
        $size = (float) db_value('SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE()');
        $add('Database size', 'ok', number_format($size / 1048576, 1) . ' MB');
    } catch (Throwable $e) {
        $add('Database connection', 'fail', 'The database could not be reached.');
    }

    $add('PHP version', version_compare(PHP_VERSION, '8.1.0', '>=') ? 'ok' : 'warn', PHP_VERSION);

    $dir = APP_ROOT;
    $free = @disk_free_space($dir);
    $totalSpace = @disk_total_space($dir);
    if ($free !== false && $totalSpace) {
        $pct = $free / $totalSpace * 100;
        $add('Free disk space', $pct < 5 ? 'fail' : ($pct < 15 ? 'warn' : 'ok'), number_format($free / 1073741824, 1) . ' GB free (' . round($pct) . '%)');
    }

    foreach (['logs' => config('paths.logs'), 'backups' => config('paths.backups')] as $label => $path) {
        $add("Folder: $label", is_dir($path) && is_writable($path) ? 'ok' : 'fail', is_dir($path) ? (is_writable($path) ? 'Writable' : 'Not writable') : 'Missing');
    }

    $backups = glob(config('paths.backups') . '/*.sql*') ?: [];
    if ($backups) {
        usort($backups, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $ageHours = (time() - filemtime($backups[0])) / 3600;
        $add('Latest backup', $ageHours > 48 ? 'fail' : ($ageHours > 26 ? 'warn' : 'ok'),
            basename($backups[0]) . ', ' . ($ageHours < 1 ? 'less than an hour' : round($ageHours) . ' hours') . ' ago (' . count($backups) . ' kept)');
    } else {
        $add('Latest backup', 'fail', 'No backup file found. Run scripts/backup.ps1 and schedule it every day.');
    }

    try {
        $problems = data_integrity_problems();
        $add('Data integrity (invoices, payments, stock)', $problems ? 'fail' : 'ok', $problems ? count($problems) . ' problem(s): ' . implode('; ', array_slice($problems, 0, 3)) : 'All records reconcile');
    } catch (Throwable $e) {
        $add('Data integrity (invoices, payments, stock)', 'warn', 'The check could not be completed.');
    }

    $failed = (int) db_value("SELECT COUNT(*) FROM audit_log WHERE action = 'login_failed' AND created_at > (NOW() - INTERVAL 24 HOUR)");
    $add('Failed sign-ins in 24 hours', $failed > 50 ? 'warn' : 'ok', (string) $failed);
    $locked = (int) db_value('SELECT COUNT(*) FROM users WHERE locked_until > NOW()');
    $add('Locked accounts', $locked > 0 ? 'warn' : 'ok', (string) $locked);
    $denied = (int) db_value("SELECT COUNT(*) FROM audit_log WHERE action IN ('access_denied','csrf_rejected') AND created_at > (NOW() - INTERVAL 24 HOUR)");
    $add('Refused requests in 24 hours', $denied > 20 ? 'warn' : 'ok', (string) $denied);

    $demo = (int) db_value("SELECT COUNT(*) FROM users WHERE username IN ('manager1','reception1','nurse1','doctor1','lab1','pharmacist1','cashier1')");
    $add('Demonstration accounts', $demo > 0 ? 'warn' : 'ok', $demo > 0 ? "$demo demonstration accounts exist. Delete or deactivate them before real use." : 'None');

    $log = config('paths.logs') . '/app.log';
    $errors = 0;
    if (is_file($log) && filesize($log) < 5242880) {
        $errors = preg_match_all('/ ERROR /', (string) file_get_contents($log));
    }
    $add('Errors in the application log', $errors > 0 ? 'warn' : 'ok', (string) $errors);

    $add('HTTPS', is_https() ? 'ok' : 'warn', is_https() ? 'This page was served over HTTPS' : 'This page was served over plain HTTP. Use HTTPS where the network is not private.');
    return $checks;
}

/**
 * Cross-checks that the records agree with each other. Returns a list of
 * plain-English problems; an empty list means everything reconciles.
 */
function data_integrity_problems(): array
{
    $problems = [];
    $add = static function (string $message, array $rows) use (&$problems): void {
        foreach ($rows as $r) {
            $problems[] = $message . ' (' . $r . ')';
        }
    };

    $add('Invoice total differs from the sum of its lines', array_column(db_all(
        'SELECT i.invoice_no FROM invoices i
          WHERE ROUND(i.total, 2) <> ROUND((SELECT COALESCE(SUM(l.line_total), 0) FROM invoice_items l WHERE l.invoice_id = i.id), 2)'
    ), 'invoice_no'));

    $add('Amount paid differs from the sum of payments', array_column(db_all(
        'SELECT i.invoice_no FROM invoices i
          WHERE ROUND(i.amount_paid, 2) <> ROUND((SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.invoice_id = i.id), 2)'
    ), 'invoice_no'));

    $add('Invoice status does not match its payments', array_column(db_all(
        "SELECT i.invoice_no FROM invoices i WHERE i.status <>
            CASE WHEN i.amount_paid <= 0 THEN 'Unpaid' WHEN i.amount_paid >= i.total THEN 'Paid' ELSE 'Partially paid' END"
    ), 'invoice_no'));

    $add('Invoice is paid beyond its total', array_column(db_all('SELECT invoice_no FROM invoices WHERE amount_paid > total'), 'invoice_no'));

    $add('Batch balance differs from its stock ledger', array_column(db_all(
        'SELECT b.batch_no FROM medicine_batches b
          WHERE b.quantity <> (SELECT COALESCE(SUM(s.quantity), 0) FROM stock_movements s WHERE s.batch_id = b.id)'
    ), 'batch_no'));

    $add('Medicine dispensed more than prescribed', array_column(db_all('SELECT id FROM prescription_items WHERE dispensed_qty > quantity'), 'id'));

    $add('Prescription status does not match what was dispensed', array_column(db_all(
        "SELECT rx.id FROM prescriptions rx WHERE rx.status <>
            CASE WHEN (SELECT SUM(i.quantity - i.dispensed_qty) FROM prescription_items i WHERE i.prescription_id = rx.id) = 0 THEN 'Dispensed'
                 WHEN (SELECT SUM(i.dispensed_qty) FROM prescription_items i WHERE i.prescription_id = rx.id) > 0 THEN 'Partially dispensed'
                 ELSE 'Pending' END"
    ), 'id'));

    $add('Patient has more than one open visit', array_column(db_all("SELECT patient_id FROM visits WHERE status = 'Open' GROUP BY patient_id HAVING COUNT(*) > 1"), 'patient_id'));

    $add('Completed laboratory request has no result', array_column(db_all("SELECT id FROM lab_requests WHERE status = 'Completed' AND (result IS NULL OR result = '')"), 'id'));

    return $problems;
}

/** Row counts for the main tables (for capacity planning). */
function table_counts(): array
{
    $out = [];
    foreach (['patients', 'appointments', 'visits', 'consultations', 'lab_requests', 'prescriptions', 'stock_movements', 'invoices', 'payments', 'audit_log'] as $t) {
        $out[$t] = (int) db_value("SELECT COUNT(*) FROM `$t`");
    }
    return $out;
}
