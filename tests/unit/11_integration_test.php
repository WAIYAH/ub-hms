<?php
declare(strict_types=1);

suite('Integration: the six modules working together');

/**
 * Walk one patient through the whole hospital. Returns the ids needed by the assertions.
 * Reception -> triage -> doctor -> laboratory -> pharmacy -> cashier.
 */
function full_journey(): array
{
    freeze_time('2026-11-02 08:30:00');
    $rec = make_user('receptionist');
    $nurse = make_user('nurse');
    $doc = make_user('doctor');
    $lab = make_user('lab');
    $ph = make_user('pharmacist');
    $cash = make_user('cashier');

    // Module 4 set-up: a medicine with two batches
    $med = make_medicine('Artemether/Lumefantrine', '25.00', 24, '20/120 mg', 'Tablet');
    stock_up($med, 'AL-EARLY', '2027-02-01', 60);
    stock_up($med, 'AL-LATE', '2028-02-01', 200);

    // Module 1: register, then book and check in
    $reg = register_patient(['first_name' => 'Journey', 'last_name' => 'Tester', 'sex' => 'Female', 'date_of_birth' => '1988-04-04', 'national_id' => '33000001', 'phone' => '0711 000 111', 'allergies' => 'Sulfa drugs'], $rec);
    $appt = book_appointment(['patient_id' => $reg['id'], 'doctor_id' => $doc, 'date' => '2026-11-02', 'start_time' => '09:00', 'reason' => 'Fever'], $rec);
    $visit = check_in_patient($reg['id'], $appt['id'], null, '', $rec)['visit_id'];

    // Module 2: triage and consultation
    save_triage($visit, ['temperature' => '39.1', 'pulse' => '110', 'weight_kg' => '62'], $nurse);
    save_consultation($visit, good_consultation(['diagnosis' => 'Malaria', 'icd10_code' => 'B54']), $doc);

    // Module 3: laboratory
    request_lab_tests($visit, [lab_test_id('Malaria test (BS/MPS)')], 'Fever and chills', $doc);
    $req = (int) visit_lab_requests($visit)[0]['id'];
    start_lab_request($req, $lab);
    record_lab_result($req, 'P. falciparum seen (++)', true, $lab);

    // Module 2 -> 4: prescribe, then dispense from the earliest-expiring batch
    $rx = create_prescription($visit, [['medicine_id' => $med, 'dosage' => '4 tablets', 'frequency' => 'twice a day', 'duration' => '3 days', 'quantity' => 24]], $doc);
    $item = (int) db_value('SELECT id FROM prescription_items WHERE prescription_id = ?', [$rx['id']]);
    dispense_prescription($rx['id'], [$item => 24], $ph);
    close_visit($visit, $doc);

    return ['rec' => $rec, 'nurse' => $nurse, 'doc' => $doc, 'lab' => $lab, 'ph' => $ph, 'cash' => $cash, 'patient' => $reg['id'], 'op' => $reg['op_number'],
            'appt' => $appt['id'], 'visit' => $visit, 'req' => $req, 'rx' => $rx['id'], 'med' => $med];
}

test('UT-INT-001', 'one patient passes through all six modules and every hand-over carries the right data', function () {
    $j = full_journey();
    assert_same('OP26-00001', $j['op']);
    assert_same('Attended', get_appointment($j['appt'])['status']);
    $v = get_visit($j['visit']);
    assert_same('Closed', $v['status']);
    assert_eq($j['doc'], $v['doctor_id']);
    assert_same('Malaria', get_consultation($j['visit'])['diagnosis']);

    $lab = get_lab_request($j['req']);
    assert_eq($j['doc'], $lab['requested_by'], 'the test is tied to the requesting doctor');
    assert_same('Completed', $lab['status']);
    assert_count(1, doctor_lab_requests($j['doc'], 'Completed'));

    assert_same('Dispensed', get_prescription($j['rx'])['status']);
    assert_eq(36, db_value("SELECT quantity FROM medicine_batches WHERE batch_no = 'AL-EARLY'"), 'the early batch was used first');

    // Module 5: the invoice assembled itself from the other modules
    $inv = get_invoice_for_visit($j['visit']);
    $lines = array_column(invoice_items((int) $inv['id']), 'source');
    sort($lines);
    assert_same(['Consultation', 'Laboratory', 'Pharmacy'], $lines);
    assert_eq(500 + 300 + 24 * 25, $inv['total']);

    // Pay by two methods, then the reports see it
    record_payment((int) $inv['id'], ['method' => 'M-Pesa', 'amount' => '1000', 'reference' => 'INTJRN0001'], $j['cash']);
    record_payment((int) $inv['id'], ['method' => 'Cash', 'amount' => '400', 'reference' => ''], $j['cash']);
    assert_same('Paid', get_invoice_for_visit($j['visit'])['status']);
    $today = date('Y-m-d');
    $rev = report_revenue($today, $today);
    assert_same('1400.00', $rev['billed']);
    assert_same('1400.00', $rev['collected']);
    assert_same('0.00', $rev['outstanding']);
    $labRep = report_lab($today, $today);
    assert_eq(1, $labRep['totals']['completed']);
    $stock = report_stock($today, $today);
    $row = array_values(array_filter($stock['rows'], fn ($m) => $m['name'] === 'Artemether/Lumefantrine'))[0];
    assert_eq(24, $row['dispensed']);
    assert_eq(236, $row['on_hand']);
});

