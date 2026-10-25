<?php
declare(strict_types=1);

suite('Module 1: doctor schedules and leave');

/** A weekly form: every clinic day Monday to Friday 08:00-12:00 and 14:00-17:00, Saturday 08:00-12:00. */
function weekly_form(array $o = []): array
{
    $sch = [];
    foreach ([1, 2, 3, 4, 5] as $wd) {
        $sch[$wd] = [['from' => '08:00', 'to' => '12:00'], ['from' => '14:00', 'to' => '17:00']];
    }
    $sch[6] = [['from' => '08:00', 'to' => '12:00']];
    return ['sch' => $o + $sch];
}

/** Receptionist, manager, doctor and patient with the clock on Monday 2 November 2026, 09:00. */
function sched_setup(): array
{
    freeze_time('2026-11-02 09:00:00');
    $rec = make_user('receptionist');
    $mgr = make_user('manager');
    $doc = make_user('doctor');
    $pat = make_patient([], $rec);
    return [$rec, $mgr, $doc, $pat];
}

test('UT-SCH-001', 'schedule times are the clinic slot boundaries, 08:00 to 17:00 in twenty-minute steps', function () {
    $t = schedule_time_choices();
    assert_count(28, $t);
    assert_same('08:00', $t[0]);
    assert_same('08:20', $t[1]);
    assert_same('17:00', $t[27]);
});

test('UT-SCH-002', 'a doctor with no schedule keeps the clinic opening hours, so nothing changes for existing doctors', function () {
    [$rec, , $doc, $pat] = sched_setup();
    assert_false(doctor_has_schedule($doc));
    assert_null(doctor_blocks_on($doc, '2026-11-03'));
    assert_true(doctor_availability($doc, '2026-11-03')['available']);
    assert_same('Clinic hours (no personal schedule set)', schedule_summary($doc));
    $states = array_unique(array_column(doctor_day_slots($doc, '2026-11-03'), 'state'));
    assert_false(in_array('off', $states, true), 'no slot is switched off');
    assert_true(book_appointment(['patient_id' => $pat, 'doctor_id' => $doc, 'date' => '2026-11-03', 'start_time' => '08:00'], $rec)['ok']);
    assert_true(book_appointment(['patient_id' => $pat, 'doctor_id' => $doc, 'date' => '2026-11-03', 'start_time' => '16:40'], $rec)['ok']);
});

test('UT-SCH-003', 'saving the weekly hours stores the blocks, replaces the old ones and is audited', function () {
    [, $mgr, $doc] = sched_setup();
    $r = save_doctor_schedule($doc, weekly_form(), $mgr);
    assert_true($r['ok']);
    assert_eq(0, $r['conflicts']);
    assert_eq(11, db_value('SELECT COUNT(*) FROM doctor_schedules WHERE doctor_id = ?', [$doc]));
    assert_same('Mon-Fri 08:00-12:00 and 14:00-17:00; Sat 08:00-12:00', schedule_summary($doc));
    assert_same([['08:00', '12:00'], ['14:00', '17:00']], doctor_schedule($doc)[3]);
    // a second save replaces, it does not add
    assert_true(save_doctor_schedule($doc, ['sch' => [2 => [['from' => '09:00', 'to' => '13:00']]]], $mgr)['ok']);
    assert_eq(1, db_value('SELECT COUNT(*) FROM doctor_schedules WHERE doctor_id = ?', [$doc]));
    assert_same('Tue 09:00-13:00', schedule_summary($doc));
    assert_eq(2, db_value("SELECT COUNT(*) FROM audit_log WHERE entity = 'schedule' AND entity_id = ? AND action = 'update'", [$doc]));
});

