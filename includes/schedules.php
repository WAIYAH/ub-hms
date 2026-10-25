<?php
/**
 * Doctor working hours and leave.
 *
 * A doctor's schedule is up to two blocks a day (for example 08:00 to 12:00 and 14:00 to 17:00) for each weekday the
 * clinic is open. A doctor with NO schedule rows keeps the clinic's opening hours, so a newly created doctor can be
 * booked at once and the system behaves as it did before schedules existed. Leave is a run of whole days. Booking
 * respects both: a slot is bookable only if the clinic is open, the doctor works then and is not on leave.
 *
 * Changing hours or recording leave never touches appointments that are already booked. They are listed as
 * conflicts for the receptionists to move or cancel, because only a person can tell the patient.
 */
declare(strict_types=1);

const LEAVE_TYPES = ['Annual leave', 'Sick leave', 'Training', 'Other'];
const WEEKDAY_NAMES = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
const WEEKDAY_SHORT = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
/** Longest leave that can be recorded in one go. */
const LEAVE_MAX_DAYS = 366;

function weekday_of(string $date): int
{
    return (int) (new DateTimeImmutable($date))->format('N');
}

function is_active_doctor(int $id): bool
{
    return (bool) db_value("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND r.code = 'doctor' AND u.is_active = 1", [$id]);
}

/** The times a schedule may start or end at: the slot boundaries from opening to closing ("08:00", "08:20", ... "17:00"). */
function schedule_time_choices(): array
{
    $step = max(1, (int) config('clinic.slot_minutes', 20));
    $t = new DateTimeImmutable('2000-01-01 ' . config('clinic.open', '08:00'));
    $close = new DateTimeImmutable('2000-01-01 ' . config('clinic.close', '17:00'));
    $out = [$t->format('H:i')];
    while ($t->modify("+$step minutes") <= $close) {
        $t = $t->modify("+$step minutes");
        $out[] = $t->format('H:i');
    }
    return $out;
}

/* ---------- Reading ---------- */

/** A doctor's saved weekly hours: weekday (1 to 7) => list of ["08:00", "12:00"] blocks. Empty when none is set. */
function doctor_schedule(int $doctorId): array
{
    $out = [];
    foreach (db_all('SELECT weekday, start_time, end_time FROM doctor_schedules WHERE doctor_id = ? ORDER BY weekday, start_time', [$doctorId]) as $r) {
        $out[(int) $r['weekday']][] = [substr($r['start_time'], 0, 5), substr($r['end_time'], 0, 5)];
    }
    return $out;
}

function doctor_has_schedule(int $doctorId): bool
{
    return (bool) db_value('SELECT id FROM doctor_schedules WHERE doctor_id = ? LIMIT 1', [$doctorId]);
}

/** The leave period that covers a date, or null. */
function doctor_leave_on(int $doctorId, string $date): ?array
{
    return db_one('SELECT * FROM doctor_leave WHERE doctor_id = ? AND ? BETWEEN start_date AND end_date ORDER BY start_date LIMIT 1', [$doctorId, $date]);
}

/**
 * The blocks a doctor works on a date, or null when the doctor has no schedule (clinic hours apply).
 * An empty array means the doctor does not work that day.
 */
function doctor_blocks_on(int $doctorId, string $date): ?array
{
    $schedule = doctor_schedule($doctorId);
    return $schedule ? ($schedule[weekday_of($date)] ?? []) : null;
}

/** Does the slot starting at $start fit inside one of the blocks? A null list (no schedule) fits everything. */
function slot_within_blocks(string $start, ?array $blocks): bool
{
    if ($blocks === null) {
        return true;
    }
    $end = slot_end_time($start);
    foreach ($blocks as [$from, $to]) {
        if ($start >= $from && $end <= $to) {
            return true;
        }
    }
    return false;
}

/**
 * Can this doctor see patients on this date at all?
 * Returns ['available' => bool, 'message' => text for staff (never says why a doctor is on leave), 'blocks' => the day's blocks or null].
 */
function doctor_availability(int $doctorId, string $date): array
{
    if (!is_valid_date($date)) {
        return ['available' => false, 'message' => 'Choose a valid date.', 'blocks' => null];
    }
    $name = (string) db_value('SELECT full_name FROM users WHERE id = ?', [$doctorId]);
    if (doctor_leave_on($doctorId, $date)) {
        return ['available' => false, 'message' => "$name is away on that date. Choose another date or another doctor.", 'blocks' => []];
    }
    $blocks = doctor_blocks_on($doctorId, $date);
    if ($blocks !== null && !$blocks) {
        return ['available' => false, 'message' => "$name does not work on " . WEEKDAY_NAMES[weekday_of($date)] . 's. Choose another date or another doctor.', 'blocks' => []];
    }
    return ['available' => true, 'message' => '', 'blocks' => $blocks];
}

