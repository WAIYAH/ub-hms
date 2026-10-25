<?php
/**
 * Adds a few SYNTHETIC records to a demonstration database so that the pages of the final release can be photographed:
 * a doctor on leave with one booking to move, three refund requests (waiting, approved, rejected) and three data requests.
 *
 *   php docs/build/seed_rights_demo.php        (uses the database named by UBHMS_DB_NAME / UBHMS_DB_PORT)
 *
 * Run it AFTER the other screenshots: an approved refund makes "collected today" negative on the dashboards.
 * Never run it against a database that holds real data.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('APP_ROOT', dirname(__DIR__, 2));
putenv('UBHMS_BCRYPT_COST=4');
require APP_ROOT . '/includes/core.php';
require APP_ROOT . '/database/demo_data.php';

$id = static fn (string $u): int => (int) db_value('SELECT id FROM users WHERE username = ?', [$u]);
$rec = $id('reception1');
$cash = $id('cashier1');
$mgr = $id('manager1');
$doc3 = $id('doctor3');

if (!db_value('SELECT COUNT(*) FROM doctor_schedules')) {
    seed_demo_schedules();
    echo "schedules seeded\n";
}

// a conflict: an appointment for doctor3 on a day that is then recorded as leave
$day = (new DateTimeImmutable('today'))->modify('+4 days');
if ((int) $day->format('N') === 7) {
    $day = $day->modify('+1 day');
}
$p = db_value('SELECT id FROM patients ORDER BY id LIMIT 1 OFFSET 7');
$b = book_appointment(['patient_id' => $p, 'doctor_id' => $doc3, 'date' => $day->format('Y-m-d'), 'start_time' => '10:20', 'reason' => 'Follow-up'], $rec);
echo 'booked: ', json_encode($b), "\n";
$l = add_doctor_leave($doc3, ['start_date' => $day->format('Y-m-d'), 'end_date' => $day->modify('+1 day')->format('Y-m-d'), 'type' => 'Training', 'reason' => 'Course'], $mgr);
echo 'leave: ', json_encode($l), "\n";

// refunds: one waiting, one approved, one rejected
$pays = db_all("SELECT p.id FROM payments p WHERE p.kind = 'Payment' AND p.amount >= 300 ORDER BY p.id LIMIT 6");
$r1 = request_refund((int) $pays[0]['id'], ['amount' => '150', 'reason' => 'The test was not done'], $cash);
$r2 = request_refund((int) $pays[1]['id'], ['amount' => '100', 'reason' => 'Paid twice at the desk', 'refund_reference' => 'REV20261'], $cash);
$r3 = request_refund((int) $pays[2]['id'], ['amount' => '200', 'reason' => 'Patient says the consultation was free'], $cash);
echo 'refund requests: ', json_encode([$r1['ok'], $r2['ok'], $r3['ok']]), "\n";
echo 'approve: ', json_encode(decide_refund($r2['id'], 'approve', 'Checked against the till', $mgr)), "\n";
echo 'reject: ', json_encode(decide_refund($r3['id'], 'reject', 'The receipt shows the consultation was charged', $mgr)), "\n";

// data requests: a copy, an erasure that can go ahead, and one that is blocked
$input = ['requester_name' => 'Test Requester', 'requester_relation' => 'The patient', 'identity_checked' => '1', 'identity_note' => 'National ID card seen', 'reason' => 'Wants a copy for another hospital'];
$clean = db_all("SELECT p.id FROM patients p WHERE NOT EXISTS (SELECT 1 FROM visits v WHERE v.patient_id = p.id AND (v.status = 'Open')) AND NOT EXISTS (SELECT 1 FROM appointments a WHERE a.patient_id = p.id AND a.status = 'Booked') AND NOT EXISTS (SELECT 1 FROM visits v JOIN invoices i ON i.visit_id = v.id WHERE v.patient_id = p.id AND i.total > i.amount_paid) AND p.erased_at IS NULL ORDER BY p.id DESC LIMIT 3");
$blocked = db_value("SELECT v.patient_id FROM visits v WHERE v.status = 'Open' LIMIT 1");
echo 'export: ', json_encode(create_data_request((int) $clean[0]['id'], 'Export', $input, $rec)), "\n";
echo 'erase ok-able: ', json_encode(create_data_request((int) $clean[1]['id'], 'Erasure', ['reason' => 'Does not want the record kept'] + $input, $rec)), "\n";
echo 'erase blocked: ', json_encode(create_data_request((int) $blocked, 'Erasure', ['reason' => 'Moving abroad'] + $input, $rec)), "\n";
echo 'integrity problems: ', count(data_integrity_problems()), "\n";