test('UT-SCH-004', 'a bad schedule is refused with a message on the day, and the saved hours are left alone', function () {
    [, $mgr, $doc] = sched_setup();
    save_doctor_schedule($doc, weekly_form(), $mgr);
    $cases = [
        'half a block'          => [1 => [['from' => '08:00', 'to' => '']]],
        'end before start'      => [1 => [['from' => '12:00', 'to' => '08:00']]],
        'same start and end'    => [1 => [['from' => '09:00', 'to' => '09:00']]],
        'off the grid'          => [1 => [['from' => '08:10', 'to' => '12:00']]],
        'outside clinic hours'  => [1 => [['from' => '06:00', 'to' => '12:00']]],
        'overlapping blocks'    => [1 => [['from' => '08:00', 'to' => '12:00'], ['from' => '11:00', 'to' => '15:00']]],
    ];
    foreach ($cases as $label => $sch) {
        $r = save_doctor_schedule($doc, ['sch' => $sch], $mgr);
        assert_false($r['ok'], $label);
        assert_has_key('d1', $r['errors'], $label);
    }
    $none = save_doctor_schedule($doc, ['sch' => []], $mgr);
    assert_false($none['ok']);
    assert_has_key('_', $none['errors']);
    assert_eq(11, db_value('SELECT COUNT(*) FROM doctor_schedules WHERE doctor_id = ?', [$doc]), 'the saved hours survive every refusal');
    assert_false(save_doctor_schedule(make_user('nurse'), weekly_form(), $mgr)['ok'], 'only a doctor has a schedule');
    // hostile input is ignored, not trusted
    assert_false(save_doctor_schedule($doc, ['sch' => 'x'], $mgr)['ok']);
    assert_false(save_doctor_schedule($doc, ['sch' => [1 => 'x']], $mgr)['ok']);
});

test('UT-SCH-005', 'a slot outside the hours of the doctor cannot be booked, one inside can', function () {
    [$rec, $mgr, $doc, $pat] = sched_setup();
    save_doctor_schedule($doc, weekly_form(), $mgr);
    $lunch = book_appointment(['patient_id' => $pat, 'doctor_id' => $doc, 'date' => '2026-11-03', 'start_time' => '12:00'], $rec);
    assert_false($lunch['ok']);
    assert_contains('does not work at that time', $lunch['errors']['start_time']);
    assert_false(book_appointment(['patient_id' => $pat, 'doctor_id' => $doc, 'date' => '2026-11-03', 'start_time' => '13:40'], $rec)['ok']);
    assert_true(book_appointment(['patient_id' => $pat, 'doctor_id' => $doc, 'date' => '2026-11-03', 'start_time' => '11:40'], $rec)['ok'], 'the last slot before lunch ends exactly at 12:00');
    assert_true(book_appointment(['patient_id' => $pat, 'doctor_id' => $doc, 'date' => '2026-11-03', 'start_time' => '14:00'], $rec)['ok']);
    assert_eq(0, db_value("SELECT COUNT(*) FROM appointments WHERE start_time IN ('12:00:00', '13:40:00')"));
});

test('UT-SCH-006', 'a day the doctor does not work is refused with a clear message', function () {
    [$rec, $mgr, $doc, $pat] = sched_setup();
    save_doctor_schedule($doc, ['sch' => [1 => [['from' => '08:00', 'to' => '17:00']]]], $mgr);
    $tue = book_appointment(['patient_id' => $pat, 'doctor_id' => $doc, 'date' => '2026-11-03', 'start_time' => '09:00'], $rec);
    assert_false($tue['ok']);
    assert_contains('does not work on Tuesdays', $tue['errors']['date']);
    assert_true(book_appointment(['patient_id' => $pat, 'doctor_id' => $doc, 'date' => '2026-11-09', 'start_time' => '09:00'], $rec)['ok'], 'the next Monday is fine');
    assert_false(doctor_availability($doc, '2026-11-03')['available']);
    assert_true(doctor_availability($doc, '2026-11-09')['available']);
});

test('UT-SCH-007', 'leave blocks booking, switches every slot off, and the message does not say why the doctor is away', function () {
    [$rec, $mgr, $doc, $pat] = sched_setup();
    $l = add_doctor_leave($doc, ['start_date' => '2026-11-04', 'end_date' => '2026-11-06', 'type' => 'Sick leave', 'reason' => 'Malaria'], $mgr);
    assert_true($l['ok']);
    assert_eq(0, $l['conflicts']);
    foreach (['2026-11-04', '2026-11-05', '2026-11-06'] as $d) {
        $r = book_appointment(['patient_id' => $pat, 'doctor_id' => $doc, 'date' => $d, 'start_time' => '09:00'], $rec);
        assert_false($r['ok'], $d);
        assert_contains('is away on that date', $r['errors']['date']);
        assert_not_contains('Sick', $r['errors']['date']);
        assert_not_contains('Malaria', $r['errors']['date']);
        assert_same(['off'], array_values(array_unique(array_column(doctor_day_slots($doc, $d), 'state'))));
    }
    assert_true(book_appointment(['patient_id' => $pat, 'doctor_id' => $doc, 'date' => '2026-11-03', 'start_time' => '09:00'], $rec)['ok'], 'the day before is fine');
    assert_true(book_appointment(['patient_id' => $pat, 'doctor_id' => $doc, 'date' => '2026-11-07', 'start_time' => '09:00'], $rec)['ok'], 'the day after is fine');
    assert_not_null(doctor_leave_on($doc, '2026-11-05'));
    assert_null(doctor_leave_on($doc, '2026-11-07'));
});

