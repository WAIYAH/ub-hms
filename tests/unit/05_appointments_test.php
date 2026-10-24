<?php
declare(strict_types=1);

suite('Module 1: appointments');

/** Monday 2 November 2026, 09:00. Tuesday 3rd is the next working day; Sunday 8th the clinic is closed. */
function appt_setup(): array
{
    freeze_time('2026-11-02 09:00:00');
    $rec = make_user('receptionist');
    $doc = make_user('doctor');
    $pat = make_patient([], $rec);
    return [$rec, $doc, $pat];
}

function book(int $patient, int $doctor, string $date, string $time, int $by, string $reason = ''): array
{
    return book_appointment(['patient_id' => $patient, 'doctor_id' => $doctor, 'date' => $date, 'start_time' => $time, 'reason' => $reason], $by);
}

test('UT-APT-001', 'a working day has 27 twenty-minute slots from 08:00 to 16:40', function () {
    $slots = clinic_slots('2026-11-03');
    assert_count(27, $slots);
    assert_same('08:00', $slots[0]);
    assert_same('16:40', $slots[26]);
    assert_same('08:20', $slots[1]);
    assert_same('17:00', slot_end_time('16:40'));
});

test('UT-APT-002', 'the clinic is closed on Sundays and invalid dates have no slots', function () {
    assert_count(0, clinic_slots('2026-11-08'));
    assert_count(0, clinic_slots('not-a-date'));
    assert_count(27, clinic_slots('2026-11-07'), 'Saturday is a working day');
});

test('UT-APT-003', 'a valid booking is saved as Booked with its end time and an audit entry', function () {
    [$rec, $doc, $pat] = appt_setup();
    $r = book($pat, $doc, '2026-11-03', '10:20', $rec, 'Headache');
    assert_true($r['ok']);
    $a = get_appointment($r['id']);
    assert_same('Booked', $a['status']);
    assert_same('10:20:00', $a['start_time']);
    assert_same('10:40:00', $a['end_time']);
    assert_same('Headache', $a['reason']);
    assert_eq($rec, $a['booked_by']);
    assert_eq(1, db_value("SELECT COUNT(*) FROM audit_log WHERE entity = 'appointment' AND entity_id = ? AND action = 'create'", [$r['id']]));
});

test('UT-APT-004', 'the same doctor cannot be booked twice for the same slot', function () {
    [$rec, $doc, $pat] = appt_setup();
    $other = make_patient(['national_id' => '77000001', 'first_name' => 'Second'], $rec);
    assert_true(book($pat, $doc, '2026-11-03', '09:00', $rec)['ok']);
    $r = book($other, $doc, '2026-11-03', '09:00', $rec);
    assert_false($r['ok']);
    assert_has_key('start_time', $r['errors']);
    assert_contains('already booked', $r['errors']['start_time']);
    assert_eq(1, db_value("SELECT COUNT(*) FROM appointments"));
});

test('UT-APT-005', 'different doctors may be booked at the same time, and the same doctor at other times', function () {
    [$rec, $doc, $pat] = appt_setup();
    $doc2 = make_user('doctor');
    $other = make_patient(['national_id' => '77000002', 'first_name' => 'Third'], $rec);
    assert_true(book($pat, $doc, '2026-11-03', '09:00', $rec)['ok']);
    assert_true(book($other, $doc2, '2026-11-03', '09:00', $rec)['ok']);
    assert_true(book($other, $doc, '2026-11-03', '09:20', $rec)['ok']);
});

test('UT-APT-006', 'a patient cannot hold two appointments at the same time', function () {
    [$rec, $doc, $pat] = appt_setup();
    $doc2 = make_user('doctor');
    assert_true(book($pat, $doc, '2026-11-03', '11:00', $rec)['ok']);
    $r = book($pat, $doc2, '2026-11-03', '11:00', $rec);
    assert_false($r['ok']);
    assert_contains('already has an appointment', $r['errors']['start_time']);
});

test('UT-APT-007', 'past dates and times that have already passed today are refused', function () {
    [$rec, $doc, $pat] = appt_setup();
    assert_has_key('date', book($pat, $doc, '2026-11-01', '10:00', $rec)['errors']);
    assert_has_key('start_time', book($pat, $doc, '2026-11-02', '08:40', $rec)['errors'], 'earlier today');
    assert_true(book($pat, $doc, '2026-11-02', '09:20', $rec)['ok'], 'later today is fine');
});

