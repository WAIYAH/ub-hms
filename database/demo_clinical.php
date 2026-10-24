<?php
/**
 * Synthetic demonstration data, part 2: medicines and stock, two weeks of
 * clinic history, and today's live queues. Everything is made up. It is built
 * through the same service functions the web pages use, then moved to past
 * dates so that lists and reports look like real history.
 */
declare(strict_types=1);

/* ===========================================================================
 * Pharmacy: medicines and batches
 * ======================================================================== */

function demo_med_id(string $name): int
{
    static $ids = [];
    return $ids[$name] ??= (int) db_value('SELECT id FROM medicines WHERE name = ? ORDER BY id LIMIT 1', [$name]);
}

function seed_demo_pharmacy(): array
{
    $ph = demo_user_id('pharmacist1');
    // name, strength, form, unit, price, reorder level, batches [batch no, days to expiry, quantity]
    $list = [
        ['Paracetamol', '500 mg', 'Tablet', 'tablet', '5.00', 200, [['PAR-2601', 300, 1500]]],
        ['Amoxicillin', '500 mg', 'Capsule', 'capsule', '15.00', 100, [['AMX-2509', 45, 300], ['AMX-2612', 400, 400]]],
        ['Artemether/Lumefantrine', '20/120 mg', 'Tablet', 'tablet', '25.00', 120, [['AL-2604', 200, 600]]],
        ['Oral rehydration salts', '', 'Sachet', 'sachet', '40.00', 50, [['ORS-2603', 365, 70]]],
        ['Metronidazole', '400 mg', 'Tablet', 'tablet', '8.00', 100, [['MTZ-2605', 250, 700]]],
        ['Ibuprofen', '400 mg', 'Tablet', 'tablet', '6.00', 100, [['IBU-2606', 500, 800]]],
        ['Cetirizine', '10 mg', 'Tablet', 'tablet', '7.00', 60, [['CET-2607', 350, 400]]],
        ['Salbutamol', '100 mcg', 'Inhaler', 'inhaler', '450.00', 10, []],
        ['Amlodipine', '5 mg', 'Tablet', 'tablet', '12.00', 100, [['AML-2608', 600, 700]]],
        ['Metformin', '500 mg', 'Tablet', 'tablet', '6.00', 150, [['MET-2609', 500, 900]]],
        ['Omeprazole', '20 mg', 'Capsule', 'capsule', '14.00', 80, [['OMP-2510', 60, 300]]],
        ['Ciprofloxacin', '500 mg', 'Tablet', 'tablet', '18.00', 60, [['CIP-2611', 300, 80]]],
        ['Zinc sulfate', '20 mg', 'Dispersible tablet', 'tablet', '10.00', 100, [['ZNC-2612', 400, 600]]],
        ['Diclofenac', '50 mg', 'Tablet', 'tablet', '9.00', 80, [['DIC-2613', 420, 700]]],
    ];
    $batches = 0;
    foreach ($list as [$name, $strength, $form, $unit, $price, $reorder, $bs]) {
        [$c] = validate_medicine_input(['name' => $name, 'strength' => $strength, 'form' => $form, 'unit' => $unit, 'unit_price' => $price, 'reorder_level' => (string) $reorder, 'is_active' => '1']);
        $id = save_medicine(null, $c, $ph);
        foreach ($bs as [$batchNo, $days, $qty]) {
            receive_batch(['medicine_id' => $id, 'batch_no' => $batchNo, 'expiry_date' => (new DateTimeImmutable('today'))->modify("+$days days")->format('Y-m-d'), 'quantity' => (string) $qty], $ph);
            $batches++;
        }
    }
    // Stock arrived a month ago.
    db_exec('UPDATE medicine_batches SET received_at = (NOW() - INTERVAL 30 DAY)');
    db_exec("UPDATE stock_movements SET created_at = (NOW() - INTERVAL 30 DAY) WHERE type = 'Receive'");
    return ['medicines' => count($list), 'stock batches received' => $batches];
}