/** "Mon-Fri 08:00-12:00 and 14:00-17:00; Sat 08:00-12:00", or the clinic-hours note when no schedule is set. */
function schedule_summary(int $doctorId): string
{
    $schedule = doctor_schedule($doctorId);
    if (!$schedule) {
        return 'Clinic hours (no personal schedule set)';
    }
    $text = static fn (array $blocks): string => implode(' and ', array_map(static fn ($b) => $b[0] . '-' . $b[1], $blocks));
    $groups = [];
    foreach ($schedule as $day => $blocks) {
        $sig = $text($blocks);
        $last = count($groups) - 1;
        if ($last >= 0 && $groups[$last]['sig'] === $sig && $groups[$last]['to'] === $day - 1) {
            $groups[$last]['to'] = $day;
        } else {
            $groups[] = ['from' => $day, 'to' => $day, 'sig' => $sig];
        }
    }
    return implode('; ', array_map(
        static fn ($g) => ($g['from'] === $g['to'] ? WEEKDAY_SHORT[$g['from']] : WEEKDAY_SHORT[$g['from']] . '-' . WEEKDAY_SHORT[$g['to']]) . ' ' . $g['sig'],
        $groups
    ));
}

/** Leave that has not ended yet (or all of it), soonest first. */
function doctor_leave_list(int $doctorId, bool $includePast = false): array
{
    return db_all(
        'SELECT l.*, u.full_name AS created_by_name FROM doctor_leave l JOIN users u ON u.id = l.created_by
          WHERE l.doctor_id = ?' . ($includePast ? '' : ' AND l.end_date >= ?') . ' ORDER BY l.start_date, l.id',
        $includePast ? [$doctorId] : [$doctorId, app_today()]
    );
}

/* ---------- Conflicts with appointments already booked ---------- */

/** SQL (for the appointments table aliased "a") that is true when a booked appointment can no longer be kept. */
function schedule_conflict_condition(): string
{
    return "(EXISTS (SELECT 1 FROM doctor_leave l WHERE l.doctor_id = a.doctor_id AND a.appointment_date BETWEEN l.start_date AND l.end_date)
          OR (EXISTS (SELECT 1 FROM doctor_schedules s0 WHERE s0.doctor_id = a.doctor_id)
              AND NOT EXISTS (SELECT 1 FROM doctor_schedules s WHERE s.doctor_id = a.doctor_id AND s.weekday = WEEKDAY(a.appointment_date) + 1
                              AND s.start_time <= a.start_time AND s.end_time >= a.end_time)))";
}

/**
 * Booked appointments from today on that fall inside the doctor's leave or outside the doctor's hours.
 * Optionally for one doctor. Each row has a "why" of "leave" or "hours".
 */
function schedule_conflicts(?int $doctorId = null, int $limit = 200): array
{
    $params = [app_today()];
    $sql = "SELECT a.id, a.patient_id, a.doctor_id, a.appointment_date, a.start_time, a.end_time, p.op_number, p.first_name, p.middle_name, p.last_name, p.phone,
                   d.full_name AS doctor_name,
                   IF(EXISTS (SELECT 1 FROM doctor_leave l WHERE l.doctor_id = a.doctor_id AND a.appointment_date BETWEEN l.start_date AND l.end_date), 'leave', 'hours') AS why
              FROM appointments a JOIN patients p ON p.id = a.patient_id JOIN users d ON d.id = a.doctor_id
             WHERE a.status = 'Booked' AND a.appointment_date >= ? AND " . schedule_conflict_condition();
    if ($doctorId !== null) {
        $sql .= ' AND a.doctor_id = ?';
        $params[] = $doctorId;
    }
    return db_all($sql . ' ORDER BY a.appointment_date, a.start_time, a.id LIMIT ' . (int) $limit, $params);
}

/** How many booked appointments need to be moved, for the notification bell and sidebar badge. */
function schedule_conflict_count(?int $doctorId = null): int
{
    $params = [app_today()];
    $sql = "SELECT COUNT(*) FROM appointments a WHERE a.status = 'Booked' AND a.appointment_date >= ? AND " . schedule_conflict_condition();
    if ($doctorId !== null) {
        $sql .= ' AND a.doctor_id = ?';
        $params[] = $doctorId;
    }
    return (int) db_value($sql, $params);
}

