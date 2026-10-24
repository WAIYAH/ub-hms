<?php
/**
 * Adds a large volume of SYNTHETIC records to an installed demonstration database so that
 * response times can be measured with realistic table sizes (see tests/apache_check.ps1 -Perf).
 *
 *   php tests/perf_seed.php [--db=NAME] [--patients=20000] [--visits=30000] [--audit=100000]
 *
 * Never run this against a database that holds real data. Every row it writes is invented and
 * written by plain SQL, so it is faster than going through the application and skips its checks;
 * the totals are still made consistent so the data-integrity check on the System health page passes.
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

$patients = max(1, (int) ($opts['patients'] ?? 20000));
$visits   = max(1, (int) ($opts['visits'] ?? 30000));
$audit    = max(0, (int) ($opts['audit'] ?? 100000));
if (max($patients, $visits, $audit) > 100000) {
    fwrite(STDERR, "Each count must be 100,000 or less.\n");
    exit(1);
}

$staff = static function (string $code): int {
    return (int) db_value('SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = ? ORDER BY u.id LIMIT 1', [$code]);
};
$admin = $staff('admin');
$reception = $staff('receptionist');
$doctor = $staff('doctor');
$labTech = $staff('lab');
$cashier = $staff('cashier');
if (!$admin || !$reception || !$doctor || !$labTech || !$cashier) {
    fwrite(STDERR, "Install the demonstration data first: php database/install.php --demo\n");
    exit(1);
}

$t0 = microtime(true);
db_exec('DROP TEMPORARY TABLE IF EXISTS perf_nums');
db_exec('CREATE TEMPORARY TABLE perf_nums (n INT UNSIGNED NOT NULL PRIMARY KEY)');
db_exec('INSERT INTO perf_nums
         SELECT a.d + 10 * b.d + 100 * c.d + 1000 * d.d + 10000 * e.d + 1
           FROM (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) a,
                (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) b,
                (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) c,
                (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) d,
                (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) e');

$firstNames = "'Wanjiru','Otieno','Achieng','Kamau','Chebet','Mwangi','Atieno','Kiprop','Njeri','Barasa'";
$lastNames  = "'Mutua','Karanja','Odhiambo','Wekesa','Kimani','Ndungu','Omondi','Cheruiyot','Waweru','Njoroge'";

db_exec("INSERT INTO patients (op_number, first_name, last_name, sex, date_of_birth, national_id, phone, residence, registered_by, created_at)
         SELECT CONCAT('OP25-', LPAD(n, 5, '0')),
                ELT(1 + n % 10, $firstNames), ELT(1 + (n DIV 10) % 10, $lastNames),
                IF(n % 2 = 0, 'Female', 'Male'),
                DATE_ADD('1950-01-01', INTERVAL (n * 37) % 25000 DAY),
                LPAD(30000000 + n, 8, '0'),
                CONCAT('+2547', LPAD(n % 100000000, 8, '0')),
                ELT(1 + n % 5, 'Kasarani', 'Embakasi', 'Westlands', 'Kibera', 'Ruaka'),
                ?, DATE_ADD('2025-01-02 08:00:00', INTERVAL n % 600 DAY)
           FROM perf_nums WHERE n <= ?", [$reception, $patients]);
$firstPatient = (int) db_value("SELECT MIN(id) FROM patients WHERE op_number LIKE 'OP25-%'");

db_exec("INSERT INTO visits (patient_id, doctor_id, reason, visit_date, status, opened_by, closed_at)
         SELECT ? + (n * 7) % ?, ?, 'Synthetic visit',
                DATE_ADD('2025-01-02 08:00:00', INTERVAL (n % 600) DAY) + INTERVAL (n % 540) MINUTE,
                'Closed', ?, DATE_ADD('2025-01-02 09:00:00', INTERVAL (n % 600) DAY) + INTERVAL (n % 540) MINUTE
           FROM perf_nums WHERE n <= ?", [$firstPatient, $patients, $doctor, $reception, $visits]);
$firstVisit = (int) db_value("SELECT MIN(id) FROM visits WHERE reason = 'Synthetic visit'");
$visitRange = [$firstVisit, $firstVisit + $visits - 1];

db_exec("INSERT INTO consultations (visit_id, doctor_id, service_id, complaint, examination, diagnosis, icd10_code, plan, created_at)
         SELECT v.id, v.doctor_id, 1, 'Synthetic complaint', 'Synthetic examination', 'Unspecified malaria', 'B54', 'Synthetic plan', v.visit_date
           FROM visits v WHERE v.id BETWEEN ? AND ?", $visitRange);
db_exec("INSERT INTO invoices (invoice_no, visit_id, total, amount_paid, status, created_by, created_at)
         SELECT CONCAT('INV-2025-', LPAD(v.id - ? + 1, 6, '0')), v.id, 800.00, 800.00, 'Paid', ?, v.visit_date
           FROM visits v WHERE v.id BETWEEN ? AND ?", [$firstVisit, $cashier, $firstVisit, $firstVisit + $visits - 1]);
db_exec("INSERT INTO invoice_items (invoice_id, source, source_id, description, quantity, unit_price, line_total)
         SELECT i.id, 'Consultation', c.id, 'General consultation', 1, 500.00, 500.00
           FROM invoices i JOIN consultations c ON c.visit_id = i.visit_id WHERE i.visit_id BETWEEN ? AND ?", $visitRange);
db_exec("INSERT INTO invoice_items (invoice_id, source, source_id, description, quantity, unit_price, line_total)
         SELECT i.id, 'Service', i.visit_id, 'Synthetic service', 1, 300.00, 300.00
           FROM invoices i WHERE i.visit_id BETWEEN ? AND ?", $visitRange);
db_exec("INSERT INTO payments (invoice_id, amount, method, reference, received_by, paid_at)
         SELECT i.id, 800.00, IF(i.id % 2 = 0, 'Cash', 'M-Pesa'), IF(i.id % 2 = 0, NULL, CONCAT('SYN', LPAD(i.id % 1000000, 7, '0'))), ?, i.created_at
           FROM invoices i WHERE i.visit_id BETWEEN ? AND ?", array_merge([$cashier], $visitRange));
db_exec("INSERT INTO lab_requests (visit_id, test_id, requested_by, requested_at, status, result, is_abnormal, result_by, result_at)
         SELECT v.id, 1 + v.id % 7, v.doctor_id, v.visit_date, 'Completed', 'Synthetic result', 0, ?, v.visit_date + INTERVAL 40 MINUTE
           FROM visits v WHERE v.id BETWEEN ? AND ? AND v.id % 3 = 0", array_merge([$labTech], $visitRange));
if ($audit > 0) {
    db_exec("INSERT INTO audit_log (user_id, action, entity, entity_id, details, ip_address, created_at)
             SELECT ?, ELT(1 + n % 4, 'view', 'update', 'create', 'login'), 'patient', ?  + n % ?, 'Synthetic audit entry', '127.0.0.1',
                    DATE_ADD('2025-01-02 08:00:00', INTERVAL n * 7 MINUTE)
               FROM perf_nums WHERE n <= ?", [$reception, $firstPatient, $patients, $audit]);
}
db_exec('DROP TEMPORARY TABLE perf_nums');
foreach (['patients', 'visits', 'consultations', 'invoices', 'invoice_items', 'payments', 'lab_requests', 'audit_log'] as $t) {
    echo sprintf("%-16s %s\n", $t, number_format((int) db_value("SELECT COUNT(*) FROM `$t`")));
}
echo sprintf("Done in %.1f seconds.\n", microtime(true) - $t0);