test('UT-SCH-008', 'leave is checked: dates, type, overlaps and the length of the period', function () {
    [, $mgr, $doc] = sched_setup();
    $ok = ['start_date' => '2026-11-10', 'end_date' => '2026-11-12', 'type' => 'Annual leave'];
    assert_true(add_doctor_leave($doc, $ok, $mgr)['ok']);
    $cases = [
        'end before start' => ['start_date' => '2026-11-20', 'end_date' => '2026-11-19'] + $ok,
        'already ended'    => ['start_date' => '2026-10-01', 'end_date' => '2026-10-05'] + $ok,
        'overlap'          => ['start_date' => '2026-11-12', 'end_date' => '2026-11-14'] + $ok,
        'unknown type'     => ['type' => 'Holiday'] + ['start_date' => '2026-12-01', 'end_date' => '2026-12-02'],
        'bad date'         => ['start_date' => '2026-02-31', 'end_date' => '2026-03-02'] + $ok,
        'too long'         => ['start_date' => '2026-11-20', 'end_date' => '2028-01-01'] + $ok,
    ];
    foreach ($cases as $label => $in) {
        assert_false(add_doctor_leave($doc, $in, $mgr)['ok'], $label);
    }
    assert_false(add_doctor_leave(make_user('nurse'), ['start_date' => '2026-12-01', 'end_date' => '2026-12-02', 'type' => 'Other'], $mgr)['ok'], 'only a doctor takes leave');
    assert_true(add_doctor_leave($doc, ['start_date' => '2026-11-13', 'end_date' => '2026-11-13', 'type' => 'Training'], $mgr)['ok'], 'the day right after is not an overlap');
    assert_true(add_doctor_leave($doc, ['start_date' => '2026-11-02', 'end_date' => '2026-11-02', 'type' => 'Other'], $mgr)['ok'], 'leave that starts today is allowed');
});

test('UT-SCH-009', 'leave can be removed until it has ended, and removal opens the days again', function () {
    [$rec, $mgr, $doc, $pat] = sched_setup();
    $l = add_doctor_leave($doc, ['start_date' => '2026-11-04', 'end_date' => '2026-11-04', 'type' => 'Annual leave'], $mgr);
    assert_false(book_appointment(['patient_id' => $pat, 'doctor_id' => $doc, 'date' => '2026-11-04', 'start_time' => '09:00'], $rec)['ok']);
    assert_null(delete_doctor_leave($l['id'], $mgr));
    assert_true(book_appointment(['patient_id' => $pat, 'doctor_id' => $doc, 'date' => '2026-11-04', 'start_time' => '09:00'], $rec)['ok']);
    assert_eq(1, db_value("SELECT COUNT(*) FROM audit_log WHERE entity = 'leave' AND action = 'delete'"));
    // leave that has ended is a record and stays
    db_exec("INSERT INTO doctor_leave (doctor_id, start_date, end_date, type, created_by) VALUES (?, '2026-10-01', '2026-10-05', 'Annual leave', ?)", [$doc, $mgr]);
    $old = (int) db_value('SELECT MAX(id) FROM doctor_leave');
    assert_contains('already ended', (string) delete_doctor_leave($old, $mgr));
    assert_contains('does not exist', (string) delete_doctor_leave(999999, $mgr));
});

