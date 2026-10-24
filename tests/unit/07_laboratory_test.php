<?php
declare(strict_types=1);

suite('Module 3: laboratory');

/** An open visit with a recorded consultation. Returns [visitId, doctorId, patientId, receptionistId]. */
function consulted_visit(?int $doctorId = null): array
{
    [$rec, , $doc, $pat] = clinic_setup();
    $doc = $doctorId ?? $doc;
    $vid = open_visit($pat, $rec);
    save_consultation($vid, good_consultation(), $doc);
    return [$vid, $doc, $pat, $rec];
}

test('UT-LAB-001', 'tests are requested by the doctor and linked to the visit and the doctor', function () {
    [$vid, $doc] = consulted_visit();
    $r = request_lab_tests($vid, [lab_test_id('Malaria test (BS/MPS)'), lab_test_id('Full haemogram')], 'Fever 3 days', $doc);
    assert_true($r['ok']);
    assert_eq(2, $r['count']);
    $rows = visit_lab_requests($vid);
    assert_count(2, $rows);
    foreach ($rows as $row) {
        assert_eq($doc, $row['requested_by']);
        assert_same('Requested', $row['status']);
        assert_same('Fever 3 days', $row['clinical_notes']);
    }
    assert_eq(2, db_value("SELECT COUNT(*) FROM audit_log WHERE entity = 'lab_request' AND action = 'create'"));
});

test('UT-LAB-002', 'tests need an open visit with a consultation and at least one test', function () {
    [$rec, , $doc, $pat] = clinic_setup();
    $vid = open_visit($pat, $rec);
    $t = lab_test_id('Urinalysis');
    assert_contains('Record the consultation', request_lab_tests($vid, [$t], '', $doc)['error']);
    save_consultation($vid, good_consultation(), $doc);
    assert_contains('at least one test', request_lab_tests($vid, [], '', $doc)['error']);
    close_visit($vid, $doc);
    assert_contains('open visit', request_lab_tests($vid, [$t], '', $doc)['error']);
});

test('UT-LAB-003', 'a pending test cannot be requested twice but can be repeated after it is completed', function () {
    [$vid, $doc] = consulted_visit();
    $lab = make_user('lab');
    $t = lab_test_id('Random blood sugar');
    assert_true(request_lab_tests_safe($vid, [$t], '', $doc)['ok']);
    $dup = request_lab_tests_safe($vid, [$t], '', $doc);
    assert_false($dup['ok']);
    assert_contains('already been requested', $dup['error']);
    $id = (int) visit_lab_requests($vid)[0]['id'];
    record_lab_result($id, '5.4 mmol/L', false, $lab);
    assert_true(request_lab_tests_safe($vid, [$t], '', $doc)['ok'], 'repeat after completion');
    assert_count(2, visit_lab_requests($vid));
});

test('UT-LAB-004', 'an unknown test in the list stops the whole request (nothing half-saved)', function () {
    [$vid, $doc] = consulted_visit();
    $r = request_lab_tests_safe($vid, [lab_test_id('Urinalysis'), 99999], '', $doc);
    assert_false($r['ok']);
    assert_count(0, visit_lab_requests($vid));
    db_exec("UPDATE lab_tests SET is_active = 0 WHERE name = 'Widal test'");
    assert_false(request_lab_tests_safe($vid, [lab_test_id('Widal test')], '', $doc)['ok'], 'inactive test');
});

test('UT-LAB-005', 'the worklist shows open requests oldest first with patient and requesting doctor', function () {
    [$vid, $doc, $pat, $rec] = consulted_visit();
    request_lab_tests($vid, [lab_test_id('Urinalysis')], '', $doc);
    $p2 = make_patient(['national_id' => '55000001', 'first_name' => 'Later'], $rec);
    $v2 = open_visit($p2, $rec);
    save_consultation($v2, good_consultation(), $doc);
    request_lab_tests($v2, [lab_test_id('Full haemogram')], '', $doc);
    $list = lab_worklist();
    assert_count(2, $list);
    assert_same('Urinalysis', $list[0]['test_name']);
    assert_same('Full haemogram', $list[1]['test_name']);
    assert_eq($doc, $list[0]['requested_by']);
    assert_true(strlen($list[0]['op_number']) === 10);
    assert_count(0, lab_worklist(['Completed']));
});

test('UT-LAB-006', 'the laboratory starts a test and enters the result, which is recorded with who and when', function () {
    [$vid, $doc] = consulted_visit();
    $lab = make_user('lab');
    request_lab_tests($vid, [lab_test_id('Full haemogram')], '', $doc);
    $id = (int) visit_lab_requests($vid)[0]['id'];
    assert_null(start_lab_request($id, $lab));
    assert_same('In progress', get_lab_request($id)['status']);
    assert_not_null(start_lab_request($id, $lab), 'cannot start twice');
    assert_null(record_lab_result($id, "Hb 11.2 g/dL\nWBC 6.1", true, $lab));
    $r = get_lab_request($id);
    assert_same('Completed', $r['status']);
    assert_contains('Hb 11.2', $r['result']);
    assert_eq(1, $r['is_abnormal']);
    assert_eq($lab, $r['result_by']);
    assert_not_null($r['result_at']);
});