/* ===========================================================================
 * Clinical journeys
 * ======================================================================== */

/** key => [complaint, diagnosis, icd10, consultation type, tests, medicines, procedure, adults only] */
function demo_scenarios(): array
{
    return [
        'malaria' => ['Fever, chills and headache for three days', 'Malaria', 'B54', 'General consultation',
            ['Malaria test (BS/MPS)' => ['Malaria parasites seen (+)', true]],
            ['Artemether/Lumefantrine' => [24, '4 tablets', 'twice a day', '3 days'], 'Paracetamol' => [18, '1 tablet', 'three times a day', '6 days']], null, false],
        'urti' => ['Cough, sore throat and runny nose for two days', 'Upper respiratory tract infection', 'J06.9', 'General consultation',
            [], ['Paracetamol' => [18, '1 tablet', 'three times a day', '6 days'], 'Cetirizine' => [10, '1 tablet', 'once a day at night', '10 days']], null, false],
        'uti' => ['Pain and burning on passing urine', 'Urinary tract infection', 'N39.0', 'General consultation',
            ['Urinalysis' => ['Pus cells +++, nitrites positive', true]], ['Ciprofloxacin' => [14, '1 tablet', 'twice a day', '7 days']], null, true],
        'gastro' => ['Diarrhoea and vomiting since yesterday', 'Acute gastroenteritis', 'A09', 'General consultation',
            [], ['Oral rehydration salts' => [6, '1 sachet in 1 litre of water', 'after each loose stool', '3 days'], 'Zinc sulfate' => [10, '1 tablet', 'once a day', '10 days']], null, false],
        'htn' => ['Review of blood pressure', 'Essential hypertension', 'I10', 'Follow-up consultation',
            ['Random blood sugar' => ['6.1 mmol/L', false]], ['Amlodipine' => [30, '1 tablet', 'once a day', '30 days']], null, true],
        'wound' => ['Cut on the left hand while at work', 'Open wound of the hand', 'S61.9', 'General consultation',
            [], ['Amoxicillin' => [15, '1 capsule', 'three times a day', '5 days'], 'Ibuprofen' => [15, '1 tablet', 'three times a day', '5 days']], ['Wound dressing', 1], false],
        'back' => ['Lower back pain after lifting a heavy load', 'Low back pain', 'M54.5', 'General consultation',
            [], ['Diclofenac' => [20, '1 tablet', 'twice a day', '10 days'], 'Omeprazole' => [14, '1 capsule', 'once a day', '14 days']], null, true],
        'fever' => ['Fever and abdominal pain for a week', 'Fever, cause being investigated', 'R50.9', 'General consultation',
            ['Widal test' => ['Titre 1:160, significant', true], 'Full haemogram' => ['Hb 11.2 g/dL, WBC 9.4', false]], ['Paracetamol' => [15, '1 tablet', 'three times a day', '5 days']], null, false],
        'diabetes' => ['Review of diabetes, tired and thirsty', 'Type 2 diabetes mellitus', 'E11.9', 'Follow-up consultation',
            ['Random blood sugar' => ['11.8 mmol/L', true]], ['Metformin' => [60, '1 tablet', 'twice a day', '30 days']], null, true],
    ];
}

/**
 * Run one patient through reception, triage, doctor, laboratory and pharmacy.
 * $stop says how far to go (used for today's live queues): checkin, triage, consult, orders, lab_done or closed.
 * Returns the visit id.
 */