/** One line per active doctor for the manager's overview. */
function schedule_overview(): array
{
    $conflicts = [];
    foreach (db_all(
        "SELECT a.doctor_id, COUNT(*) AS n FROM appointments a WHERE a.status = 'Booked' AND a.appointment_date >= ? AND " . schedule_conflict_condition() . ' GROUP BY a.doctor_id',
        [app_today()]
    ) as $r) {
        $conflicts[(int) $r['doctor_id']] = (int) $r['n'];
    }
    $rows = [];
    foreach (list_doctors() as $d) {
        $id = (int) $d['id'];
        $upcoming = doctor_leave_list($id);
        $rows[] = $d + [
            'has_schedule' => doctor_has_schedule($id),
            'summary' => schedule_summary($id),
            'on_leave_today' => doctor_leave_on($id, app_today()) !== null,
            'next_leave' => $upcoming[0] ?? null,
            'conflicts' => $conflicts[$id] ?? 0,
        ];
    }
    return $rows;
}

/* ---------- Writing ---------- */

/**
 * Read the schedule form: sch[weekday][0|1][from|to]. Returns [blocks, errors] where blocks are [weekday, from, to]
 * and errors are keyed "d<weekday>" (shown on that day's row) or "_" (about the whole form).
 */
function parse_schedule_input(array $in): array
{
    $choices = schedule_time_choices();
    $sch = is_array($in['sch'] ?? null) ? $in['sch'] : [];
    $blocks = [];
    $errors = [];
    foreach ((array) config('clinic.days') as $wd) {
        $wd = (int) $wd;
        $day = [];
        for ($n = 0; $n < 2; $n++) {
            $cell = is_array($sch[$wd][$n] ?? null) ? $sch[$wd][$n] : [];
            $from = clean_text($cell['from'] ?? '', 5);
            $to = clean_text($cell['to'] ?? '', 5);
            if ($from === '' && $to === '') {
                continue;
            }
            if ($from === '' || $to === '') {
                $errors["d$wd"] = 'Give both a start and an end time, or leave both empty.';
            } elseif (!in_array($from, $choices, true) || !in_array($to, $choices, true)) {
                $errors["d$wd"] = 'Choose times from the list.';
            } elseif ($from >= $to) {
                $errors["d$wd"] = 'The end time must be after the start time.';
            } else {
                $day[] = [$from, $to];
            }
        }
        usort($day, static fn ($a, $b) => strcmp($a[0], $b[0]));
        if (count($day) === 2 && $day[1][0] < $day[0][1]) {
            $errors["d$wd"] = 'The two blocks overlap. Make the second start when the first ends or later.';
        }
        if (!isset($errors["d$wd"])) {
            foreach ($day as [$from, $to]) {
                $blocks[] = [$wd, $from, $to];
            }
        }
    }
    if (!$blocks && !$errors) {
        $errors['_'] = 'Enter working hours for at least one day. To go back to the clinic opening hours, use "Remove personal schedule".';
    }
    return [$blocks, $errors];
}

/**
 * Replace a doctor's weekly hours. Returns ['ok' => true, 'conflicts' => n] (n = booked appointments that no longer fit)
 * or ['ok' => false, 'errors' => [...]].
 */
function save_doctor_schedule(int $doctorId, array $in, int $userId): array
{
    if (!is_active_doctor($doctorId)) {
        return ['ok' => false, 'errors' => ['_' => 'Choose an active doctor.']];
    }
    [$blocks, $errors] = parse_schedule_input($in);
    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }
    db_tx(function () use ($doctorId, $blocks, $userId): void {
        // Booking locks the same row, so a booking and a schedule change are handled one after the other.
        db_value('SELECT id FROM users WHERE id = ? FOR UPDATE', [$doctorId]);
        db_exec('DELETE FROM doctor_schedules WHERE doctor_id = ?', [$doctorId]);
        foreach ($blocks as [$wd, $from, $to]) {
            db_exec('INSERT INTO doctor_schedules (doctor_id, weekday, start_time, end_time, created_by) VALUES (?, ?, ?, ?, ?)', [$doctorId, $wd, $from . ':00', $to . ':00', $userId]);
        }
        audit('update', 'schedule', $doctorId, 'Weekly hours set (' . count($blocks) . ' blocks)', $userId);
    });
    return ['ok' => true, 'conflicts' => schedule_conflict_count($doctorId)];
}

/** Go back to the clinic's opening hours for this doctor. */
function clear_doctor_schedule(int $doctorId, int $userId): bool
{
    return db_tx(function () use ($doctorId, $userId): bool {
        db_value('SELECT id FROM users WHERE id = ? FOR UPDATE', [$doctorId]);
        $n = db_exec('DELETE FROM doctor_schedules WHERE doctor_id = ?', [$doctorId]);
        if ($n > 0) {
            audit('update', 'schedule', $doctorId, 'Personal schedule removed; clinic hours apply', $userId);
        }
        return $n > 0;
    });
}

