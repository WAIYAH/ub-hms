<?php
declare(strict_types=1);

suite('Module 1: patient registration');

function valid_patient(array $o = []): array
{
    return $o + [
        'first_name' => 'Wanjiru', 'middle_name' => '', 'last_name' => 'Kamau', 'sex' => 'Female',
        'date_of_birth' => '1992-03-14', 'national_id' => '29876543', 'phone' => '0712 345 678',
        'residence' => 'Embakasi', 'allergies' => 'Penicillin',
        'next_of_kin_name' => 'John Kamau', 'next_of_kin_relation' => 'Brother', 'next_of_kin_phone' => '0722 000 111',
    ];
}

test('UT-PAT-001', 'registration issues a unique outpatient number in the form OPyy-nnnnn', function () {
    $u = make_user('receptionist');
    freeze_time('2026-10-05 09:00:00');
    $r = register_patient(valid_patient(), $u);
    assert_true($r['ok']);
    assert_same('OP26-00001', $r['op_number']);
    assert_same('OP26-00001', db_value('SELECT op_number FROM patients WHERE id = ?', [$r['id']]));
});

test('UT-PAT-002', 'outpatient numbers run in sequence and are never reused', function () {
    $u = make_user('receptionist');
    freeze_time('2026-10-05 09:00:00');
    $numbers = [];
    foreach (['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo'] as $i => $first) {
        $numbers[] = register_patient(valid_patient(['national_id' => (string) (30000001 + $i), 'first_name' => $first]), $u)['op_number'];
    }
    assert_same(['OP26-00001', 'OP26-00002', 'OP26-00003', 'OP26-00004', 'OP26-00005'], $numbers);
    assert_eq(5, db_value('SELECT COUNT(DISTINCT op_number) FROM patients'));
});

test('UT-PAT-003', 'the serial restarts each year', function () {
    $u = make_user('receptionist');
    freeze_time('2026-12-31 23:00:00');
    assert_same('OP26-00001', register_patient(valid_patient(['national_id' => '31000001']), $u)['op_number']);
    freeze_time('2027-01-02 08:00:00');
    assert_same('OP27-00001', register_patient(valid_patient(['national_id' => '31000002', 'first_name' => 'Next']), $u)['op_number']);
});

test('UT-PAT-004', 'a failed registration does not use up an outpatient number', function () {
    $u = make_user('receptionist');
    freeze_time('2026-10-05 09:00:00');
    register_patient(valid_patient(['last_name' => '']), $u);
    assert_same('OP26-00001', register_patient(valid_patient(), $u)['op_number']);
});

test('UT-PAT-005', 'required fields are checked', function () {
    $u = make_user('receptionist');
    $r = register_patient(['first_name' => '', 'last_name' => '', 'sex' => '', 'date_of_birth' => ''], $u);
    assert_false($r['ok']);
    foreach (['first_name', 'last_name', 'sex', 'date_of_birth'] as $field) {
        assert_has_key($field, $r['errors']);
    }
    assert_eq(0, db_value('SELECT COUNT(*) FROM patients'));
});

test('UT-PAT-006', 'future and impossible dates of birth are refused', function () {
    $u = make_user('receptionist');
    freeze_time('2026-10-05 09:00:00');
    assert_has_key('date_of_birth', register_patient(valid_patient(['date_of_birth' => '2026-10-06']), $u)['errors']);
    assert_has_key('date_of_birth', register_patient(valid_patient(['date_of_birth' => '1890-01-01']), $u)['errors']);
    assert_has_key('date_of_birth', register_patient(valid_patient(['date_of_birth' => '2020-02-31']), $u)['errors']);
    assert_true(register_patient(valid_patient(['date_of_birth' => '2026-10-05', 'national_id' => '']), $u)['ok'], 'born today is allowed');
});

test('UT-PAT-007', 'phone numbers are validated and stored in one format', function () {
    $u = make_user('receptionist');
    assert_has_key('phone', register_patient(valid_patient(['phone' => '12345']), $u)['errors']);
    $r = register_patient(valid_patient(['phone' => '0712 345 678']), $u);
    assert_same('+254712345678', db_value('SELECT phone FROM patients WHERE id = ?', [$r['id']]));
});