function demo_journey(array $patient, string $doctorUser, string $scenario, ?int $apptId, string $stop = 'closed', bool $dispense = true): int
{
    [$complaint, $diagnosis, $icd, $type, $tests, $meds, $procedure] = demo_scenarios()[$scenario];
    $rec = demo_user_id('reception1');
    $nurse = demo_user_id(mt_rand(0, 1) ? 'nurse1' : 'nurse2');
    $doc = demo_user_id($doctorUser);
    $lab = demo_user_id('lab1');
    $ph = demo_user_id('pharmacist1');

    $in = check_in_patient((int) $patient['id'], $apptId, $apptId ? null : (mt_rand(0, 1) ? $doc : null), $apptId ? '' : $complaint, $rec);
    if (!$in['ok']) {
        throw new RuntimeException('demo check-in failed: ' . $in['error']);
    }
    $vid = $in['visit_id'];
    if ($stop === 'checkin') {
        return $vid;
    }
    $age = age_on($patient['date_of_birth']);
    save_triage($vid, [
        'temperature' => (string) (36.2 + mt_rand(0, 28) / 10), 'pulse' => (string) mt_rand(62, 108), 'resp_rate' => (string) mt_rand(14, 22),
        'bp_systolic' => $age > 16 ? (string) mt_rand(108, 148) : '', 'bp_diastolic' => $age > 16 ? (string) mt_rand(66, 92) : '',
        'spo2' => (string) mt_rand(95, 99), 'weight_kg' => (string) ($age > 16 ? mt_rand(52, 88) : mt_rand(10, 40)), 'notes' => '',
    ], $nurse);
    if ($stop === 'triage') {
        return $vid;
    }
    $res = save_consultation($vid, [
        'service_id' => (string) db_value('SELECT id FROM services WHERE name = ?', [$type]), 'complaint' => $complaint,
        'examination' => 'Examined; findings consistent with the diagnosis.', 'diagnosis' => $diagnosis, 'icd10_code' => $icd,
        'plan' => 'Medicines as prescribed. Return if not improving.',
    ], $doc);
    if (!$res['ok']) {
        throw new RuntimeException('demo consultation failed: ' . json_encode($res['errors']));
    }
    if ($procedure) {
        record_visit_service($vid, (int) db_value('SELECT id FROM services WHERE name = ?', [$procedure[0]]), $procedure[1], $nurse);
    }
    if ($stop === 'consult') {
        return $vid;
    }
    if ($tests) {
        $ids = array_map(static fn ($n) => (int) db_value('SELECT id FROM lab_tests WHERE name = ?', [$n]), array_keys($tests));
        request_lab_tests($vid, $ids, 'Clinical suspicion', $doc);
    }
    $items = [];
    foreach ($meds as $name => [$qty, $dose, $freq, $dur]) {
        $items[] = ['medicine_id' => demo_med_id($name), 'dosage' => $dose, 'frequency' => $freq, 'duration' => $dur, 'quantity' => $qty];
    }
    $rx = $items ? create_prescription($vid, $items, $doc) : ['ok' => false, 'id' => 0];
    if ($stop === 'orders') {
        return $vid;
    }
    foreach (visit_lab_requests($vid) as $r) {
        start_lab_request((int) $r['id'], $lab);
        record_lab_result((int) $r['id'], $tests[$r['test_name']][0], $tests[$r['test_name']][1], $lab);
    }
    if ($stop === 'lab_done') {
        return $vid;
    }
    if ($rx['ok'] && $dispense) {
        $q = [];
        foreach (db_all('SELECT id, quantity FROM prescription_items WHERE prescription_id = ?', [$rx['id']]) as $it) {
            $q[(int) $it['id']] = (int) $it['quantity'];
        }
        if (!dispense_prescription($rx['id'], $q, $ph)['ok']) { // short of stock: give what is there
            $stock = array_column(medicine_stock(), 'on_hand', 'id');
            foreach (db_all('SELECT i.id, i.quantity, i.medicine_id FROM prescription_items i WHERE i.prescription_id = ?', [$rx['id']]) as $it) {
                $q[(int) $it['id']] = min((int) $it['quantity'], (int) ($stock[$it['medicine_id']] ?? 0));
            }
            dispense_prescription($rx['id'], $q, $ph);
        }
    }
    close_visit($vid, $doc);
    return $vid;
}