test('UT-SCH-010', 'appointments already booked are never changed, but they are listed as conflicts until they are moved', function () {
    [$rec, $mgr, $doc, $pat] = sched_setup();
    $other = make_user('doctor');
    $pat2 = make_patient([], $rec);
    $a = book_appointment(['patient_id' => $pat, 'doctor_id' => $doc, 'date' => '2026-11-04', 'start_time' => '10:00'], $rec);
    $b = book_appointment(['patient_id' => $pat2, 'doctor_id' => $other, 'date' => '2026-11-04', 'start_time' => '10:00'], $rec);
    assert_eq(0, schedule_conflict_count());

    // new hours: this doctor now works only in the afternoon
    $r = save_doctor_schedule($doc, ['sch' => [3 => [['from' => '14:00', 'to' => '17:00']]]], $mgr);
    assert_eq(1, $r['conflicts']);
    $rows = schedule_conflicts();
    assert_count(1, $rows);
    assert_eq($a['id'], $rows[0]['id']);
    assert_same('hours', $rows[0]['why']);
    assert_same('Booked', get_appointment($a['id'])['status'], 'nothing is cancelled for the receptionist');
    assert_eq(1, schedule_conflict_count($doc));
    assert_eq(0, schedule_conflict_count($other), 'the other doctor is not affected');

    // leave on the same day is reported as leave
    add_doctor_leave($other, ['start_date' => '2026-11-04', 'end_date' => '2026-11-05', 'type' => 'Annual leave'], $mgr);
    $why = array_column(schedule_conflicts(), 'why', 'id');
    assert_same('leave', $why[$b['id']]);
    assert_eq(2, schedule_conflict_count());

    // once the appointments are cancelled the conflicts go away
    assert_null(set_appointment_status($a['id'], 'Cancelled', $rec, 'Doctor not available'));
    assert_null(set_appointment_status($b['id'], 'Cancelled', $rec, 'Doctor not available'));
    assert_eq(0, schedule_conflict_count());
    assert_count(0, schedule_conflicts());
});

test('UT-SCH-011', 'a booking made before the hours changed still shows as taken, and past or attended appointments are not conflicts', function () {
    [$rec, $mgr, $doc, $pat] = sched_setup();
    book_appointment(['patient_id' => $pat, 'doctor_id' => $doc, 'date' => '2026-11-03', 'start_time' => '10:00'], $rec);
    save_doctor_schedule($doc, ['sch' => [2 => [['from' => '14:00', 'to' => '17:00']]]], $mgr);
    $slots = doctor_day_slots($doc, '2026-11-03');
    assert_same('taken', $slots['10:00']['state'], 'the existing booking is still visible');
    assert_same('off', $slots['09:40']['state']);
    assert_same('free', $slots['14:00']['state']);
    // an appointment on an earlier day, or one that was attended, is never listed
    db_exec("UPDATE appointments SET appointment_date = '2026-10-20'");
    assert_eq(0, schedule_conflict_count(), 'past days are not conflicts');
    db_exec("UPDATE appointments SET appointment_date = '2026-11-03', status = 'Attended'");
    assert_eq(0, schedule_conflict_count(), 'only Booked appointments are conflicts');
});

test('UT-SCH-012', 'a walk-in cannot be given to a doctor who is away today, but blank or an appointment check-in still works', function () {
    freeze_time('2026-11-02 08:30:00');
    $rec = make_user('receptionist');
    $mgr = make_user('manager');
    $doc = make_user('doctor');
    $pat = make_patient([], $rec);
    add_doctor_leave($doc, ['start_date' => '2026-11-02', 'end_date' => '2026-11-03', 'type' => 'Sick leave'], $mgr);
    $r = check_in_patient($pat, null, $doc, 'Cough', $rec);
    assert_false($r['ok']);
    assert_contains('away today', $r['error']);
    assert_eq(0, db_value('SELECT COUNT(*) FROM visits'));
    assert_true(check_in_patient($pat, null, null, 'Cough', $rec)['ok'], 'next available doctor');
});

test('UT-SCH-013', 'removing the personal schedule returns the doctor to clinic hours', function () {
    [$rec, $mgr, $doc, $pat] = sched_setup();
    save_doctor_schedule($doc, ['sch' => [1 => [['from' => '08:00', 'to' => '10:00']]]], $mgr);
    assert_false(book_appointment(['patient_id' => $pat, 'doctor_id' => $doc, 'date' => '2026-11-03', 'start_time' => '09:00'], $rec)['ok']);
    assert_true(clear_doctor_schedule($doc, $mgr));
    assert_false(clear_doctor_schedule($doc, $mgr), 'nothing left to remove');
    assert_false(doctor_has_schedule($doc));
    assert_true(book_appointment(['patient_id' => $pat, 'doctor_id' => $doc, 'date' => '2026-11-03', 'start_time' => '09:00'], $rec)['ok']);
    assert_eq(2, db_value("SELECT COUNT(*) FROM audit_log WHERE entity = 'schedule'"), 'saved once, removed once');
});