test('UT-PAT-008', 'names reject digits and symbols but accept apostrophes and hyphens', function () {
    $u = make_user('receptionist');
    assert_has_key('first_name', register_patient(valid_patient(['first_name' => 'Jane123']), $u)['errors']);
    assert_has_key('last_name', register_patient(valid_patient(['last_name' => '<b>x</b>']), $u)['errors']);
    assert_true(register_patient(valid_patient(['last_name' => "O'Connor-Mwangi", 'national_id' => '']), $u)['ok']);
});

test('UT-PAT-009', 'the same ID number cannot be registered twice', function () {
    $u = make_user('receptionist');
    $first = register_patient(valid_patient(), $u);
    $second = register_patient(valid_patient(['first_name' => 'Different', 'last_name' => 'Person']), $u, true);
    assert_false($second['ok'], 'confirmation does not override an ID clash');
    assert_has_key('national_id', $second['errors']);
    assert_contains($first['op_number'], $second['errors']['national_id']);
    assert_eq(1, db_value('SELECT COUNT(*) FROM patients'));
});

test('UT-PAT-010', 'same name and date of birth is flagged and needs confirmation', function () {
    $u = make_user('receptionist');
    register_patient(valid_patient(), $u);
    $again = register_patient(valid_patient(['national_id' => '']), $u);
    assert_false($again['ok']);
    assert_true($again['needs_confirmation']);
    assert_count(1, $again['duplicates']['similar']);
    assert_eq(1, db_value('SELECT COUNT(*) FROM patients'));
    $confirmed = register_patient(valid_patient(['national_id' => '']), $u, true);
    assert_true($confirmed['ok'], 'registers once staff confirm it is a different person');
    assert_eq(2, db_value('SELECT COUNT(*) FROM patients'));
});

test('UT-PAT-011', 'a patient can have no ID number (children) without clashing with other children', function () {
    $u = make_user('receptionist');
    assert_true(register_patient(valid_patient(['national_id' => '', 'first_name' => 'Amani', 'date_of_birth' => '2021-01-01']), $u)['ok']);
    assert_true(register_patient(valid_patient(['national_id' => '', 'first_name' => 'Baraka', 'date_of_birth' => '2022-02-02']), $u)['ok']);
});

test('UT-PAT-012', 'registering writes an audit entry without health details', function () {
    $u = make_user('receptionist');
    $r = register_patient(valid_patient(), $u);
    $row = db_one("SELECT * FROM audit_log WHERE action = 'create' AND entity = 'patient'");
    assert_eq($r['id'], $row['entity_id']);
    assert_eq($u, $row['user_id']);
    assert_not_contains('Penicillin', (string) $row['details'], 'allergy text is not copied into the log');
});

test('UT-PAT-013', 'correcting details keeps the outpatient number and logs the field names only', function () {
    $u = make_user('receptionist');
    $r = register_patient(valid_patient(), $u);
    $res = update_patient($r['id'], valid_patient(['phone' => '0733 111 222', 'residence' => 'Ruaka']), $u);
    assert_true($res['ok']);
    $p = get_patient($r['id']);
    assert_same($r['op_number'], $p['op_number']);
    assert_same('+254733111222', $p['phone']);
    $detail = (string) db_value("SELECT details FROM audit_log WHERE action = 'update' AND entity = 'patient'");
    assert_contains('phone', $detail);
    assert_contains('residence', $detail);
    assert_not_contains('Ruaka', $detail);
});

test('UT-PAT-014', 'a correction cannot take another patient\'s ID number', function () {
    $u = make_user('receptionist');
    register_patient(valid_patient(['national_id' => '40000001']), $u);
    $b = register_patient(valid_patient(['first_name' => 'Other', 'national_id' => '40000002']), $u);
    $res = update_patient($b['id'], valid_patient(['first_name' => 'Other', 'national_id' => '40000001']), $u);
    assert_false($res['ok']);
    assert_has_key('national_id', $res['errors']);
});