/** Move every record of a visit to a given day and clock time so reports and lists look like real history. */
function demo_backdate_visit(int $visitId, string $day, int $startMinute): void
{
    $t = static fn (int $m): string => (new DateTimeImmutable("$day 07:30:00"))->modify("+$m minutes")->format('Y-m-d H:i:s');
    db_exec('UPDATE visits SET visit_date = ?, closed_at = IF(closed_at IS NULL, NULL, ?) WHERE id = ?', [$t($startMinute), $t($startMinute + 50), $visitId]);
    db_exec('UPDATE triage SET recorded_at = ? WHERE visit_id = ?', [$t($startMinute + 12), $visitId]);
    db_exec('UPDATE consultations SET created_at = ?, updated_at = NULL WHERE visit_id = ?', [$t($startMinute + 30), $visitId]);
    db_exec('UPDATE visit_services SET recorded_at = ? WHERE visit_id = ?', [$t($startMinute + 20), $visitId]);
    db_exec('UPDATE lab_requests SET requested_at = ?, result_at = IF(result_at IS NULL, NULL, ?) WHERE visit_id = ?', [$t($startMinute + 35), $t($startMinute + 95), $visitId]);
    db_exec('UPDATE prescriptions SET created_at = ? WHERE visit_id = ?', [$t($startMinute + 38), $visitId]);
    foreach (db_all('SELECT id FROM prescriptions WHERE visit_id = ?', [$visitId]) as $rx) {
        db_exec("UPDATE stock_movements SET created_at = ? WHERE type = 'Dispense' AND reference LIKE ?", [$t($startMinute + 55), 'Rx ' . $rx['id'] . ' %']);
    }
    db_exec('UPDATE invoices SET created_at = ? WHERE visit_id = ?', [$t($startMinute + 30), $visitId]);
    db_exec('UPDATE payments SET paid_at = ? WHERE invoice_id IN (SELECT id FROM invoices WHERE visit_id = ?)', [$t($startMinute + 75), $visitId]);
}

/** Take payment for a visit's invoice. $mode: cash, mpesa, card, insurance, part or none. */
function demo_pay(int $visitId, string $mode): void
{
    $inv = get_invoice_for_visit($visitId);
    if (!$inv || $mode === 'none') {
        return;
    }
    $total = to_cents($inv['total']) / 100;
    $code = static fn (int $n): string => strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ0123456789'), 0, $n));
    $full = number_format($total, 2, '.', '');
    $in = match ($mode) {
        'cash'      => ['method' => 'Cash', 'amount' => $full, 'reference' => ''],
        'mpesa'     => ['method' => 'M-Pesa', 'amount' => $full, 'reference' => 'Q' . $code(9)],
        'card'      => ['method' => 'Card', 'amount' => $full, 'reference' => 'AP' . $code(6)],
        'insurance' => ['method' => 'Insurance', 'amount' => $full, 'reference' => 'AAR/2026/' . mt_rand(1000, 9999)],
        default     => ['method' => 'Cash', 'amount' => number_format(floor($total / 2), 2, '.', ''), 'reference' => ''],
    };
    record_payment((int) $inv['id'], $in, demo_user_id('cashier1'));
}