test('UT-APT-008', 'times off the slot grid or outside clinic hours are refused', function () {
    [$rec, $doc, $pat] = appt_setup();
    foreach (['08:10', '07:40', '17:00', '16:50', '25:00', ''] as $t) {
        $r = book($pat, $doc, '2026-11-03', $t, $rec);
        assert_false($r['ok'], "time '$t' should be refused");
        assert_has_key('start_time', $r['errors']);
    }
});

test('UT-APT-009', 'closed days and dates too far ahead are refused', function () {
    [$rec, $doc, $pat] = appt_setup();
    assert_contains('closed', book($pat, $doc, '2026-11-08', '10:00', $rec)['errors']['date']);
    assert_contains('days ahead', book($pat, $doc, '2027-03-01', '10:00', $rec)['errors']['date']);
    assert_has_key('date', book($pat, $doc, '2026-02-30', '10:00', $rec)['errors']);
});

test('UT-APT-010', 'only registered patients and active doctors can be booked', function () {
    [$rec, $doc, $pat] = appt_setup();
    $nurse = make_user('nurse');
    $off = make_user('doctor', null, ['is_active' => 0]);
    assert_has_key('patient_id', book(99999, $doc, '2026-11-03', '10:00', $rec)['errors']);
    assert_has_key('doctor_id', book($pat, $nurse, '2026-11-03', '10:00', $rec)['errors'], 'a nurse is not a doctor');
    assert_has_key('doctor_id', book($pat, $off, '2026-11-03', '10:00', $rec)['errors'], 'inactive doctor');
    assert_has_key('doctor_id', book($pat, 0, '2026-11-03', '10:00', $rec)['errors']);
});

test('UT-APT-011', 'a cancelled appointment releases its slot for someone else', function () {
    [$rec, $doc, $pat] = appt_setup();
    $other = make_patient(['national_id' => '77000003', 'first_name' => 'Fourth'], $rec);
    $first = book($pat, $doc, '2026-11-03', '14:00', $rec);
    assert_null(set_appointment_status($first['id'], 'Cancelled', $rec, 'Patient called to cancel'));
    assert_same('Patient called to cancel', get_appointment($first['id'])['cancel_reason']);
    assert_true(book($other, $doc, '2026-11-03', '14:00', $rec)['ok']);
});

test('UT-APT-012', 'missed only after the time has passed; cancelled only before it', function () {
    [$rec, $doc, $pat] = appt_setup();
    $future = book($pat, $doc, '2026-11-03', '10:00', $rec);
    assert_not_null(set_appointment_status($future['id'], 'Missed', $rec), 'cannot be missed before its time');
    assert_null(set_appointment_status($future['id'], 'Cancelled', $rec));
    $today = book($pat, $doc, '2026-11-02', '09:40', $rec);
    freeze_time('2026-11-02 11:00:00');
    assert_not_null(set_appointment_status($today['id'], 'Cancelled', $rec), 'a past appointment cannot be cancelled');
    assert_null(set_appointment_status($today['id'], 'Missed', $rec));
    assert_same('Missed', get_appointment($today['id'])['status']);
    assert_not_null(set_appointment_status($today['id'], 'Attended', $rec), 'Attended is set only by check-in');
});

test('UT-APT-013', 'only a Booked appointment can change status', function () {
    [$rec, $doc, $pat] = appt_setup();
    $r = book($pat, $doc, '2026-11-03', '10:00', $rec);
    set_appointment_status($r['id'], 'Cancelled', $rec);
    $err = set_appointment_status($r['id'], 'Cancelled', $rec);
    assert_not_null($err);
    assert_contains('cancelled', $err);
    assert_not_null(set_appointment_status(99999, 'Cancelled', $rec));
});