test('UT-INT-002', 'every important action in the journey left an audit entry by the person who did it', function () {
    $j = full_journey();
    record_payment((int) get_invoice_for_visit($j['visit'])['id'], ['method' => 'Cash', 'amount' => '100', 'reference' => ''], $j['cash']);
    $expected = [
        ['patient', 'create', 'rec'], ['appointment', 'create', 'rec'], ['visit', 'checkin', 'rec'],
        ['triage', 'create', 'nurse'], ['consultation', 'create', 'doc'], ['lab_request', 'create', 'doc'],
        ['lab_request', 'start', 'lab'], ['lab_request', 'result', 'lab'], ['prescription', 'create', 'doc'],
        ['prescription', 'dispense', 'ph'], ['medicine_batch', 'receive', null], ['visit', 'close', 'doc'],
        ['invoice', 'create', null], ['payment', 'payment', 'cash'],
    ];
    foreach ($expected as [$entity, $action, $who]) {
        $row = db_one('SELECT user_id FROM audit_log WHERE entity = ? AND action = ? ORDER BY id LIMIT 1', [$entity, $action]);
        assert_not_null($row, "audit entry for $entity/$action");
        if ($who !== null) {
            assert_eq($j[$who], $row['user_id'], "$entity/$action was done by $who");
        }
    }
    // The log never contains clinical content from this journey.
    $all = implode(' ', array_column(db_all('SELECT COALESCE(details, "") AS d FROM audit_log'), 'd'));
    foreach (['Malaria', 'falciparum', 'Sulfa', '39.1', 'Fever and chills'] as $secret) {
        assert_not_contains($secret, $all, "audit trail must not hold '$secret'");
    }
});

test('UT-INT-003', 'the stock ledger, invoices and payments all reconcile after the journey', function () {
    $j = full_journey();
    record_payment((int) get_invoice_for_visit($j['visit'])['id'], ['method' => 'Card', 'amount' => '250', 'reference' => 'INTCARD01'], $j['cash']);
    assert_same([], data_integrity_problems());
});

test('UT-INT-004', 'the integrity check actually detects corrupted records', function () {
    $j = full_journey();
    $inv = get_invoice_for_visit($j['visit']);
    db_exec('UPDATE invoices SET total = total + 10 WHERE id = ?', [$inv['id']]);
    db_exec("UPDATE medicine_batches SET quantity = quantity + 1 WHERE batch_no = 'AL-LATE'");
    db_exec("UPDATE prescriptions SET status = 'Pending' WHERE id = ?", [$j['rx']]);
    $problems = implode(' | ', data_integrity_problems());
    assert_contains('Invoice total differs', $problems);
    assert_contains('Batch balance differs', $problems);
    assert_contains('Prescription status does not match', $problems);
    assert_not_contains('Invoice status does not match', $problems, 'an unpaid invoice whose total rose is still correctly Unpaid');
});

test('UT-INT-005', 'records cannot be deleted out from under the data that refers to them', function () {
    $j = full_journey();
    foreach ([['patients', $j['patient']], ['users', $j['doc']], ['visits', $j['visit']], ['medicines', $j['med']]] as [$table, $id]) {
        $e = assert_throws(fn () => db_exec("DELETE FROM `$table` WHERE id = ?", [$id]));
        assert_true($e instanceof PDOException, "$table row is protected by a foreign key");
    }
    assert_not_null(get_patient($j['patient']));
});

