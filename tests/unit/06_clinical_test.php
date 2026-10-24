<?php
declare(strict_types=1);

suite('Module 2: visits, triage and consultation');

/** A receptionist, nurse, doctor and a patient, with the clock set to a Monday morning. */
function clinic_setup(): array
{
    freeze_time('2026-11-02 08:30:00');
    $rec = make_user('receptionist');
    $nurse = make_user('nurse');
    $doc = make_user('doctor');
    $pat = make_patient([], $rec);
    return [$rec, $nurse, $doc, $pat];
}

function open_visit(int $patient, int $by, ?int $doctor = null): int
{
    $r = check_in_patient($patient, null, $doctor, 'Cough', $by);
    if (!$r['ok']) {
        throw new RuntimeException($r['error']);
    }
    return $r['visit_id'];
}

function good_consultation(array $o = []): array
{
    return $o + ['service_id' => service_id('General consultation'), 'complaint' => 'Cough for three days', 'examination' => 'Chest clear', 'diagnosis' => 'Upper respiratory infection', 'icd10_code' => 'J06.9', 'plan' => 'Rest and fluids'];
}

test('UT-VIS-001', 'checking in from an appointment marks it Attended and assigns the doctor', function () {
    [$rec, , $doc, $pat] = clinic_setup();
    $appt = book_appointment(['patient_id' => $pat, 'doctor_id' => $doc, 'date' => '2026-11-02', 'start_time' => '09:00', 'reason' => 'Review'], $rec);
    $r = check_in_patient($pat, $appt['id'], null, '', $rec);
    assert_true($r['ok']);
    $v = get_visit($r['visit_id']);
    assert_same('Open', $v['status']);
    assert_eq($doc, $v['doctor_id']);
    assert_same('Review', $v['reason']);
    assert_same('Waiting for triage', visit_stage($v));
    assert_same('Attended', get_appointment($appt['id'])['status']);
    assert_eq($appt['id'], $v['appointment_id']);
});

test('UT-VIS-002', 'a walk-in can be checked in with or without a preferred doctor', function () {
    [$rec, , $doc, $pat] = clinic_setup();
    $v1 = get_visit(open_visit($pat, $rec));
    assert_null($v1['doctor_id']);
    close_open_visits();
    $v2 = get_visit(open_visit($pat, $rec, $doc));
    assert_eq($doc, $v2['doctor_id']);
    close_open_visits();
    $bad = check_in_patient($pat, null, make_user('nurse'), '', $rec);
    assert_false($bad['ok'], 'a nurse is not a valid preferred doctor');
});

function close_open_visits(): void
{
    db_exec("UPDATE visits SET status = 'Closed', closed_at = NOW() WHERE status = 'Open'");
}

test('UT-VIS-003', 'a patient cannot have two open visits', function () {
    [$rec, , , $pat] = clinic_setup();
    open_visit($pat, $rec);
    $again = check_in_patient($pat, null, null, '', $rec);
    assert_false($again['ok']);
    assert_contains('already has an open visit', $again['error']);
    assert_eq(1, db_value('SELECT COUNT(*) FROM visits'));
});

test('UT-VIS-004', 'check-in refuses another patient\'s, a cancelled, or a future appointment', function () {
    [$rec, , $doc, $pat] = clinic_setup();
    $other = make_patient(['national_id' => '66000001', 'first_name' => 'Other'], $rec);
    $mine = book_appointment(['patient_id' => $pat, 'doctor_id' => $doc, 'date' => '2026-11-02', 'start_time' => '10:00'], $rec)['id'];
    assert_false(check_in_patient($other, $mine, null, '', $rec)['ok'], "someone else's appointment");
    $later = book_appointment(['patient_id' => $pat, 'doctor_id' => $doc, 'date' => '2026-11-03', 'start_time' => '10:00'], $rec)['id'];
    $r = check_in_patient($pat, $later, null, '', $rec);
    assert_false($r['ok']);
    assert_contains('day of the appointment', $r['error']);
    set_appointment_status($mine, 'Cancelled', $rec);
    assert_false(check_in_patient($pat, $mine, null, '', $rec)['ok'], 'cancelled appointment');
    assert_eq(0, db_value('SELECT COUNT(*) FROM visits'));
});

