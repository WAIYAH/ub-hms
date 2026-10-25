<?php
/**
 * Module 1: appointments.
 *
 * Rules: appointments sit on a fixed grid of slots inside clinic hours; one
 * doctor can hold only one active appointment (Booked or Attended) per slot;
 * a patient cannot be in two places at the same time; cancelled and missed
 * appointments release their slot.
 */
declare(strict_types=1);

const APPOINTMENT_STATUSES = ['Booked', 'Attended', 'Missed', 'Cancelled'];

/** Start times ("08:00", "08:20", ...) available on a date; empty on days the clinic is closed. */
function clinic_slots(string $date): array
{
    if (!is_valid_date($date)) {
        return [];
    }
    $dayNumber = (int) (new DateTimeImmutable($date))->format('N');
    if (!in_array($dayNumber, (array) config('clinic.days'), true)) {
        return [];
    }
    $step = (int) config('clinic.slot_minutes', 20);
    $t = new DateTimeImmutable($date . ' ' . config('clinic.open', '08:00'));
    $close = new DateTimeImmutable($date . ' ' . config('clinic.close', '17:00'));
    $slots = [];
    while ($t->modify("+$step minutes") <= $close) {
        $slots[] = $t->format('H:i');
        $t = $t->modify("+$step minutes");
    }
    return $slots;
}

function slot_end_time(string $start): string
{
    return (new DateTimeImmutable('2000-01-01 ' . $start))->modify('+' . (int) config('clinic.slot_minutes', 20) . ' minutes')->format('H:i');
}

/**
 * State of every clinic slot for one doctor on one date.
 * Returns start => ['state' => 'free'|'taken'|'past'|'off', 'appointment' => row|null].
 * 'off' means the clinic is open but the doctor does not work then (outside their hours, or on leave).
 */
function doctor_day_slots(int $doctorId, string $date): array
{
    $taken = [];
    foreach (db_all(
        "SELECT a.*, p.op_number, p.first_name, p.last_name FROM appointments a JOIN patients p ON p.id = a.patient_id
          WHERE a.doctor_id = ? AND a.appointment_date = ? AND a.status IN ('Booked','Attended')",
        [$doctorId, $date]
    ) as $row) {
        $taken[substr($row['start_time'], 0, 5)] = $row;
    }
    $now = app_now();
    $away = doctor_leave_on($doctorId, $date) !== null;
    $blocks = $away ? [] : doctor_blocks_on($doctorId, $date);
    $out = [];
    foreach (clinic_slots($date) as $start) {
        $isPast = new DateTimeImmutable("$date $start") <= $now;
        if (isset($taken[$start])) {
            $out[$start] = ['state' => 'taken', 'appointment' => $taken[$start]];
        } elseif (!slot_within_blocks($start, $blocks)) {
            $out[$start] = ['state' => 'off', 'appointment' => null];
        } else {
            $out[$start] = ['state' => $isPast ? 'past' : 'free', 'appointment' => null];
        }
    }
    return $out;
}

/**
 * Book an appointment. Returns ['ok' => true, 'id' => n] or
 * ['ok' => false, 'errors' => [field => message]].
 */
