<?php
declare(strict_types=1);

suite('Module 6: patient data rights (copy and erasure)');

function dr_input(array $o = []): array
{
    return $o + ['requester_name' => 'Jane Requester', 'requester_relation' => 'The patient', 'identity_checked' => '1', 'identity_note' => 'National ID card seen', 'reason' => 'Wants a copy'];
}

/** The whole hospital journey with the invoice paid in full, so nothing stops the record being erased. */
function erasable_journey(): array
{
    $j = full_journey();
    $inv = inv_for($j['visit']);
    $paid = pay((int) $inv['id'], 'Cash', (string) $inv['total'], '', $j['cash']);
    if (!$paid['ok']) {
        throw new RuntimeException('erasable_journey: ' . json_encode($paid['errors']));
    }
    db_exec("UPDATE triage SET notes = 'Patient is anxious' WHERE visit_id = ?", [$j['visit']]);
    $j['mgr'] = make_user('manager');
    $j['invoice'] = (int) $inv['id'];
    $j['total'] = (string) $inv['total'];
    return $j;
}

function erase_now(array $j, string $name = 'Jane Requester'): int
{
    $req = create_data_request($j['patient'], 'Erasure', dr_input(['requester_name' => $name]), $j['rec']);
    if (!$req['ok']) {
        throw new RuntimeException('erase_now: ' . json_encode($req['errors']));
    }
    $d = decide_erasure($req['id'], 'approve', 'Checked', $j['mgr']);
    if (!$d['ok']) {
        throw new RuntimeException('erase_now: ' . $d['error']);
    }
    return $req['id'];
}

test('UT-PRV-001', 'a request needs the name of the person, who they are, a ticked identity check and how it was checked', function () {
    $rec = make_user('receptionist');
    $pat = make_patient([], $rec);
    $r = create_data_request($pat, 'Export', ['requester_name' => '', 'requester_relation' => 'Neighbour', 'identity_checked' => '', 'identity_note' => 'ok'], $rec);
    assert_false($r['ok']);
    foreach (['requester_name', 'requester_relation', 'identity_checked', 'identity_note'] as $k) {
        assert_has_key($k, $r['errors']);
    }
    assert_false(create_data_request($pat, 'Delete', dr_input(), $rec)['ok'], 'only copy and erasure exist');
    assert_false(create_data_request(999999, 'Export', dr_input(), $rec)['ok'], 'unknown patient');
    assert_eq(0, db_value('SELECT COUNT(*) FROM data_requests'));
    foreach (DATA_RELATIONS as $rel) {
        assert_true(create_data_request($pat, 'Export', dr_input(['requester_relation' => $rel]), $rec)['ok'], $rel);
    }
});

test('UT-PRV-002', 'a copy request is completed at once, numbered, and audited without the name or the identity note', function () {
    $rec = make_user('receptionist');
    $pat = make_patient([], $rec);
    $r = create_data_request($pat, 'Export', dr_input(), $rec);
    assert_true($r['ok']);
    assert_true((bool) preg_match('/^DR-\d{4}-\d{6}$/', $r['reference']));
    $dr = get_data_request($r['id']);
    assert_same('Export', $dr['kind']);
    assert_same('Completed', $dr['status']);
    assert_not_null($dr['decided_at']);
    assert_null($dr['decided_by']);
    assert_eq($rec, $dr['created_by']);
    assert_eq(1, $dr['identity_checked']);
    $audit = db_one("SELECT details FROM audit_log WHERE action = 'data_request'");
    assert_contains($r['reference'], $audit['details']);
    assert_contains(get_patient($pat)['op_number'], $audit['details']);
    assert_not_contains('Jane Requester', $audit['details']);
    assert_not_contains('National ID', $audit['details']);
    assert_not_contains('Wants a copy', $audit['details']);
});