test('UT-SCH-014', 'the overview lists every active doctor with hours, leave today and the appointments to move', function () {
    [$rec, $mgr, $doc, $pat] = sched_setup();
    $busy = make_user('doctor');
    make_user('doctor', null, ['is_active' => 0]);
    save_doctor_schedule($doc, weekly_form(), $mgr);
    add_doctor_leave($busy, ['start_date' => '2026-11-02', 'end_date' => '2026-11-03', 'type' => 'Annual leave'], $mgr);
    book_appointment(['patient_id' => $pat, 'doctor_id' => $busy, 'date' => '2026-11-10', 'start_time' => '09:00'], $rec);
    add_doctor_leave($busy, ['start_date' => '2026-11-10', 'end_date' => '2026-11-10', 'type' => 'Training'], $mgr);
    $rows = array_column(schedule_overview(), null, 'id');
    assert_count(2, $rows, 'inactive doctors are left out');
    assert_true($rows[$doc]['has_schedule']);
    assert_false($rows[$busy]['has_schedule']);
    assert_true($rows[$busy]['on_leave_today']);
    assert_false($rows[$doc]['on_leave_today']);
    assert_eq(1, $rows[$busy]['conflicts']);
    assert_same('2026-11-02', $rows[$busy]['next_leave']['start_date']);
});

test('UT-SCH-015', 'the database refuses nonsense schedules and leave even if the application were bypassed', function () {
    [, $mgr, $doc] = sched_setup();
    assert_throws(fn () => db_exec('INSERT INTO doctor_schedules (doctor_id, weekday, start_time, end_time, created_by) VALUES (?, 8, ?, ?, ?)', [$doc, '08:00:00', '12:00:00', $mgr]), 'weekday 8');
    assert_throws(fn () => db_exec('INSERT INTO doctor_schedules (doctor_id, weekday, start_time, end_time, created_by) VALUES (?, 1, ?, ?, ?)', [$doc, '12:00:00', '08:00:00', $mgr]), 'end before start');
    db_exec('INSERT INTO doctor_schedules (doctor_id, weekday, start_time, end_time, created_by) VALUES (?, 1, ?, ?, ?)', [$doc, '08:00:00', '12:00:00', $mgr]);
    assert_throws(fn () => db_exec('INSERT INTO doctor_schedules (doctor_id, weekday, start_time, end_time, created_by) VALUES (?, 1, ?, ?, ?)', [$doc, '08:00:00', '10:00:00', $mgr]), 'two blocks with the same start');
    assert_throws(fn () => db_exec("INSERT INTO doctor_leave (doctor_id, start_date, end_date, type, created_by) VALUES (?, '2026-11-10', '2026-11-09', 'Other', ?)", [$doc, $mgr]), 'leave ending before it starts');
});

test('UT-SCH-016', 'only the Hospital Manager manages schedules; receptionists are alerted to conflicts and doctors only see their own hours', function () {
    assert_true(role_can('manager', 'schedules.manage'));
    foreach (['admin', 'receptionist', 'nurse', 'doctor', 'lab', 'pharmacist', 'cashier'] as $role) {
        assert_false(role_can($role, 'schedules.manage'), $role);
    }
    [$rec, $mgr, $doc, $pat] = sched_setup();
    assert_eq(0, shell_counts(['id' => $rec, 'role' => 'receptionist'])['sched_conflicts']);
    book_appointment(['patient_id' => $pat, 'doctor_id' => $doc, 'date' => '2026-11-04', 'start_time' => '10:00'], $rec);
    add_doctor_leave($doc, ['start_date' => '2026-11-04', 'end_date' => '2026-11-04', 'type' => 'Annual leave'], $mgr);
    $c = shell_counts(['id' => $rec + 1000, 'role' => 'receptionist']);
    assert_eq(1, $c['sched_conflicts']);
    $n = notifications_for(['id' => $rec, 'role' => 'receptionist'], $c);
    $titles = implode('|', array_column($n, 'title'));
    assert_contains('1 booked appointment to move', $titles);
    assert_eq(1, nav_badges(['id' => $rec, 'role' => 'receptionist'], $c)['conflicts']);
    $mgrNote = notifications_for(['id' => $mgr, 'role' => 'manager'], shell_counts(['id' => $mgr + 1000, 'role' => 'manager']));
    $href = array_values(array_filter($mgrNote, fn ($x) => str_contains($x['title'], 'to move')))[0]['href'];
    assert_same('admin/schedules.php', $href, 'the manager is sent to the schedules, the receptionist to the list of appointments');
});