test('UT-VIS-005', 'queues show each stage; a doctor sees own and unassigned patients only', function () {
    [$rec, $nurse, $doc, $pat] = clinic_setup();
    $doc2 = make_user('doctor');
    $p2 = make_patient(['national_id' => '66000002', 'first_name' => 'Second'], $rec);
    $p3 = make_patient(['national_id' => '66000003', 'first_name' => 'Third'], $rec);
    $v1 = open_visit($pat, $rec, $doc);
    $v2 = open_visit($p2, $rec, $doc2);
    $v3 = open_visit($p3, $rec);
    assert_count(3, visit_queue('triage'));
    save_triage($v1, ['temperature' => '37.2'], $nurse);
    save_triage($v2, ['temperature' => '36.8'], $nurse);
    assert_count(1, visit_queue('triage'));
    $mine = array_column(visit_queue('consult', $doc), 'id');
    assert_same([$v1], array_map('intval', $mine), 'doctor 1 sees only own triaged patient; v3 is not triaged yet');
    save_triage($v3, ['temperature' => '37.0'], $nurse);
    assert_count(2, visit_queue('consult', $doc), 'own plus unassigned');
    assert_count(3, visit_queue('consult'));
    assert_count(3, visit_queue(''));
});

test('UT-TRI-001', 'valid vital signs are saved and move the patient to the doctor queue', function () {
    [$rec, $nurse, , $pat] = clinic_setup();
    $vid = open_visit($pat, $rec);
    $r = save_triage($vid, ['temperature' => '38.4', 'pulse' => '96', 'bp_systolic' => '128', 'bp_diastolic' => '82', 'resp_rate' => '18', 'spo2' => '97', 'weight_kg' => '64.5', 'height_cm' => '165', 'notes' => 'Dry cough'], $nurse);
    assert_true($r['ok']);
    $t = get_triage($vid);
    assert_eq(38.4, $t['temperature']);
    assert_eq(96, $t['pulse']);
    assert_eq(64.5, $t['weight_kg']);
    assert_eq($nurse, $t['recorded_by']);
    assert_same('Waiting for doctor', visit_stage(get_visit($vid)));
});

test('UT-TRI-002', 'implausible or non-numeric vital signs are refused with a message for each field', function () {
    [$rec, $nurse, , $pat] = clinic_setup();
    $vid = open_visit($pat, $rec);
    $r = save_triage($vid, ['temperature' => '50', 'pulse' => '10', 'spo2' => '120', 'weight_kg' => 'heavy', 'resp_rate' => '18.5'], $nurse);
    assert_false($r['ok']);
    foreach (['temperature', 'pulse', 'spo2', 'weight_kg', 'resp_rate'] as $f) {
        assert_has_key($f, $r['errors']);
    }
    assert_null(get_triage($vid));
});

test('UT-TRI-003', 'blood pressure needs both values with the upper higher than the lower', function () {
    [$rec, $nurse, , $pat] = clinic_setup();
    $vid = open_visit($pat, $rec);
    assert_has_key('bp_systolic', save_triage($vid, ['bp_systolic' => '120'], $nurse)['errors']);
    assert_has_key('bp_systolic', save_triage($vid, ['bp_systolic' => '80', 'bp_diastolic' => '120'], $nurse)['errors']);
    assert_true(save_triage($vid, ['bp_systolic' => '120', 'bp_diastolic' => '80'], $nurse)['ok']);
});

test('UT-TRI-004', 'an empty triage form is refused but a note alone is enough', function () {
    [$rec, $nurse, , $pat] = clinic_setup();
    $vid = open_visit($pat, $rec);
    assert_false(save_triage($vid, [], $nurse)['ok']);
    assert_true(save_triage($vid, ['notes' => 'Patient unable to stand; weight not taken'], $nurse)['ok']);
});