function book_appointment(array $in, int $userId): array
{
    $errors = [];
    $patientId = (int) ($in['patient_id'] ?? 0);
    $doctorId = (int) ($in['doctor_id'] ?? 0);
    $date = clean_text($in['date'] ?? '', 10);
    $start = clean_text($in['start_time'] ?? '', 5);
    $reason = clean_text($in['reason'] ?? '', 255);

    $patientRow = db_one('SELECT id, erased_at FROM patients WHERE id = ?', [$patientId]);
    if (!$patientRow) {
        $errors['patient_id'] = 'Choose a registered patient.';
    } elseif ($patientRow['erased_at'] !== null) {
        $errors['patient_id'] = 'This patient record has been erased and cannot be used.';
    }
    if (!is_active_doctor($doctorId)) {
        $errors['doctor_id'] = 'Choose a doctor.';
    }
    if (!is_valid_date($date)) {
        $errors['date'] = 'Choose a valid date.';
    } elseif ($date < app_today()) {
        $errors['date'] = 'You cannot book a date that has already passed.';
    } elseif ($date > app_now()->modify('+' . (int) config('clinic.max_days_ahead', 90) . ' days')->format('Y-m-d')) {
        $errors['date'] = 'Appointments can be booked up to ' . (int) config('clinic.max_days_ahead', 90) . ' days ahead.';
    } elseif (!clinic_slots($date)) {
        $errors['date'] = 'The clinic is closed on that day.';
    }
    $blocks = null;
    if (!isset($errors['date']) && !isset($errors['doctor_id'])) {
        $availability = doctor_availability($doctorId, $date);
        if (!$availability['available']) {
            $errors['date'] = $availability['message'];
        }
        $blocks = $availability['blocks'];
    }
    if (!isset($errors['date'])) {
        if (!in_array($start, clinic_slots($date), true)) {
            $errors['start_time'] = 'Choose a time from the list of slots within clinic hours.';
        } elseif (new DateTimeImmutable("$date $start") <= app_now()) {
            $errors['start_time'] = 'That time has already passed.';
        } elseif (!isset($errors['doctor_id']) && !slot_within_blocks($start, $blocks)) {
            $errors['start_time'] = 'The doctor does not work at that time. Choose a time from the free slots.';
        }
    } elseif (!isset($errors['start_time']) && $start === '') {
        $errors['start_time'] = 'Choose a time.';
    }
    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }

    $end = slot_end_time($start);
    try {
        $id = db_tx(function () use ($patientId, $doctorId, $date, $start, $end, $reason, $userId): int {
            // Lock the doctor's row so two receptionists booking at once are handled one after the other.
            db_value('SELECT id FROM users WHERE id = ? FOR UPDATE', [$doctorId]);
            // An erasure of the patient locks their row too; a booking made at the same moment waits and then sees the result.
            if (db_value('SELECT erased_at FROM patients WHERE id = ? FOR UPDATE', [$patientId]) !== null) {
                throw new RuntimeException('patient_erased');
            }
            // The manager may have changed the doctor's hours or recorded leave a moment ago; saving them locks the same row.
            $now = doctor_availability($doctorId, $date);
            if (!$now['available'] || !slot_within_blocks($start, $now['blocks'])) {
                throw new RuntimeException('doctor_unavailable');
            }
            if (db_value("SELECT id FROM appointments WHERE doctor_id = ? AND appointment_date = ? AND start_time = ? AND status IN ('Booked','Attended')", [$doctorId, $date, $start . ':00'])) {
                throw new RuntimeException('slot_taken');
            }
            if (db_value("SELECT id FROM appointments WHERE patient_id = ? AND appointment_date = ? AND start_time = ? AND status IN ('Booked','Attended')", [$patientId, $date, $start . ':00'])) {
                throw new RuntimeException('patient_busy');
            }
            $newId = db_insert(
                'INSERT INTO appointments (patient_id, doctor_id, appointment_date, start_time, end_time, reason, booked_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$patientId, $doctorId, $date, $start . ':00', $end . ':00', $reason !== '' ? $reason : null, $userId]
            );
            audit('create', 'appointment', $newId, "Booked $date $start", $userId);
            return $newId;
        });
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'slot_taken') {
            return ['ok' => false, 'errors' => ['start_time' => 'That time is already booked for this doctor. Choose another slot.']];
        }
        if ($e->getMessage() === 'doctor_unavailable') {
            return ['ok' => false, 'errors' => ['start_time' => 'The hours of this doctor have just changed and they no longer work at that time. Choose another slot.']];
        }
        if ($e->getMessage() === 'patient_erased') {
            return ['ok' => false, 'errors' => ['patient_id' => 'This patient record has been erased and cannot be used.']];
        }
        if ($e->getMessage() === 'patient_busy') {
            return ['ok' => false, 'errors' => ['start_time' => 'This patient already has an appointment at that time with another doctor.']];
        }
        throw $e;
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') { // the database's own unique slot guard
            return ['ok' => false, 'errors' => ['start_time' => 'That time is already booked for this doctor. Choose another slot.']];
        }
        throw $e;
    }
    return ['ok' => true, 'id' => $id];
}