test('UT-APT-014', 'the database itself refuses a double booking even if the application check is bypassed', function () {
    [$rec, $doc, $pat] = appt_setup();
    $other = make_patient(['national_id' => '77000004', 'first_name' => 'Fifth'], $rec);
    $sql = "INSERT INTO appointments (patient_id, doctor_id, appointment_date, start_time, end_time, booked_by, status) VALUES (?, ?, '2026-11-03', '10:00:00', '10:20:00', ?, ?)";
    db_exec($sql, [$pat, $doc, $rec, 'Booked']);
    $e = assert_throws(fn () => db_exec($sql, [$other, $doc, $rec, 'Booked']));
    assert_true($e instanceof PDOException, 'unique slot index rejected the row');
    db_exec($sql, [$other, $doc, $rec, 'Cancelled']);
    db_exec($sql, [$other, $doc, $rec, 'Cancelled']);
    assert_eq(3, db_value('SELECT COUNT(*) FROM appointments'), 'cancelled rows do not hold the slot');
});

test('UT-APT-015', 'overdue booked appointments become Missed; future ones and attended ones are left alone', function () {
    [$rec, $doc, $pat] = appt_setup();
    $past = db_insert("INSERT INTO appointments (patient_id, doctor_id, appointment_date, start_time, end_time, booked_by) VALUES (?, ?, '2026-10-30', '10:00:00', '10:20:00', ?)", [$pat, $doc, $rec]);
    $attended = db_insert("INSERT INTO appointments (patient_id, doctor_id, appointment_date, start_time, end_time, booked_by, status) VALUES (?, ?, '2026-10-30', '11:00:00', '11:20:00', ?, 'Attended')", [$pat, $doc, $rec]);
    $future = book($pat, $doc, '2026-11-03', '10:00', $rec)['id'];
    assert_eq(1, mark_overdue_appointments_missed());
    assert_same('Missed', get_appointment($past)['status']);
    assert_same('Attended', get_appointment($attended)['status']);
    assert_same('Booked', get_appointment($future)['status']);
});

test('UT-APT-016', 'doctor_day_slots reports free, taken and past slots', function () {
    [$rec, $doc, $pat] = appt_setup();
    book($pat, $doc, '2026-11-02', '10:00', $rec);
    $slots = doctor_day_slots($doc, '2026-11-02');
    assert_same('past', $slots['08:00']['state']);
    assert_same('past', $slots['09:00']['state'], 'a slot starting now has begun');
    assert_same('taken', $slots['10:00']['state']);
    assert_same('free', $slots['10:20']['state']);
    assert_eq($pat, $slots['10:00']['appointment']['patient_id']);
});

test('UT-APT-017', 'the diary filters by date, doctor and status in time order', function () {
    [$rec, $doc, $pat] = appt_setup();
    $doc2 = make_user('doctor');
    book($pat, $doc, '2026-11-03', '11:00', $rec);
    $o = make_patient(['national_id' => '77000005', 'first_name' => 'Sixth'], $rec);
    book($o, $doc, '2026-11-03', '09:00', $rec);
    book($o, $doc2, '2026-11-03', '12:00', $rec);
    book($o, $doc, '2026-11-04', '09:00', $rec);
    [$rows, $total] = list_appointments(['date' => '2026-11-03'], 50, 0);
    assert_eq(3, $total);
    assert_same(['09:00:00', '11:00:00', '12:00:00'], array_column($rows, 'start_time'));
    assert_eq(2, list_appointments(['date' => '2026-11-03', 'doctor_id' => $doc], 50, 0)[1]);
    assert_eq(0, list_appointments(['date' => '2026-11-03', 'status' => 'Cancelled'], 50, 0)[1]);
    assert_eq(4, list_appointments(['from' => '2026-11-01', 'to' => '2026-11-30'], 50, 0)[1]);
});

test('UT-APT-018', 'two bookings racing for one slot: exactly one succeeds', function () {
    [$rec, $doc, $pat] = appt_setup();
    $o = make_patient(['national_id' => '77000006', 'first_name' => 'Seventh'], $rec);
    $results = [book($pat, $doc, '2026-11-03', '15:00', $rec)['ok'], book($o, $doc, '2026-11-03', '15:00', $rec)['ok']];
    assert_same([true, false], $results);
    assert_eq(1, db_value("SELECT COUNT(*) FROM appointments WHERE start_time = '15:00:00' AND status = 'Booked'"));
});