test('UT-LAB-007', 'a result can be entered without starting, but not empty and not for a cancelled request', function () {
    [$vid, $doc] = consulted_visit();
    $lab = make_user('lab');
    request_lab_tests($vid, [lab_test_id('Urinalysis'), lab_test_id('Pregnancy test')], '', $doc);
    [$a, $b] = array_map(fn ($r) => (int) $r['id'], visit_lab_requests($vid));
    assert_contains('Enter the result', (string) record_lab_result($a, '   ', null, $lab));
    assert_null(record_lab_result($a, 'Negative', null, $lab));
    assert_null(get_lab_request($a)['is_abnormal'], 'no flag when none was chosen');
    cancel_lab_request($b, $doc);
    assert_contains('cancelled', (string) record_lab_result($b, 'Negative', null, $lab));
});

test('UT-LAB-008', 'the result returns to the doctor who requested the test, not to other doctors', function () {
    [$vid, $docA, $pat, $rec] = consulted_visit();
    $docB = make_user('doctor');
    $lab = make_user('lab');
    request_lab_tests($vid, [lab_test_id('Malaria test (BS/MPS)')], '', $docA);
    $id = (int) visit_lab_requests($vid)[0]['id'];
    assert_eq(0, results_ready_count($docA), 'nothing ready before the result');
    record_lab_result($id, 'Malaria parasites seen', true, $lab);
    $mine = doctor_lab_requests($docA);
    assert_count(1, $mine);
    assert_same('Malaria parasites seen', $mine[0]['result']);
    assert_count(0, doctor_lab_requests($docB));
    assert_eq(1, results_ready_count($docA));
    assert_eq(0, results_ready_count($docB));
    assert_count(1, doctor_lab_requests($docA, 'Completed'));
    assert_count(0, doctor_lab_requests($docA, 'Requested'));
    close_visit($vid, $docA);
    assert_eq(0, results_ready_count($docA), 'no longer counted once the visit is closed');
});

test('UT-LAB-009', 'only the requesting doctor can cancel, and only before the laboratory starts', function () {
    [$vid, $docA] = consulted_visit();
    $docB = make_user('doctor');
    $lab = make_user('lab');
    request_lab_tests($vid, [lab_test_id('Urinalysis'), lab_test_id('Widal test')], '', $docA);
    [$a, $b] = array_map(fn ($r) => (int) $r['id'], visit_lab_requests($vid));
    assert_contains('Only the doctor who requested', (string) cancel_lab_request($a, $docB));
    start_lab_request($b, $lab);
    assert_contains('already started', (string) cancel_lab_request($b, $docA));
    assert_null(cancel_lab_request($a, $docA));
    assert_same('Cancelled', get_lab_request($a)['status']);
    assert_count(1, visit_lab_requests($vid), 'cancelled requests are hidden from the visit');
    assert_count(1, lab_worklist(), 'and from the laboratory worklist');
});

test('UT-LAB-010', 'a corrected result is audited as an amendment and the audit trail holds no result text', function () {
    [$vid, $doc] = consulted_visit();
    $lab = make_user('lab');
    request_lab_tests($vid, [lab_test_id('Random blood sugar')], '', $doc);
    $id = (int) visit_lab_requests($vid)[0]['id'];
    record_lab_result($id, '19.9 mmol/L secret-value', true, $lab);
    record_lab_result($id, '9.9 mmol/L', false, $lab);
    $actions = array_column(db_all("SELECT action FROM audit_log WHERE entity = 'lab_request' AND entity_id = ? ORDER BY id", [$id]), 'action');
    assert_same(['create', 'result', 'result_amended'], $actions);
    assert_eq(0, db_value("SELECT COUNT(*) FROM audit_log WHERE details LIKE '%secret-value%' OR details LIKE '%mmol%'"));
    assert_same('9.9 mmol/L', get_lab_request($id)['result']);
});

test('UT-LAB-011', 'the visit history shows tests with their results to a doctor', function () {
    [$vid, $doc, $pat, $rec] = consulted_visit();
    $lab = make_user('lab');
    request_lab_tests($vid, [lab_test_id('Urinalysis')], '', $doc);
    record_lab_result((int) visit_lab_requests($vid)[0]['id'], 'Protein trace', true, $lab);
    close_visit($vid, $doc);
    $hist = patient_history($pat);
    assert_same('Urinalysis', $hist[0]['tests'][0]['test_name']);
    assert_contains('Urinalysis (completed)', render_patient_history(get_patient($pat), ['role' => 'doctor']));
});

test('UT-LAB-012', 'the dashboard shows waiting tests to the laboratory and ready results to the doctor', function () {
    [$vid, $doc] = consulted_visit();
    $lab = make_user('lab');
    request_lab_tests($vid, [lab_test_id('Urinalysis')], '', $doc);
    sign_in_as($lab);
    $labTiles = implode(' ', dashboard_tiles_laboratory($_SESSION['user']));
    assert_true((bool) preg_match('#<div class="num">1</div><div class="label">Tests waiting#', $labTiles));
    record_lab_result((int) visit_lab_requests($vid)[0]['id'], 'Normal', false, $lab);
    sign_in_as($doc);
    $docTiles = implode(' ', dashboard_tiles_laboratory($_SESSION['user']));
    assert_true((bool) preg_match('#<div class="num">1</div><div class="label">Test results ready#', $docTiles));
});