test('UT-PRV-003', 'the copy holds everything about the patient: details, appointments, visits, tests, medicines, invoices, payments and requests', function () {
    $j = erasable_journey();
    $refund = db_one('SELECT id FROM payments WHERE invoice_id = ?', [$j['invoice']]);
    $rq = request_refund((int) $refund['id'], ['amount' => '50', 'reason' => 'Asked and rejected'], $j['cash']);
    decide_refund($rq['id'], 'reject', 'No', $j['mgr']);
    create_data_request($j['patient'], 'Export', dr_input(), $j['rec']);

    $b = patient_record_bundle($j['patient']);
    assert_same('Journey', $b['patient']['first_name']);
    assert_same('33000001', $b['patient']['national_id']);
    assert_same('Sulfa drugs', $b['patient']['allergies']);
    assert_same($j['op'], $b['patient']['outpatient_number']);
    assert_count(1, $b['appointments']);
    assert_same('Attended', $b['appointments'][0]['status']);
    assert_count(1, $b['visits']);
    $v = $b['visits'][0];
    assert_same('Closed', $v['status']);
    assert_same('39.1', $v['triage']['temperature_c']);
    assert_same('Patient is anxious', $v['triage']['notes']);
    assert_same('Malaria', $v['consultation']['diagnosis']);
    assert_same('B54', $v['consultation']['icd10_code']);
    assert_same('Unspecified malaria', $v['consultation']['icd10_description']);
    assert_count(1, $v['laboratory']);
    assert_same('P. falciparum seen (++)', $v['laboratory'][0]['result']);
    assert_true($v['laboratory'][0]['abnormal']);
    assert_count(1, $v['medicines']);
    assert_contains('Artemether', $v['medicines'][0]['medicine']);
    assert_same(24, $v['medicines'][0]['quantity_dispensed']);
    assert_count(1, $b['invoices']);
    $i = $b['invoices'][0];
    assert_same('Paid', $i['status']);
    assert_true(count($i['items']) >= 3, 'consultation, test and medicine lines');
    assert_count(1, $i['payments']);
    assert_same('Payment', $i['payments'][0]['type']);
    assert_same($j['total'], $i['payments'][0]['amount']);
    assert_count(1, $b['refund_requests']);
    assert_same('Rejected', $b['refund_requests'][0]['status']);
    assert_count(1, $b['data_requests']);
    assert_array_not_has_audit($b);
    $json = json_encode($b, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    assert_true(is_string($json) && $json !== '', 'it can be written as a JSON file');
    assert_same($b['patient']['first_name'], json_decode($json, true)['patient']['first_name']);
});

function assert_array_not_has_audit(array $b): void
{
    assert_false(array_key_exists('audit', $b) || array_key_exists('audit_log', $b), 'the hospital security log is not part of the copy');
    assert_not_contains('login', json_encode($b));
}

test('UT-PRV-004', 'the copy of one patient never contains another patient', function () {
    $j = erasable_journey();
    $other = make_patient(['first_name' => 'Zebedee', 'last_name' => 'Unrelated', 'national_id' => '55555555', 'phone' => '0799 888 777'], $j['rec']);
    $ov = open_visit($other, $j['rec']);
    save_consultation($ov, good_consultation(['diagnosis' => 'Something private', 'complaint' => 'Secret complaint']), $j['doc']);
    $json = (string) json_encode(patient_record_bundle($j['patient']));
    foreach (['Zebedee', 'Unrelated', '55555555', '0799', 'Something private', 'Secret complaint'] as $needle) {
        assert_not_contains($needle, $json);
    }
    $html = render_record_copy(patient_record_bundle($j['patient']));
    assert_not_contains('Zebedee', $html);
    assert_null(patient_record_bundle(999999));
});

test('UT-PRV-005', 'the printed copy shows the record and escapes everything it prints', function () {
    $j = erasable_journey();
    db_exec("UPDATE patients SET residence = '<i>Estate</i> & Co' WHERE id = ?", [$j['patient']]);
    $html = render_record_copy(patient_record_bundle($j['patient']));
    assert_contains('Journey Tester', $html);
    assert_contains($j['op'], $html);
    assert_contains('Malaria', $html);
    assert_contains('Sulfa drugs', $html);
    assert_contains(inv_for($j['visit'])['invoice_no'], $html);
    assert_contains('&lt;i&gt;Estate&lt;/i&gt; &amp; Co', $html);
    assert_not_contains('<i>Estate</i>', $html);
    assert_contains('does not include', $html, 'the page says the security log is left out');
});

test('UT-PRV-006', 'an erasure request stays open for the manager, and only one can be open for a patient at a time', function () {
    $rec = make_user('receptionist');
    $pat = make_patient([], $rec);
    $r = create_data_request($pat, 'Erasure', dr_input(), $rec);
    assert_true($r['ok']);
    $dr = get_data_request($r['id']);
    assert_same('Erasure', $dr['kind']);
    assert_same('Open', $dr['status']);
    assert_null($dr['decided_at']);
    assert_null($dr['erased_at'], 'nothing is erased by asking');
    $again = create_data_request($pat, 'Erasure', dr_input(), $rec);
    assert_false($again['ok']);
    assert_contains('already an open erasure request', $again['errors']['requester_name']);
    assert_true(create_data_request($pat, 'Export', dr_input(), $rec)['ok'], 'a copy can still be given meanwhile');
    assert_null(cancel_data_request($r['id'], $rec));
    assert_true(create_data_request($pat, 'Erasure', dr_input(), $rec)['ok'], 'after a withdrawal a new request is possible');
});

test('UT-PRV-007', 'a record cannot be erased while there is an open visit, a booked appointment, money owed, a waiting refund or a retention period', function () {
    freeze_time('2026-11-02 08:30:00');
    $rec = make_user('receptionist');
    $cash = make_user('cashier');
    $doc = make_user('doctor');
    $pat = make_patient([], $rec);
    assert_same([], erasure_blockers($pat), 'a new patient with no history can be erased');
    assert_same(['The patient record does not exist.'], erasure_blockers(999999));

    $vid = open_visit($pat, $rec);
    assert_contains('open visit', implode(' ', erasure_blockers($pat)));
    save_consultation($vid, good_consultation(), $doc);
    close_visit($vid, $doc);

    $inv = inv_for($vid);
    $owed = implode(' ', erasure_blockers($pat));
    assert_contains($inv['invoice_no'], $owed);
    assert_contains('balance of KSh 500.00', $owed);
    $p = pay((int) $inv['id'], 'Cash', '200.00', '', $cash);
    assert_contains('balance of KSh 300.00', implode(' ', erasure_blockers($pat)), 'a part-payment is still money owed');
    pay((int) $inv['id'], 'Cash', '300.00', '', $cash);
    assert_same([], erasure_blockers($pat));

    $rq = request_refund((int) $p['payment_id'], ['amount' => '10', 'reason' => 'Valid reason'], $cash);
    assert_contains('1 refund request', implode(' ', erasure_blockers($pat)));
    decide_refund($rq['id'], 'reject', 'No', make_user('manager'));
    assert_same([], erasure_blockers($pat));

    book_appointment(['patient_id' => $pat, 'doctor_id' => $doc, 'date' => '2026-11-03', 'start_time' => '09:00'], $rec);
    assert_contains('1 booked appointment', implode(' ', erasure_blockers($pat)));
    db_exec("UPDATE appointments SET status = 'Cancelled'");
    assert_same([], erasure_blockers($pat));

    assert_same([], erasure_blockers($pat, 0), 'no retention period set');
    $held = implode(' ', erasure_blockers($pat, 10));
    assert_contains('must be kept until', $held);
    assert_contains('2036', $held);
    db_exec("UPDATE visits SET visit_date = '2014-05-05 10:00:00'");
    assert_same([], erasure_blockers($pat, 10), 'the last visit was long enough ago');
    assert_contains('must be kept until', implode(' ', erasure_blockers($pat, 15)));
});

test('UT-PRV-008', 'the manager decides: a refusal needs a reason, the person who recorded it cannot decide it, and a blocked record is not erased', function () {
    $j = erasable_journey();
    $req = create_data_request($j['patient'], 'Erasure', dr_input(), $j['rec']);
    $id = $req['id'];
    assert_contains('cannot decide a request you recorded yourself', decide_erasure($id, 'approve', '', $j['rec'])['error']);
    assert_contains('Say why', decide_erasure($id, 'refuse', '', $j['mgr'])['error']);
    assert_false(decide_erasure($id, 'maybe', '', $j['mgr'])['ok']);
    assert_false(decide_erasure(999999, 'approve', '', $j['mgr'])['ok']);
    $export = create_data_request($j['patient'], 'Export', dr_input(), $j['rec']);
    assert_false(decide_erasure($export['id'], 'approve', '', $j['mgr'])['ok'], 'a copy request is not an erasure');
    assert_same('Open', get_data_request($id)['status']);
    assert_null(get_patient($j['patient'])['erased_at']);

    // an open visit appears after the request was made: approval must notice
    $v = open_visit($j['patient'], $j['rec']);
    $blocked = decide_erasure($id, 'approve', '', $j['mgr']);
    assert_false($blocked['ok']);
    assert_contains('open visit', $blocked['error']);
    assert_same('Open', get_data_request($id)['status']);
    $p = get_patient($j['patient']);
    assert_same('Journey', $p['first_name']);
    assert_null($p['erased_at']);
    assert_true(decide_erasure($id, 'refuse', 'The patient has an open visit', $j['mgr'])['ok'], 'refusing is always possible');
    assert_contains('already been refused', decide_erasure($id, 'approve', '', $j['mgr'])['error']);
});

test('UT-PRV-009', 'approving anonymises the patient and the free text, keeps the coded data and every money line, and is audited', function () {
    $j = erasable_journey();
    $before = [
        'visits' => db_value('SELECT COUNT(*) FROM visits'), 'invoices' => db_value('SELECT COUNT(*) FROM invoices'),
        'items' => db_value('SELECT COUNT(*) FROM invoice_items'), 'payments' => db_value('SELECT SUM(amount) FROM payments'),
        'total' => db_value('SELECT SUM(total) FROM invoices'), 'moves' => db_value('SELECT COUNT(*) FROM stock_movements'),
    ];
    $requestId = erase_now($j);

    $p = get_patient($j['patient']);
    assert_same('Erased', $p['first_name']);
    assert_same('Patient', $p['last_name']);
    assert_null($p['middle_name']);
    assert_same('ERASED-' . str_pad((string) $j['patient'], 6, '0', STR_PAD_LEFT), $p['op_number']);
    foreach (['national_id', 'phone', 'residence', 'allergies', 'next_of_kin_name', 'next_of_kin_phone', 'next_of_kin_relation'] as $col) {
        assert_null($p[$col], $col);
    }
    assert_same('1988-01-01', $p['date_of_birth'], 'only the year of birth is kept');
    assert_eq(1, $p['dob_estimated']);
    assert_same('Female', $p['sex']);
    assert_not_null($p['erased_at']);
    assert_eq($j['mgr'], $p['erased_by']);

    $erased = ERASED_TEXT;
    assert_same($erased, db_value('SELECT reason FROM visits WHERE patient_id = ?', [$j['patient']]));
    assert_same($erased, db_value('SELECT reason FROM appointments WHERE patient_id = ?', [$j['patient']]));
    $c = db_one('SELECT * FROM consultations');
    foreach (['complaint', 'examination', 'plan'] as $col) {
        assert_same($erased, $c[$col], $col);
    }
    assert_same('Unspecified malaria', $c['diagnosis'], 'the diagnosis text becomes the standard description of its code');
    assert_same('B54', $c['icd10_code'], 'the code is kept for statistics');
    $t = db_one('SELECT * FROM triage');
    assert_same($erased, $t['notes']);
    assert_eq(39.1, $t['temperature']);
    $lab = db_one('SELECT * FROM lab_requests');
    assert_same($erased, $lab['clinical_notes']);
    assert_same($erased, $lab['result']);
    assert_eq(1, $lab['is_abnormal']);
    assert_same('Completed', $lab['status']);

    $dr = get_data_request($requestId);
    assert_same('Completed', $dr['status']);
    assert_eq($j['mgr'], $dr['decided_by']);
    assert_same('Checked', $dr['decision_note']);
    assert_same($erased, $dr['requester_name']);
    assert_null($dr['identity_note']);
    assert_null($dr['reason']);

    foreach ($before as $k => $v) {
        $after = ['visits' => 'SELECT COUNT(*) FROM visits', 'invoices' => 'SELECT COUNT(*) FROM invoices', 'items' => 'SELECT COUNT(*) FROM invoice_items',
                  'payments' => 'SELECT SUM(amount) FROM payments', 'total' => 'SELECT SUM(total) FROM invoices', 'moves' => 'SELECT COUNT(*) FROM stock_movements'][$k];
        assert_eq($v, db_value($after), "$k are unchanged");
    }
    $audit = db_one("SELECT details, user_id FROM audit_log WHERE action = 'erase'");
    assert_contains($dr['reference'], $audit['details']);
    assert_eq($j['mgr'], $audit['user_id']);
    assert_not_contains('Journey', $audit['details']);
    assert_same([], data_integrity_problems(), 'an erased record passes every check');

    assert_count(0, search_patients('Journey')[0], 'not found by name');
    assert_count(0, search_patients('0711 000 111')[0], 'not found by phone');
    assert_count(0, search_patients('33000001')[0], 'not found by ID number');
    assert_count(0, search_patients($j['op'])[0], 'not found by the old outpatient number');
    assert_count(0, search_patients('Erased')[0], 'erased records are not offered in a search');
});

test('UT-PRV-010', 'an erased record can no longer be changed, booked, checked in, copied or erased again', function () {
    $j = erasable_journey();
    erase_now($j);
    $pid = $j['patient'];
    $edit = update_patient($pid, ['first_name' => 'Back', 'last_name' => 'Again', 'sex' => 'Female', 'date_of_birth' => '1988-01-01'], $j['rec']);
    assert_false($edit['ok']);
    assert_contains('erased', $edit['errors']['first_name']);
    assert_same('Erased', get_patient($pid)['first_name']);

    $in = check_in_patient($pid, null, null, 'Cough', $j['rec']);
    assert_false($in['ok']);
    assert_contains('erased', $in['error']);

    $bk = book_appointment(['patient_id' => $pid, 'doctor_id' => $j['doc'], 'date' => '2026-11-03', 'start_time' => '09:00'], $j['rec']);
    assert_false($bk['ok']);
    assert_contains('erased', $bk['errors']['patient_id']);

    assert_false(create_data_request($pid, 'Export', dr_input(), $j['rec'])['ok']);
    assert_false(create_data_request($pid, 'Erasure', dr_input(), $j['rec'])['ok']);
    assert_same(['This record has already been erased.'], erasure_blockers($pid));
    assert_eq(0, db_value("SELECT COUNT(*) FROM visits WHERE status = 'Open'"));
});

test('UT-PRV-011', 'a refused request leaves the patient exactly as they were, and a new request can follow', function () {
    $j = erasable_journey();
    $req = create_data_request($j['patient'], 'Erasure', dr_input(), $j['rec']);
    assert_true(decide_erasure($req['id'], 'refuse', 'Records must be kept for the insurer', $j['mgr'])['ok']);
    $dr = get_data_request($req['id']);
    assert_same('Refused', $dr['status']);
    assert_same('Records must be kept for the insurer', $dr['decision_note']);
    assert_eq($j['mgr'], $dr['decided_by']);
    $p = get_patient($j['patient']);
    assert_same('Journey', $p['first_name']);
    assert_same('33000001', $p['national_id']);
    assert_null($p['erased_at']);
    assert_eq(1, db_value("SELECT COUNT(*) FROM audit_log WHERE action = 'erase_refused'"));
    assert_eq(0, db_value("SELECT COUNT(*) FROM audit_log WHERE action = 'erase'"));
    assert_true(create_data_request($j['patient'], 'Erasure', dr_input(), $j['rec'])['ok']);
});

test('UT-PRV-012', 'only the person who recorded an erasure request can withdraw it, and only while it is open', function () {
    $rec = make_user('receptionist');
    $mgr = make_user('manager');
    $pat = make_patient([], $rec);
    $r = create_data_request($pat, 'Erasure', dr_input(), $rec);
    assert_contains('Only the person who recorded', (string) cancel_data_request($r['id'], make_user('receptionist')));
    assert_contains('Only the person who recorded', (string) cancel_data_request($r['id'], $mgr));
    assert_null(cancel_data_request($r['id'], $rec));
    $dr = get_data_request($r['id']);
    assert_same('Cancelled', $dr['status']);
    assert_eq($rec, $dr['decided_by']);
    assert_contains('already been cancelled', (string) cancel_data_request($r['id'], $rec));
    assert_contains('already been cancelled', decide_erasure($r['id'], 'approve', '', $mgr)['error']);
    assert_contains('does not exist', (string) cancel_data_request(999999, $rec));
});

test('UT-PRV-013', 'erasing one patient changes nothing for anyone else', function () {
    $j = erasable_journey();
    $other = make_patient(['first_name' => 'Keep', 'last_name' => 'Me', 'national_id' => '44444444', 'phone' => '0722 123 456', 'residence' => 'Nakuru', 'allergies' => 'Penicillin'], $j['rec']);
    $ov = open_visit($other, $j['rec']);
    save_consultation($ov, good_consultation(['complaint' => 'Keep this complaint']), $j['doc']);
    $keepRequest = create_data_request($other, 'Export', dr_input(['requester_name' => 'Keep Requester']), $j['rec']);
    erase_now($j);
    $o = get_patient($other);
    assert_same('Keep', $o['first_name']);
    assert_same('44444444', $o['national_id']);
    assert_same('Nakuru', $o['residence']);
    assert_same('Penicillin', $o['allergies']);
    assert_null($o['erased_at']);
    assert_same('Cough', db_value('SELECT reason FROM visits WHERE patient_id = ?', [$other]));
    assert_same('Keep this complaint', db_value('SELECT complaint FROM consultations WHERE visit_id = ?', [$ov]));
    assert_same('Keep Requester', get_data_request($keepRequest['id'])['requester_name']);
    assert_count(1, search_patients('Keep')[0]);
});

test('UT-PRV-014', 'a patient with no visits, no appointments and no invoices is erased cleanly', function () {
    $rec = make_user('receptionist');
    $mgr = make_user('manager');
    $pat = make_patient(['middle_name' => 'Mid', 'residence' => 'Kisumu'], $rec);
    $r = create_data_request($pat, 'Erasure', dr_input(), $rec);
    assert_true(decide_erasure($r['id'], 'approve', '', $mgr)['ok']);
    $p = get_patient($pat);
    assert_same('Erased', $p['first_name']);
    assert_null($p['middle_name']);
    assert_null($p['residence']);
    assert_null(get_data_request($r['id'])['decision_note'], 'no note was given, none is stored');
    assert_same([], data_integrity_problems());
});

test('UT-PRV-015', 'erasing a record also blanks the free text of its refund requests and keeps their amounts', function () {
    $j = erasable_journey();
    $pay = (int) db_value("SELECT id FROM payments WHERE kind = 'Payment' AND invoice_id = ?", [$j['invoice']]);
    $rq = request_refund($pay, ['amount' => '100', 'reason' => 'Jane paid twice at the desk'], $j['cash']);
    decide_refund($rq['id'], 'reject', 'Jane has only one payment on the system', $j['mgr']);
    erase_now($j);
    $rr = get_refund_request($rq['id']);
    assert_same(ERASED_TEXT, $rr['reason']);
    assert_same(ERASED_TEXT, $rr['decision_note']);
    assert_eq(100, $rr['amount']);
    assert_same('Rejected', $rr['status']);
    assert_same($rq['reference'], $rr['reference']);
});

test('UT-PRV-016', 'the database refuses a data request with no identity check or one decided by the person who recorded it', function () {
    $rec = make_user('receptionist');
    $pat = make_patient([], $rec);
    $ins = fn (int $checked, string $status, ?int $decidedBy) => db_exec(
        "INSERT INTO data_requests (patient_id, kind, requester_name, requester_relation, identity_checked, status, created_by, decided_by, decided_at)
         VALUES (?, 'Erasure', 'X', 'The patient', ?, ?, ?, ?, NOW())",
        [$pat, $checked, $status, $rec, $decidedBy]
    );
    assert_throws(fn () => $ins(0, 'Open', null), 'identity must be checked');
    assert_throws(fn () => $ins(1, 'Completed', $rec), 'the recorder cannot decide');
    assert_eq(1, $ins(1, 'Completed', null));
});

test('UT-PRV-017', 'Records asks for copies and erasures, the manager decides erasures, and nobody else is involved', function () {
    assert_true(role_can('receptionist', 'patients.export'));
    assert_true(role_can('receptionist', 'patients.erase.request'));
    assert_true(role_can('receptionist', 'privacy.view'));
    assert_false(role_can('receptionist', 'patients.erase.approve'), 'the person who asks cannot decide');
    assert_true(role_can('manager', 'patients.erase.approve'));
    assert_true(role_can('manager', 'privacy.view'));
    assert_false(role_can('manager', 'patients.export'), 'the manager never takes a copy of a record');
    assert_false(role_can('manager', 'patients.erase.request'));
    assert_false(role_can('manager', 'patients.view'), 'the manager decides without seeing who the patient is');
    foreach (['admin', 'nurse', 'doctor', 'lab', 'pharmacist', 'cashier'] as $role) {
        foreach (['patients.export', 'patients.erase.request', 'patients.erase.approve', 'privacy.view'] as $perm) {
            assert_false(role_can($role, $perm), "$role $perm");
        }
    }
});

test('UT-PRV-018', 'the manager is told how many erasures wait; Records is not asked to decide', function () {
    $rec = make_user('receptionist');
    $mgr = make_user('manager');
    $pat = make_patient([], $rec);
    $r = create_data_request($pat, 'Erasure', dr_input(), $rec);
    create_data_request($pat, 'Export', dr_input(), $rec);
    $c = shell_counts(['id' => $mgr + 7000, 'role' => 'manager']);
    assert_eq(1, $c['erasures_open'], 'a copy request is not a decision for the manager');
    assert_contains('1 record erasure request waiting for your decision', implode('|', array_column(notifications_for(['id' => $mgr, 'role' => 'manager'], $c), 'title')));
    assert_eq(1, nav_badges(['id' => $mgr, 'role' => 'manager'], $c)['privacy']);
    assert_false(array_key_exists('erasures_open', shell_counts(['id' => $rec + 7000, 'role' => 'receptionist'])));
    decide_erasure($r['id'], 'refuse', 'Not now', $mgr);
    assert_eq(0, shell_counts(['id' => $mgr + 8000, 'role' => 'manager'])['erasures_open']);
});

test('UT-PRV-019', 'requests can be listed and filtered, open ones first', function () {
    $rec = make_user('receptionist');
    $mgr = make_user('manager');
    $a = make_patient([], $rec);
    $b = make_patient([], $rec);
    $done = create_data_request($a, 'Export', dr_input(), $rec);
    $open = create_data_request($b, 'Erasure', dr_input(), $rec);
    $rows = list_data_requests();
    assert_count(2, $rows);
    assert_eq($open['id'], $rows[0]['id'], 'open requests come first');
    assert_count(1, list_data_requests(['kind' => 'Export']));
    assert_count(1, list_data_requests(['status' => 'Open']));
    assert_count(1, list_data_requests(['q' => $done['reference']]));
    assert_count(1, list_data_requests(['q' => get_patient($b)['op_number']]));
    assert_count(0, list_data_requests(['q' => '%']), 'a percent sign is searched for, not used as a wildcard');
    assert_count(2, list_data_requests(['kind' => 'nonsense', 'status' => 'nonsense']), 'unknown filters are ignored, not trusted');
    assert_count(1, patient_data_requests($a));
    $impact = erasure_impact($a);
    assert_same(0, $impact['visits']);
    assert_same(0, $impact['payments']);
});