test('UT-PAT-015', 'search finds patients by outpatient number, name, ID number and phone', function () {
    $u = make_user('receptionist');
    freeze_time('2026-10-05 09:00:00');
    $a = register_patient(valid_patient(), $u);
    register_patient(valid_patient(['first_name' => 'Otieno', 'last_name' => 'Ouma', 'national_id' => '30111222', 'phone' => '0701 222 333']), $u);
    assert_eq($a['id'], search_patients('OP26-00001')[0][0]['id']);
    assert_eq($a['id'], search_patients('op26-1')[0][0]['id'], 'short serial and lower case');
    assert_count(1, search_patients('Wanj')[0], 'name prefix');
    assert_count(1, search_patients('kamau wanjiru')[0], 'two name parts in any order');
    assert_count(1, search_patients('29876543')[0], 'ID number');
    assert_count(1, search_patients('0701 222 333')[0], 'phone number');
    assert_count(0, search_patients('Nobody')[0]);
    assert_eq(2, search_patients('')[1], 'empty search lists everyone');
});

test('UT-PAT-016', 'search treats % and _ as ordinary characters', function () {
    $u = make_user('receptionist');
    register_patient(valid_patient(), $u);
    assert_count(0, search_patients('%')[0]);
    assert_count(0, search_patients('W_nj')[0]);
});

test('UT-PAT-017', 'search is paged', function () {
    $u = make_user('receptionist');
    foreach (['Adams', 'Brown', 'Clarke', 'Dawson', 'Evans', 'Foster', 'Grant'] as $i => $last) {
        register_patient(valid_patient(['first_name' => 'Person', 'last_name' => $last, 'national_id' => (string) (50000001 + $i)]), $u, true);
    }
    [$rows, $total] = search_patients('Person', 3, 0);
    assert_count(3, $rows);
    assert_eq(7, $total);
    assert_count(1, search_patients('Person', 3, 6)[0]);
});

test('UT-PAT-018', 'CSV preview classifies rows and saves nothing', function () {
    $u = make_user('receptionist');
    register_patient(valid_patient(), $u);
    $csv = "first_name,last_name,sex,date_of_birth,national_id,phone\n"
         . "Akinyi,Odhiambo,Female,12/04/1988,31000111,0700 111 222\n"      // valid, dd/mm/yyyy
         . "Wanjiru,Kamau,Female,1992-03-14,,\n"                              // same name + dob as existing
         . "Bad,,Male,1990-01-01,,\n"                                         // missing last name
         . "Kip,Rono,Male,1980-01-01,29876543,\n";                            // existing ID number
    $file = sys_get_temp_dir() . '/ubhms_import_' . uniqid() . '.csv';
    file_put_contents($file, $csv);
    $p = preview_patient_import($file);
    unlink($file);
    assert_null($p['error']);
    assert_eq(1, $p['valid']);
    assert_same(['Valid', 'Duplicate', 'Error', 'Duplicate'], array_column($p['rows'], 'status'));
    assert_eq(1, db_value('SELECT COUNT(*) FROM patients'), 'preview saves nothing');
});

test('UT-PAT-019', 'CSV import registers only the valid rows and logs it', function () {
    $u = make_user('receptionist');
    freeze_time('2026-10-05 09:00:00');
    $csv = "first_name,last_name,sex,date_of_birth\nAkinyi,Odhiambo,female,1988-04-12\nJuma,Mwangi,Male,1975-09-30\n,Missing,Male,1990-01-01\n";
    $file = sys_get_temp_dir() . '/ubhms_import_' . uniqid() . '.csv';
    file_put_contents($file, $csv);
    assert_eq(2, commit_patient_import($file, $u));
    unlink($file);
    assert_eq(2, db_value('SELECT COUNT(*) FROM patients'));
    assert_eq(1, db_value("SELECT COUNT(*) FROM audit_log WHERE action = 'import'"));
});

test('UT-PAT-020', 'a CSV without the required columns is rejected', function () {
    $file = sys_get_temp_dir() . '/ubhms_import_' . uniqid() . '.csv';
    file_put_contents($file, "name,age\nJane,30\n");
    $p = preview_patient_import($file);
    unlink($file);
    assert_not_null($p['error']);
    assert_contains('first_name', $p['error']);
});