function get_appointment(int $id): ?array
{
    return db_one(
        'SELECT a.*, p.op_number, p.first_name, p.middle_name, p.last_name, d.full_name AS doctor_name
           FROM appointments a JOIN patients p ON p.id = a.patient_id JOIN users d ON d.id = a.doctor_id WHERE a.id = ?',
        [$id]
    );
}

/** Has the appointment's time already ended? */
function appointment_has_ended(array $a): bool
{
    return new DateTimeImmutable($a['appointment_date'] . ' ' . $a['end_time']) <= app_now();
}

/**
 * Mark a booked appointment Missed or Cancelled. Attended is set only by check-in.
 * Returns an error message, or null on success.
 */
function set_appointment_status(int $id, string $status, int $userId, string $reason = ''): ?string
{
    $a = get_appointment($id);
    if (!$a) {
        return 'Appointment not found.';
    }
    if ($a['status'] !== 'Booked') {
        return 'Only a booked appointment can be changed. This one is ' . strtolower($a['status']) . '.';
    }
    if ($status === 'Missed') {
        if (!appointment_has_ended($a)) {
            return 'An appointment can be marked missed only after its time has passed.';
        }
    } elseif ($status === 'Cancelled') {
        if (appointment_has_ended($a)) {
            return 'This appointment time has passed. Mark it missed instead.';
        }
    } else {
        return 'Choose Missed or Cancelled.';
    }
    $reason = clean_text($reason, 255);
    db_exec('UPDATE appointments SET status = ?, cancel_reason = ? WHERE id = ?', [$status, $status === 'Cancelled' && $reason !== '' ? $reason : null, $id]);
    audit('status', 'appointment', $id, 'Marked ' . strtolower($status), $userId);
    return null;
}

/** Booked appointments from earlier days become Missed. Returns how many were changed. */
function mark_overdue_appointments_missed(): int
{
    $n = db_exec("UPDATE appointments SET status = 'Missed' WHERE status = 'Booked' AND appointment_date < ?", [app_today()]);
    if ($n > 0) {
        audit('auto_missed', 'appointment', null, "$n overdue appointments marked missed");
    }
    return $n;
}

/**
 * Diary query. Filters: date, from, to, doctor_id, patient_id, status.
 * Returns [rows, total].
 */
function list_appointments(array $f, int $limit = 100, int $offset = 0): array
{
    $where = ' WHERE 1 = 1';
    $params = [];
    if (!empty($f['date'])) {
        $where .= ' AND a.appointment_date = ?';
        $params[] = $f['date'];
    }
    if (!empty($f['from'])) {
        $where .= ' AND a.appointment_date >= ?';
        $params[] = $f['from'];
    }
    if (!empty($f['to'])) {
        $where .= ' AND a.appointment_date <= ?';
        $params[] = $f['to'];
    }
    if (!empty($f['doctor_id'])) {
        $where .= ' AND a.doctor_id = ?';
        $params[] = (int) $f['doctor_id'];
    }
    if (!empty($f['patient_id'])) {
        $where .= ' AND a.patient_id = ?';
        $params[] = (int) $f['patient_id'];
    }
    if (!empty($f['status']) && in_array($f['status'], APPOINTMENT_STATUSES, true)) {
        $where .= ' AND a.status = ?';
        $params[] = $f['status'];
    }
    $total = (int) db_value('SELECT COUNT(*) FROM appointments a' . $where, $params);
    $rows = db_all(
        'SELECT a.*, p.op_number, p.first_name, p.middle_name, p.last_name, d.full_name AS doctor_name,
                v.id AS visit_id
           FROM appointments a JOIN patients p ON p.id = a.patient_id JOIN users d ON d.id = a.doctor_id
           LEFT JOIN visits v ON v.appointment_id = a.id' . $where
        . ' ORDER BY a.appointment_date, a.start_time, a.id LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
        $params
    );
    return [$rows, $total];
}

/** Today's booked appointments for one patient (used at check-in). */
function todays_appointments_for_patient(int $patientId): array
{
    return db_all(
        "SELECT a.*, d.full_name AS doctor_name FROM appointments a JOIN users d ON d.id = a.doctor_id
          WHERE a.patient_id = ? AND a.appointment_date = ? AND a.status = 'Booked' ORDER BY a.start_time",
        [$patientId, app_today()]
    );
}