/**
 * Record leave. Returns ['ok' => true, 'id' => n, 'conflicts' => booked appointments inside the period]
 * or ['ok' => false, 'errors' => [field => message]].
 */
function add_doctor_leave(int $doctorId, array $in, int $userId): array
{
    $errors = [];
    $start = clean_text($in['start_date'] ?? '', 10);
    $end = clean_text($in['end_date'] ?? '', 10);
    $type = clean_text($in['type'] ?? '', 20);
    $reason = clean_text($in['reason'] ?? '', 255);
    if (!is_active_doctor($doctorId)) {
        $errors['doctor_id'] = 'Choose an active doctor.';
    }
    if (!is_valid_date($start)) {
        $errors['start_date'] = 'Enter the first day of leave.';
    }
    if (!is_valid_date($end)) {
        $errors['end_date'] = 'Enter the last day of leave.';
    }
    if (is_valid_date($start) && is_valid_date($end)) {
        if ($end < $start) {
            $errors['end_date'] = 'The last day cannot be before the first day.';
        } elseif ($end < app_today()) {
            $errors['end_date'] = 'That leave has already ended. Record leave that is current or still to come.';
        } elseif ((new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days >= LEAVE_MAX_DAYS) {
            $errors['end_date'] = 'Leave can be recorded for up to ' . LEAVE_MAX_DAYS . ' days at a time.';
        } elseif (!isset($errors['doctor_id']) && db_value('SELECT id FROM doctor_leave WHERE doctor_id = ? AND start_date <= ? AND end_date >= ?', [$doctorId, $end, $start])) {
            $errors['start_date'] = 'This overlaps leave that is already recorded for this doctor. Remove that first or choose other dates.';
        }
    }
    if (!in_array($type, LEAVE_TYPES, true)) {
        $errors['type'] = 'Choose the type of leave.';
    }
    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }
    $id = db_insert(
        'INSERT INTO doctor_leave (doctor_id, start_date, end_date, type, reason, created_by) VALUES (?, ?, ?, ?, ?, ?)',
        [$doctorId, $start, $end, $type, $reason !== '' ? $reason : null, $userId]
    );
    // The audit trail records that leave was recorded and for which dates, not the reason.
    audit('create', 'leave', $id, "Leave recorded for doctor #$doctorId, $start to $end", $userId);
    $conflicts = (int) db_value("SELECT COUNT(*) FROM appointments WHERE doctor_id = ? AND status = 'Booked' AND appointment_date BETWEEN ? AND ?", [$doctorId, $start, $end]);
    return ['ok' => true, 'id' => $id, 'conflicts' => $conflicts];
}

/** Remove leave that has not ended yet. Returns an error message, or null on success. */
function delete_doctor_leave(int $leaveId, int $userId): ?string
{
    $l = db_one('SELECT * FROM doctor_leave WHERE id = ?', [$leaveId]);
    if (!$l) {
        return 'That leave record does not exist.';
    }
    if ($l['end_date'] < app_today()) {
        return 'That leave has already ended and is kept as a record.';
    }
    db_exec('DELETE FROM doctor_leave WHERE id = ?', [$leaveId]);
    audit('delete', 'leave', $leaveId, "Leave removed for doctor #{$l['doctor_id']}, {$l['start_date']} to {$l['end_date']}", $userId);
    return null;
}

/* ---------- Display ---------- */

/** A small table of the week: day and hours. $schedule comes from doctor_schedule(). */
function render_week_hours(array $schedule): string
{
    if (!$schedule) {
        return '<p class="muted">No personal schedule is set, so the clinic opening hours apply (' . e((string) config('clinic.open')) . ' to ' . e((string) config('clinic.close')) . ').</p>';
    }
    $html = '<div class="table-wrap" tabindex="0" role="group" aria-label="Table: scroll sideways if it does not fit"><table class="data"><thead><tr><th>Day</th><th>Hours</th></tr></thead><tbody>';
    foreach ((array) config('clinic.days') as $wd) {
        $blocks = $schedule[(int) $wd] ?? [];
        $html .= '<tr><td>' . e(WEEKDAY_NAMES[(int) $wd]) . '</td><td>'
            . ($blocks ? e(implode(' and ', array_map(static fn ($b) => $b[0] . ' to ' . $b[1], $blocks))) : '<span class="muted">Not working</span>')
            . '</td></tr>';
    }
    return $html . '</tbody></table></div>';
}
