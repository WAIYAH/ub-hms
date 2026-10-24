<?php
declare(strict_types=1);

suite('Module 6: authentication and account security');

test('UT-AUT-001', 'correct username and password signs in and records the time', function () {
    $id = make_user('doctor', 'dr.test');
    $r = attempt_login('dr.test', TEST_PASSWORD, '10.0.0.5');
    assert_true($r['ok']);
    assert_eq($id, $r['user']['id']);
    assert_same('doctor', $r['user']['role']);
    assert_not_null(db_value('SELECT last_login_at FROM users WHERE id = ?', [$id]));
});

test('UT-AUT-002', 'username is case-insensitive but the password is not', function () {
    make_user('doctor', 'dr.case');
    assert_true(attempt_login('  DR.Case ', TEST_PASSWORD, '10.0.0.5')['ok']);
    assert_false(attempt_login('dr.case', strtoupper(TEST_PASSWORD), '10.0.0.5')['ok']);
});

test('UT-AUT-003', 'wrong password and unknown username give the same message', function () {
    make_user('doctor', 'dr.real');
    $wrong = attempt_login('dr.real', 'Wrong#Pass123', '10.0.0.6');
    $unknown = attempt_login('nobody.here', 'Wrong#Pass123', '10.0.0.6');
    assert_false($wrong['ok']);
    assert_false($unknown['ok']);
    assert_same($wrong['error'], $unknown['error'], 'no hint about which part was wrong');
});

test('UT-AUT-004', 'an account locks after five wrong passwords, even for the right password', function () {
    $id = make_user('nurse', 'nurse.lock');
    for ($i = 1; $i <= 5; $i++) {
        attempt_login('nurse.lock', 'Bad#Password' . $i, '10.0.0.7');
    }
    assert_eq(5, db_value('SELECT failed_attempts FROM users WHERE id = ?', [$id]));
    $r = attempt_login('nurse.lock', TEST_PASSWORD, '10.0.0.8');
    assert_false($r['ok'], 'locked account refuses the correct password');
    assert_contains('locked', $r['error']);
});

test('UT-AUT-005', 'unlocking restores access and a good sign-in resets the failure counter', function () {
    $admin = make_user('admin', 'admin.u');
    $id = make_user('nurse', 'nurse.unlock');
    for ($i = 0; $i < 5; $i++) {
        attempt_login('nurse.unlock', 'Bad#Password1', '10.0.0.9');
    }
    unlock_user($id, $admin);
    assert_true(attempt_login('nurse.unlock', TEST_PASSWORD, '10.0.0.9')['ok']);
    assert_eq(0, db_value('SELECT failed_attempts FROM users WHERE id = ?', [$id]));
    assert_null(db_value('SELECT locked_until FROM users WHERE id = ?', [$id]));
});

test('UT-AUT-006', 'a deactivated account cannot sign in', function () {
    make_user('cashier', 'cash.off', ['is_active' => 0]);
    $r = attempt_login('cash.off', TEST_PASSWORD, '10.0.0.10');
    assert_false($r['ok']);
    assert_contains('deactivated', $r['error']);
});

test('UT-AUT-007', 'too many failures from one computer blocks that computer', function () {
    make_user('doctor', 'dr.ip');
    for ($i = 0; $i < 20; $i++) {
        attempt_login('ghost' . $i, 'Bad#Password1', '10.9.9.9');
    }
    $r = attempt_login('dr.ip', TEST_PASSWORD, '10.9.9.9');
    assert_false($r['ok'], 'even a correct login is refused from the blocked computer');
    assert_true(attempt_login('dr.ip', TEST_PASSWORD, '10.1.1.1')['ok'], 'other computers are unaffected');
});

test('UT-AUT-008', 'every failed and successful attempt can be traced in the audit trail', function () {
    $id = make_user('doctor', 'dr.audit');
    attempt_login('dr.audit', 'Bad#Password1', '10.0.0.11');
    attempt_login('nobody', 'Bad#Password1', '10.0.0.11');
    assert_eq(2, db_value("SELECT COUNT(*) FROM audit_log WHERE action = 'login_failed'"));
    assert_eq($id, db_value("SELECT user_id FROM audit_log WHERE details = 'Wrong password'"));
});

test('UT-AUT-009', 'passwords are stored hashed, never as plain text', function () {
    $id = make_user('doctor', 'dr.hash');
    $hash = (string) db_value('SELECT password_hash FROM users WHERE id = ?', [$id]);
    assert_true(strpos($hash, TEST_PASSWORD) === false);
    assert_true(str_starts_with($hash, '$2y$'));
    assert_true(password_verify(TEST_PASSWORD, $hash));
});

test('UT-AUT-010', 'start_user_session stores the user and issues a fresh CSRF token', function () {
    $id = make_user('lab', 'lab.s');
    $_SESSION['csrf'] = 'old-token';
    $u = get_user($id);
    start_user_session($u);
    assert_eq($id, $_SESSION['user']['id']);
    assert_same('lab', $_SESSION['user']['role']);
    assert_true($_SESSION['csrf'] !== 'old-token', 'token rotated at sign-in');
    assert_false(isset($_SESSION['user']['password_hash']), 'no password data kept in the session');
});

test('UT-AUT-011', 'user_can follows the signed-in role', function () {
    assert_false(user_can('patients.view'), 'nobody signed in');
    sign_in_as(make_user('receptionist'));
    assert_true(user_can('patients.register'));
    assert_false(user_can('consult.record'));
    sign_in_as(make_user('doctor'));
    assert_true(user_can('consult.record'));
    assert_false(user_can('patients.register'));
});

test('UT-AUT-012', 'set_user_password replaces the old password and can force a change', function () {
    $id = make_user('cashier', 'cash.pw');
    set_user_password($id, 'Another#Pass456', true);
    assert_false(attempt_login('cash.pw', TEST_PASSWORD, '10.0.0.12')['ok'], 'old password stops working');
    $r = attempt_login('cash.pw', 'Another#Pass456', '10.0.0.12');
    assert_true($r['ok']);
    assert_eq(1, $r['user']['must_change_password']);
});