function seed_demo_appointments_and_visits(): array
{
    mt_srand(4040);
    $patients = db_all('SELECT * FROM patients ORDER BY id LIMIT 30');
    $doctors = ['doctor1', 'doctor2', 'doctor3'];
    $scenarios = array_keys(demo_scenarios());
    $payModes = ['cash', 'mpesa', 'insurance', 'card', 'mpesa', 'cash', 'part', 'cash', 'mpesa', 'none'];
    $perDay = [4, 5, 6, 5, 4, 6, 5, 3, 5, 6, 4];
    $rec = demo_user_id('reception1');
    $count = ['journeys' => 0, 'missed' => 0, 'cancelled' => 0];

    // The last 14 days, oldest first, without Sundays.
    $days = [];
    for ($i = 14; $i >= 1; $i--) {
        $d = (new DateTimeImmutable('today'))->modify("-$i days");
        if ((int) $d->format('N') !== 7) {
            $days[] = $d->format('Y-m-d');
        }
    }
    $seen = [];
    $uti = 0;
    $j = 0;
    foreach ($days as $di => $day) {
        $GLOBALS['__now'] = "$day 07:45:00";
        $slot = array_fill_keys($doctors, 0);
        for ($k = 0; $k < $perDay[$di % count($perDay)]; $k++, $j++) {
            $p = $patients[($j * 7 + 3) % count($patients)];
            $age = age_on($p['date_of_birth'], $day);
            $pool = array_values(array_filter($scenarios, static fn ($sc) => $age >= 18 || !demo_scenarios()[$sc][7]));
            $sc = $pool[($j * 5 + $k) % count($pool)];
            if ($sc === 'uti' && ++$uti > 2) {
                $sc = $age >= 18 ? 'back' : 'urti';
            }
            $doctor = $doctors[($j + $k) % 3];
            $docId = demo_user_id($doctor);
            if (db_value("SELECT id FROM visits WHERE patient_id = ? AND status = 'Open'", [$p['id']])) {
                continue;
            }
            $appt = null;
            if ($j % 5 !== 0) {
                $start = (new DateTimeImmutable("$day 08:00"))->modify('+' . ($slot[$doctor]++ * 20) . ' minutes');
                $appt = db_insert(
                    "INSERT INTO appointments (patient_id, doctor_id, appointment_date, start_time, end_time, reason, status, booked_by, created_at) VALUES (?, ?, ?, ?, ?, ?, 'Booked', ?, ?)",
                    [$p['id'], $docId, $day, $start->format('H:i:s'), $start->modify('+20 minutes')->format('H:i:s'), 'Booked visit', $rec, "$day 06:00:00"]
                );
            }
            $vid = demo_journey($p, $doctor, $sc, $appt, 'closed');
            demo_pay($vid, $payModes[$j % count($payModes)]);
            demo_backdate_visit($vid, $day, 20 + $k * 55 + mt_rand(0, 20));
            if ($appt) {
                db_exec('UPDATE appointments SET created_at = ? WHERE id = ?', [(new DateTimeImmutable($day))->modify('-2 days')->format('Y-m-d 09:00:00'), $appt]);
            }
            if (!isset($seen[$p['id']])) {
                $seen[$p['id']] = true;
                db_exec('UPDATE patients SET created_at = ? WHERE id = ?', [sprintf('%s 0%d:%02d:00', $day, mt_rand(7, 8), mt_rand(0, 59)), $p['id']]);
            }
            $count['journeys']++;
        }
        // appointments nobody turned up for, and some that were cancelled
        foreach ([['Missed', 'doctor1', 1], ['Cancelled', 'doctor2', 2]] as [$status, $doctor, $every]) {
            if ($di % $every === 0) {
                $p = $patients[($di * 11 + 5) % count($patients)];
                if (!db_value('SELECT id FROM appointments WHERE doctor_id = ? AND appointment_date = ? AND start_time = ?', [demo_user_id($doctor), $day, '16:00:00'])) {
                    db_insert(
                        "INSERT INTO appointments (patient_id, doctor_id, appointment_date, start_time, end_time, reason, status, cancel_reason, booked_by, created_at) VALUES (?, ?, ?, '16:00:00', '16:20:00', 'Booked visit', ?, ?, ?, ?)",
                        [$p['id'], demo_user_id($doctor), $day, $status, $status === 'Cancelled' ? 'Patient called to cancel' : null, $rec, "$day 06:00:00"]
                    );
                    $count[strtolower($status)]++;
                }
            }
        }
    }
    unset($GLOBALS['__now']);

    // ---- today: patients at every stage so each queue has something in it ----
    $today = (new DateTimeImmutable('today'))->format('Y-m-d');
    $pool = array_slice($patients, 18, 12);
    $stages = [
        ['checkin', 'malaria', 'doctor1', 'none'], ['checkin', 'urti', 'doctor2', 'none'],
        ['triage', 'wound', 'doctor1', 'none'], ['triage', 'back', 'doctor3', 'none'],
        ['consult', 'fever', 'doctor2', 'none'],
        ['orders', 'malaria', 'doctor1', 'none'],
        ['lab_done', 'fever', 'doctor3', 'none'],
        ['closed', 'gastro', 'doctor2', 'none'],
        ['closed', 'htn', 'doctor1', 'part'],
        ['closed', 'urti', 'doctor3', 'mpesa'],
    ];
    foreach ($stages as $i => [$stop, $sc, $doctor, $pay]) {
        $p = $pool[$i % count($pool)];
        if (db_value("SELECT id FROM visits WHERE patient_id = ? AND status = 'Open'", [$p['id']])) {
            continue;
        }
        $vid = demo_journey($p, $doctor, $sc, null, $stop, $stop === 'closed');
        $count['journeys']++;
        if ($stop === 'closed') {
            demo_pay($vid, $pay);
        }
    }
    // a booked patient who has not yet arrived today
    $p = $pool[count($stages) % count($pool)];
    db_insert(
        "INSERT INTO appointments (patient_id, doctor_id, appointment_date, start_time, end_time, reason, booked_by) VALUES (?, ?, ?, '15:00:00', '15:20:00', 'Review', ?)",
        [$p['id'], demo_user_id('doctor1'), $today, $rec]
    );

    // ---- the days ahead ----
    $n = 0;
    for ($i = 1; $i <= 6 && $n < 10; $i++) {
        $d = (new DateTimeImmutable('today'))->modify("+$i days");
        if ((int) $d->format('N') === 7) {
            continue;
        }
        foreach ([['09:00', 'doctor1'], ['10:20', 'doctor2'], ['11:40', 'doctor3']] as [$time, $doctor]) {
            if ($n >= 10) {
                break;
            }
            $p = $patients[($n * 3 + 1) % count($patients)];
            $r = book_appointment(['patient_id' => $p['id'], 'doctor_id' => demo_user_id($doctor), 'date' => $d->format('Y-m-d'), 'start_time' => $time, 'reason' => 'Follow-up'], $rec);
            $n += $r['ok'] ? 1 : 0;
        }
    }
    return ['visits (past 2 weeks + today)' => $count['journeys'], 'missed appointments' => $count['missed'], 'cancelled appointments' => $count['cancelled'], 'booked ahead' => $n];
}

function seed_demo_billing(): array
{
    // An old batch that has now expired, so the expiry alert has something to show.
    $metro = demo_med_id('Metronidazole');
    $id = db_insert("INSERT INTO medicine_batches (medicine_id, batch_no, expiry_date, quantity, received_at) VALUES (?, 'MTZ-2410', (CURDATE() - INTERVAL 20 DAY), 40, (NOW() - INTERVAL 200 DAY))", [$metro]);
    db_insert("INSERT INTO stock_movements (medicine_id, batch_id, type, quantity, reference, user_id, created_at) VALUES (?, ?, 'Receive', 40, 'Batch MTZ-2410', ?, (NOW() - INTERVAL 200 DAY))", [$metro, $id, demo_user_id('pharmacist1')]);
    return [
        'invoices' => (int) db_value('SELECT COUNT(*) FROM invoices'),
        'payments' => (int) db_value('SELECT COUNT(*) FROM payments'),
        'unpaid or part-paid invoices' => (int) db_value("SELECT COUNT(*) FROM invoices WHERE status <> 'Paid'"),
        'integrity problems found' => count(data_integrity_problems()),
    ];
}