test('UT-INT-006', 'every table has a primary key and every foreign key column is indexed', function () {
    $noPk = db_all(
        "SELECT t.table_name FROM information_schema.tables t
          WHERE t.table_schema = DATABASE() AND t.table_type = 'BASE TABLE'
            AND NOT EXISTS (SELECT 1 FROM information_schema.table_constraints c WHERE c.table_schema = t.table_schema AND c.table_name = t.table_name AND c.constraint_type = 'PRIMARY KEY')"
    );
    assert_same([], array_column($noPk, 'table_name'));
    $unindexed = db_all(
        "SELECT k.table_name, k.column_name FROM information_schema.key_column_usage k
          WHERE k.table_schema = DATABASE() AND k.referenced_table_name IS NOT NULL
            AND NOT EXISTS (SELECT 1 FROM information_schema.statistics s WHERE s.table_schema = k.table_schema AND s.table_name = k.table_name AND s.column_name = k.column_name AND s.seq_in_index = 1)"
    );
    assert_same([], $unindexed);
    assert_eq(22, db_value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"));
});

test('UT-INT-007', 'sessions expire after 30 idle minutes or 8 hours in total', function () {
    $s = ['user' => ['id' => 1], 'last_activity' => 10000, 'signed_in_at' => 10000];
    assert_false(session_has_expired($s, 10000 + 1799, 1800, 28800));
    assert_false(session_has_expired($s, 10000 + 1800, 1800, 28800), 'exactly at the limit is still valid');
    assert_true(session_has_expired($s, 10000 + 1801, 1800, 28800));
    $busy = ['user' => ['id' => 1], 'last_activity' => 10000 + 28000, 'signed_in_at' => 10000];
    assert_false(session_has_expired($busy, 10000 + 28800, 1800, 28800));
    assert_true(session_has_expired($busy, 10000 + 28801, 1800, 28800), 'an always-busy session still ends after 8 hours');
    assert_false(session_has_expired(['last_activity' => 1], 999999, 1800, 28800), 'nobody signed in');
});

test('UT-INT-008', 'internal folders are shielded from the web and the default configuration holds no secrets', function () {
    foreach (['config', 'includes', 'database', 'tests', 'storage', 'scripts', 'docs'] as $dir) {
        $f = APP_ROOT . "/$dir/.htaccess";
        assert_true(is_file($f), "$dir/.htaccess exists");
        assert_contains('Require all denied', (string) file_get_contents($f));
    }
    $cfg = (string) file_get_contents(APP_ROOT . '/config/config.php');
    assert_true(!preg_match("/'pass'\s*=>\s*'[^']+'/", $cfg), 'no password literal in config.php');
    assert_contains('display_errors', (string) file_get_contents(APP_ROOT . '/includes/bootstrap.php'));
    $gi = (string) file_get_contents(APP_ROOT . '/.gitignore');
    assert_contains('config/config.local.php', $gi);
});

test('UT-INT-009', 'no page can change data from a plain GET: every state-changing page requires a POST with a token', function () {
    $pages = [
        'logout.php', 'appointments/action.php', 'billing/pay.php', 'admin/users.php', 'admin/prices.php', 'patients/import.php',
        'visits/consult.php', 'visits/triage.php', 'visits/checkin.php', 'lab/index.php', 'lab/result.php', 'pharmacy/dispense.php',
        'pharmacy/batches.php', 'pharmacy/receive.php', 'pharmacy/medicine.php', 'patients/register.php', 'patients/edit.php', 'admin/user.php', 'password.php',
    ];
    foreach ($pages as $page) {
        $src = (string) file_get_contents(APP_ROOT . '/' . $page);
        assert_true(strpos($src, 'require_post()') !== false || $page === 'logout.php' && strpos($src, 'require_post') !== false,
            "$page checks the CSRF token before changing anything");
    }
});

test('UT-INT-010', 'the health page checks run and report a sound database', function () {
    $checks = system_health();
    $byName = array_column($checks, 'status', 'name');
    assert_same('ok', $byName['Database connection']);
    assert_same('ok', $byName['Database tables']);
    assert_same('ok', $byName['Data integrity (invoices, payments, stock)']);
    assert_has_key('Latest backup', $byName);
    $counts = table_counts();
    assert_eq(0, $counts['patients']);
});

test('UT-INT-011', 'a second doctor cannot read results for another doctor\'s tests but can still see the patient history', function () {
    $j = full_journey();
    $docB = make_user('doctor');
    assert_count(0, doctor_lab_requests($docB));
    assert_count(1, patient_history($j['patient']), 'the clinical history is shared for continuity of care');
    assert_false(save_consultation($j['visit'], good_consultation(), $docB)['ok'], 'but the original consultation is not editable by others');
});