test('UT-TRI-005', 'correcting triage updates the same record and is audited', function () {
    [$rec, $nurse, , $pat] = clinic_setup();
    $vid = open_visit($pat, $rec);
    save_triage($vid, ['temperature' => '37.0'], $nurse);
    save_triage($vid, ['temperature' => '38.0'], $nurse);
    assert_eq(1, db_value('SELECT COUNT(*) FROM triage WHERE visit_id = ?', [$vid]));
    assert_eq(38.0, get_triage($vid)['temperature']);
    assert_eq(1, db_value("SELECT COUNT(*) FROM audit_log WHERE entity = 'triage' AND action = 'update'"));
});

test('UT-TRI-006', 'triage cannot be recorded on a closed visit', function () {
    [$rec, $nurse, , $pat] = clinic_setup();
    $vid = open_visit($pat, $rec);
    close_open_visits();
    assert_false(save_triage($vid, ['temperature' => '37.0'], $nurse)['ok']);
});

test('UT-CON-001', 'a consultation is saved, assigns the doctor, moves the stage and is audited', function () {
    [$rec, $nurse, $doc, $pat] = clinic_setup();
    $vid = open_visit($pat, $rec);
    save_triage($vid, ['temperature' => '37.5'], $nurse);
    $r = save_consultation($vid, good_consultation(), $doc);
    assert_true($r['ok']);
    $c = get_consultation($vid);
    assert_same('Upper respiratory infection', $c['diagnosis']);
    assert_same('J06.9', $c['icd10_code']);
    assert_same('Acute upper respiratory infection, unspecified', $c['icd10_description']);
    assert_same('General consultation', $c['service_name']);
    assert_eq($doc, get_visit($vid)['doctor_id']);
    assert_same('With doctor', visit_stage(get_visit($vid)));
    assert_eq(1, db_value("SELECT COUNT(*) FROM audit_log WHERE entity = 'consultation' AND action = 'create' AND user_id = ?", [$doc]));
});

test('UT-CON-002', 'complaint, diagnosis and consultation type are required; ICD-10 is optional but validated', function () {
    [$rec, , $doc, $pat] = clinic_setup();
    $vid = open_visit($pat, $rec);
    $r = save_consultation($vid, ['complaint' => '', 'diagnosis' => '', 'service_id' => 0], $doc);
    foreach (['complaint', 'diagnosis', 'service_id'] as $f) {
        assert_has_key($f, $r['errors']);
    }
    assert_has_key('icd10_code', save_consultation($vid, good_consultation(['icd10_code' => 'ZZZ.99']), $doc)['errors']);
    assert_true(save_consultation($vid, good_consultation(['icd10_code' => '']), $doc)['ok'], 'free-text diagnosis alone is allowed');
    assert_null(get_consultation($vid)['icd10_code']);
    assert_true(save_consultation($vid, good_consultation(['icd10_code' => 'j06.9']), $doc)['ok'], 'code is case-insensitive');
});

test('UT-CON-003', 'only the doctor who recorded a consultation can change it', function () {
    [$rec, , $doc, $pat] = clinic_setup();
    $doc2 = make_user('doctor');
    $vid = open_visit($pat, $rec);
    save_consultation($vid, good_consultation(), $doc);
    $r = save_consultation($vid, good_consultation(['diagnosis' => 'Changed']), $doc2);
    assert_false($r['ok']);
    assert_contains('only that doctor', $r['errors']['complaint']);
    assert_same('Upper respiratory infection', get_consultation($vid)['diagnosis']);
    assert_true(save_consultation($vid, good_consultation(['diagnosis' => 'Corrected']), $doc)['ok']);
});

test('UT-CON-004', 'a visit can be closed only after a consultation, and a closed visit is locked', function () {
    [$rec, $nurse, $doc, $pat] = clinic_setup();
    $vid = open_visit($pat, $rec);
    assert_contains('Record the consultation', (string) close_visit($vid, $doc));
    save_consultation($vid, good_consultation(), $doc);
    assert_null(close_visit($vid, $doc));
    assert_same('Closed', visit_stage(get_visit($vid)));
    assert_not_null(close_visit($vid, $doc), 'cannot close twice');
    assert_false(save_consultation($vid, good_consultation(['diagnosis' => 'Late edit']), $doc)['ok']);
    assert_false(save_triage($vid, ['temperature' => '37'], $nurse)['ok']);
    assert_not_null(record_visit_service($vid, service_id('Wound dressing'), 1, $nurse));
    assert_true(check_in_patient($pat, null, null, '', $rec)['ok'], 'a new visit can start once the old one is closed');
});

