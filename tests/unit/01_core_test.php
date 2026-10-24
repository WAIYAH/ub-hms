<?php
declare(strict_types=1);

suite('Core helpers and security primitives');

test('UT-COR-001', 'e() escapes HTML so injected markup is shown as text', function () {
    assert_same('&lt;script&gt;alert(1)&lt;/script&gt;', e('<script>alert(1)</script>'));
    assert_same('&quot;a&quot; &amp; &#039;b&#039;', e('"a" & \'b\''));
});

test('UT-COR-002', 'normalize_phone accepts the common Kenyan formats', function () {
    $cases = [
        '0712345678'    => '+254712345678',
        '0712 345 678'  => '+254712345678',
        '0712-345-678'  => '+254712345678',
        '+254712345678' => '+254712345678',
        '254712345678'  => '+254712345678',
        '712345678'     => '+254712345678',
        '0112345678'    => '+254112345678',
    ];
    foreach ($cases as $raw => $expected) {
        assert_same($expected, normalize_phone((string) $raw), "format $raw");
    }
});

test('UT-COR-003', 'normalize_phone rejects numbers that are not Kenyan mobiles', function () {
    foreach (['12345', '0212345678', '+1555123456', 'abcdefghij', '071234567', '07123456789'] as $raw) {
        assert_null(normalize_phone($raw), "should reject $raw");
    }
});

test('UT-COR-004', 'is_valid_date rejects impossible dates', function () {
    assert_true(is_valid_date('2026-02-28'));
    assert_false(is_valid_date('2026-02-30'));
    assert_false(is_valid_date('31/12/2026'));
    assert_false(is_valid_date(''));
});

test('UT-COR-005', 'clean_text removes control characters and extra spaces and limits length', function () {
    assert_same('Jane Doe', clean_text("  Jane \x00 \t Doe  "));
    assert_same('abc', clean_text('abcdef', 3));
    assert_same('', clean_text(['not', 'a', 'string']));
});

test('UT-COR-006', 'to_cents avoids floating point errors in money maths', function () {
    assert_same(12345, to_cents('123.45'));
    assert_same(1999, to_cents(19.99));
    assert_same(10, to_cents('0.10'));
    assert_same('KSh 1,500.50', fmt_money('1500.5'));
});

test('UT-COR-007', 'age_on counts whole years correctly', function () {
    assert_same(36, age_on('1990-05-15', '2026-05-15'), 'birthday today');
    assert_same(35, age_on('1990-05-15', '2026-05-14'), 'birthday tomorrow');
    assert_same(0, age_on('2026-01-01', '2026-06-01'));
});

test('UT-COR-008', 'password_problems enforces the password policy', function () {
    assert_count(0, password_problems('Strong#Pass123', 'susan'));
    assert_true(count(password_problems('short1A')) > 0, 'too short');
    assert_true(count(password_problems('alllowercase123')) > 0, 'needs upper case');
    assert_true(count(password_problems('ALLUPPERCASE123')) > 0, 'needs lower case');
    assert_true(count(password_problems('NoDigitsHereAtAll')) > 0, 'needs a digit');
    assert_true(count(password_problems('Susan#Pass12345', 'susan')) > 0, 'must not contain the username');
});

test('UT-COR-009', 'generated temporary passwords always satisfy the policy', function () {
    for ($i = 0; $i < 50; $i++) {
        assert_count(0, password_problems(generate_temp_password()));
    }
});

test('UT-COR-010', 'CSRF tokens are random, stable within a session and verified in constant time', function () {
    $_SESSION = [];
    $t1 = csrf_token();
    assert_same(64, strlen($t1));
    assert_same($t1, csrf_token());
    assert_true(csrf_valid($t1));
    assert_false(csrf_valid('wrong'));
    assert_false(csrf_valid(null));
    assert_false(csrf_valid(''));
    assert_contains('name="_csrf"', csrf_field());
});

test('UT-COR-011', 'every permission belongs to at least one role and every granted permission exists', function () {
    $catalogue = array_keys(permission_catalogue());
    $granted = [];
    foreach (permission_matrix() as $role => $perms) {
        assert_true(array_key_exists($role, role_labels()), "role $role has a label");
        foreach ($perms as $p) {
            assert_true(in_array($p, $catalogue, true), "permission $p is described");
            $granted[$p] = true;
        }
    }
    foreach ($catalogue as $p) {
        assert_true(isset($granted[$p]), "permission $p is given to some role");
    }
    assert_count(8, role_labels());
});

test('UT-COR-012', 'least privilege: roles cannot do other departments\' work', function () {
    assert_false(role_can('admin', 'patients.view'), 'administrator has no clinical access');
    assert_false(role_can('cashier', 'patients.view'));
    assert_false(role_can('lab', 'consult.record'));
    assert_false(role_can('nurse', 'consult.record'));
    assert_false(role_can('receptionist', 'billing.pay'));
    assert_false(role_can('pharmacist', 'lab.result'));
    assert_false(role_can('doctor', 'pharmacy.dispense'));
    assert_true(role_can('doctor', 'consult.record'));
    assert_true(role_can('cashier', 'billing.pay'));
    assert_false(role_can('unknown-role', 'patients.view'));
});

test('UT-COR-013', 'audit() writes who, what, which record and from which address', function () {
    $uid = make_user('nurse');
    audit('test_action', 'patient', 42, 'Some detail', $uid);
    $row = db_one('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1');
    assert_same('test_action', $row['action']);
    assert_same('patient', $row['entity']);
    assert_eq(42, $row['entity_id']);
    assert_eq($uid, $row['user_id']);
    assert_same('127.0.0.1', $row['ip_address']);
    assert_not_null($row['created_at']);
});

test('UT-COR-014', 'db_tx rolls back every change when something fails', function () {
    $uid = make_user('receptionist');
    assert_throws(function () use ($uid) {
        db_tx(function () use ($uid) {
            db_exec("INSERT INTO op_sequences (year, last_number) VALUES (2030, 5)");
            throw new RuntimeException('boom');
        });
    });
    assert_eq(0, db_value('SELECT COUNT(*) FROM op_sequences WHERE year = 2030'));
});

test('UT-COR-015', 'prepared statements keep SQL injection text as plain data', function () {
    $attack = "x'); DROP TABLE patients;--";
    $pid = make_patient(['residence' => $attack]);
    assert_eq(1, db_value('SELECT COUNT(*) FROM patients'), 'table still exists');
    assert_same($attack, db_value('SELECT residence FROM patients WHERE id = ?', [$pid]), 'stored as plain text');
    [$rows] = search_patients("' OR '1'='1");
    assert_count(0, $rows, 'a classic injection string matches nothing');
    assert_count(0, search_patients("Test'; DELETE FROM patients; --")[0]);
    assert_eq(1, db_value('SELECT COUNT(*) FROM patients'));
});
