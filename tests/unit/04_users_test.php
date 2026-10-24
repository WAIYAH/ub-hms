<?php
declare(strict_types=1);

suite('Module 6: user management');

test('UT-USR-001', 'an account is created with a one-time password that must be changed', function () {
    $admin = make_user('admin');
    [$clean, $errors] = validate_user_input(['full_name' => 'Jane Wairimu', 'username' => 'Jane.W', 'role' => 'nurse', 'phone' => '0712345678']);
    assert_count(0, $errors);
    $temp = generate_temp_password();
    $id = create_user($clean, $temp, $admin);
    $u = get_user($id);
    assert_same('jane.w', $u['username'], 'username stored in lower case');
    assert_same('nurse', $u['role']);
    assert_eq(1, $u['must_change_password']);
    assert_same('+254712345678', $u['phone']);
    assert_true(attempt_login('jane.w', $temp, '10.0.0.20')['ok']);
});

test('UT-USR-002', 'account details are validated', function () {
    [, $e] = validate_user_input(['full_name' => 'Al', 'username' => 'a b', 'role' => 'wizard', 'phone' => '123']);
    foreach (['full_name', 'username', 'role', 'phone'] as $k) {
        assert_has_key($k, $e);
    }
});

test('UT-USR-003', 'usernames must be unique', function () {
    make_user('nurse', 'taken.name');
    [, $e] = validate_user_input(['full_name' => 'Another Person', 'username' => 'Taken.Name', 'role' => 'nurse']);
    assert_has_key('username', $e);
    $id = (int) db_value("SELECT id FROM users WHERE username = 'taken.name'");
    [, $e2] = validate_user_input(['full_name' => 'Another Person', 'username' => 'taken.name', 'role' => 'nurse'], $id);
    assert_false(isset($e2['username']), 'a user may keep their own username when edited');
});

test('UT-USR-004', 'the last active administrator cannot be deactivated or demoted', function () {
    $a1 = make_user('admin', 'admin.one');
    $other = make_user('doctor');
    assert_not_null(set_user_active($a1, false, $other), 'cannot deactivate the only admin');
    [$clean] = validate_user_input(['full_name' => 'Admin One', 'username' => 'admin.one', 'role' => 'doctor']);
    assert_not_null(update_user($a1, $clean, $other), 'cannot change the only admin to another role');
    $a2 = make_user('admin', 'admin.two');
    assert_null(set_user_active($a1, false, $a2), 'allowed when another administrator exists');
});

test('UT-USR-005', 'an administrator cannot deactivate their own account', function () {
    $a1 = make_user('admin');
    make_user('admin');
    assert_not_null(set_user_active($a1, false, $a1));
});

test('UT-USR-006', 'deactivating an account stops sign-in and reactivating restores it', function () {
    $admin = make_user('admin');
    $id = make_user('cashier', 'cash.toggle');
    assert_null(set_user_active($id, false, $admin));
    assert_false(attempt_login('cash.toggle', TEST_PASSWORD, '10.0.0.30')['ok']);
    assert_null(set_user_active($id, true, $admin));
    assert_true(attempt_login('cash.toggle', TEST_PASSWORD, '10.0.0.30')['ok']);
});

test('UT-USR-007', 'a password reset invalidates the old password and forces a new one', function () {
    $admin = make_user('admin');
    $id = make_user('nurse', 'nurse.reset');
    $temp = generate_temp_password();
    reset_user_password($id, $temp, $admin);
    assert_false(attempt_login('nurse.reset', TEST_PASSWORD, '10.0.0.31')['ok']);
    $r = attempt_login('nurse.reset', $temp, '10.0.0.31');
    assert_true($r['ok']);
    assert_eq(1, $r['user']['must_change_password']);
    $log = (string) db_value("SELECT details FROM audit_log WHERE action = 'password_reset'");
    assert_not_contains($temp, $log, 'the new password is never written to the log');
});

test('UT-USR-008', 'account management is recorded in the audit trail', function () {
    $admin = make_user('admin');
    $id = create_user(['full_name' => 'Audit Subject', 'username' => 'audit.subject', 'role' => 'lab', 'phone' => ''], generate_temp_password(), $admin);
    set_user_active($id, false, $admin);
    $actions = array_column(db_all("SELECT action FROM audit_log WHERE entity = 'user' AND entity_id = ? ORDER BY id", [$id]), 'action');
    assert_same(['create', 'deactivate'], $actions);
});

test('UT-USR-009', 'list_users filters by name and role; list_doctors returns only active doctors', function () {
    make_user('doctor', 'doc.a', ['full_name' => 'Dr Alpha']);
    make_user('doctor', 'doc.b', ['full_name' => 'Dr Beta', 'is_active' => 0]);
    make_user('nurse', 'nurse.a', ['full_name' => 'Nurse Alpha']);
    assert_count(2, list_users('Alpha'));
    assert_count(2, list_users('', 'doctor'));
    $docs = list_doctors();
    assert_count(1, $docs);
    assert_same('Dr Alpha', $docs[0]['full_name']);
});

test('UT-USR-010', 'the audit trail can be filtered by user, action and date', function () {
    $u1 = make_user('doctor');
    $u2 = make_user('nurse');
    audit('view', 'patient', 1, null, $u1);
    audit('view', 'patient', 2, null, $u2);
    audit('create', 'patient', 3, null, $u2);
    [$rows, $total] = search_audit(['user_id' => $u2], 50, 0);
    assert_eq(2, $total);
    [, $total] = search_audit(['action' => 'view'], 50, 0);
    assert_eq(2, $total);
    [, $total] = search_audit(['from' => '2000-01-01', 'to' => '2000-01-02'], 50, 0);
    assert_eq(0, $total);
    [, $total] = search_audit(['from' => date('Y-m-d'), 'to' => date('Y-m-d')], 50, 0);
    assert_eq(3, $total);
});
