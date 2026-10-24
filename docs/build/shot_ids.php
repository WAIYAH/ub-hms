<?php
/** Print, as JSON, the ids of sample records that the screenshot script should open. */
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__, 2));
require_once APP_ROOT . '/includes/core.php';

$busy = db_one('SELECT p.id, p.last_name, p.first_name, COUNT(v.id) AS n FROM patients p JOIN visits v ON v.patient_id = p.id JOIN consultations c ON c.visit_id = v.id
                 GROUP BY p.id ORDER BY n DESC, p.id LIMIT 1');
$stage = static function (string $s): ?int {
    foreach (visit_queue('') as $v) {
        if ($v['stage'] === $s) {
            return (int) $v['id'];
        }
    }
    return null;
};
$ordersVisit = db_one("SELECT v.id FROM visits v WHERE v.status = 'Open' AND EXISTS (SELECT 1 FROM consultations c WHERE c.visit_id = v.id)
                         AND EXISTS (SELECT 1 FROM lab_requests r WHERE r.visit_id = v.id AND r.status = 'Requested') AND EXISTS (SELECT 1 FROM prescriptions p WHERE p.visit_id = v.id) ORDER BY v.id LIMIT 1");
$resultVisit = db_one("SELECT v.id FROM visits v WHERE v.status = 'Open' AND EXISTS (SELECT 1 FROM lab_requests r WHERE r.visit_id = v.id AND r.status = 'Completed') ORDER BY v.id LIMIT 1");
$out = [
    'patient_id' => (int) $busy['id'], 'patient_last' => $busy['last_name'], 'patient_first' => $busy['first_name'],
    'triage_visit' => $stage('Waiting for triage'), 'consult_visit' => $stage('Waiting for doctor'), 'with_doctor_visit' => $stage('With doctor'),
    'orders_visit' => $ordersVisit ? (int) $ordersVisit['id'] : null, 'result_visit' => $resultVisit ? (int) $resultVisit['id'] : null,
    'lab_request' => (int) db_value("SELECT id FROM lab_requests WHERE status = 'Requested' ORDER BY id LIMIT 1"),
    'rx' => (int) db_value("SELECT id FROM prescriptions WHERE status = 'Pending' ORDER BY id LIMIT 1"),
    'invoice_unpaid' => (int) db_value("SELECT id FROM invoices WHERE status = 'Unpaid' ORDER BY id DESC LIMIT 1"),
    'invoice_part' => (int) db_value("SELECT id FROM invoices WHERE status = 'Partially paid' ORDER BY id DESC LIMIT 1"),
    'invoice_paid' => (int) db_value("SELECT id FROM invoices WHERE status = 'Paid' ORDER BY id DESC LIMIT 1"),
    'payment' => (int) db_value("SELECT id FROM payments WHERE method = 'M-Pesa' ORDER BY id DESC LIMIT 1"),
    'user_edit' => (int) db_value("SELECT id FROM users WHERE username = 'nurse2'"),
    'medicine_edit' => (int) db_value("SELECT id FROM medicines WHERE name = 'Ciprofloxacin'"),
    'doctor1' => (int) db_value("SELECT id FROM users WHERE username = 'doctor1'"),
    'today' => date('Y-m-d'), 'range_from' => date('Y-m-d', strtotime('-14 days')),
];
echo json_encode($out, JSON_PRETTY_PRINT), "\n";