test('UT-CON-005', 'procedures are recorded with quantity checks and listed', function () {
    [$rec, $nurse, , $pat] = clinic_setup();
    $vid = open_visit($pat, $rec);
    assert_null(record_visit_service($vid, service_id('Wound dressing'), 2, $nurse));
    assert_not_null(record_visit_service($vid, service_id('General consultation'), 1, $nurse), 'a consultation fee is not a procedure');
    assert_not_null(record_visit_service($vid, service_id('Wound dressing'), 0, $nurse));
    assert_not_null(record_visit_service($vid, service_id('Wound dressing'), 21, $nurse));
    assert_not_null(record_visit_service($vid, 999, 1, $nurse));
    $list = visit_services_list($vid);
    assert_count(1, $list);
    assert_same('Wound dressing', $list[0]['name']);
    assert_eq(2, $list[0]['quantity']);
});

test('UT-CON-006', 'earlier visits are listed newest first and exclude the current visit', function () {
    [$rec, $nurse, $doc, $pat] = clinic_setup();
    $v1 = open_visit($pat, $rec);
    save_consultation($v1, good_consultation(['diagnosis' => 'First illness']), $doc);
    close_visit($v1, $doc);
    freeze_time('2026-11-09 09:00:00');
    $v2 = open_visit($pat, $rec);
    save_consultation($v2, good_consultation(['diagnosis' => 'Second illness']), $doc);
    close_visit($v2, $doc);
    freeze_time('2026-11-16 09:00:00');
    $v3 = open_visit($pat, $rec);
    $hist = patient_history($pat, $v3);
    assert_count(2, $hist);
    assert_same('Second illness', $hist[0]['diagnosis']);
    assert_same('First illness', $hist[1]['diagnosis']);
    assert_count(1, patient_history($pat, $v3, 1), 'limit respected');
    assert_count(2, patient_history($pat, $v2));
});

test('UT-CON-007', 'the visit history shows diagnoses to doctors but not to other roles', function () {
    [$rec, , $doc, $pat] = clinic_setup();
    $vid = open_visit($pat, $rec);
    save_consultation($vid, good_consultation(['diagnosis' => 'Confidential diagnosis']), $doc);
    close_visit($vid, $doc);
    $p = get_patient($pat);
    $asDoctor = render_patient_history($p, ['role' => 'doctor']);
    $asReceptionist = render_patient_history($p, ['role' => 'receptionist']);
    assert_contains('Confidential diagnosis', $asDoctor);
    assert_not_contains('Confidential diagnosis', $asReceptionist);
    assert_contains('Visits', $asReceptionist);
    assert_same('', render_patient_history($p, ['role' => 'cashier']), 'cashier sees no clinical history');
});

test('UT-CON-008', 'ICD-10 lookup finds codes by code or by words', function () {
    clinic_setup();
    assert_same('B50.9', icd10_lookup('B50')[0]['code']);
    assert_true(count(icd10_lookup('malaria')) >= 2);
    assert_count(0, icd10_lookup('zzzzzz'));
    assert_count(0, icd10_lookup('%'), '% is not a wildcard');
});

test('UT-CON-009', 'the dashboard counts waiting patients for nurses and doctors', function () {
    [$rec, $nurse, $doc, $pat] = clinic_setup();
    open_visit($pat, $rec);
    sign_in_as($nurse);
    $tiles = implode(' ', dashboard_tiles($_SESSION['user']));
    assert_contains('Waiting for triage', $tiles);
    assert_true((bool) preg_match('#<div class="num">1</div><div class="label">Waiting for triage#', $tiles), 'shows 1 waiting');
});
