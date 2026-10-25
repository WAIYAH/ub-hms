<?php
/**
 * Dashboard analytics: the numbers behind the charts and lists on each role's dashboard.
 *
 * Every function reads day-to-day records directly, so a dashboard is always up to date. Day ranges are written as
 * "from the start of the first day, up to but not including the day after the last", so the database can use its
 * indexes (a date wrapped in DATE() would force it to read every row). No function here returns patient names or
 * clinical details except the short lists, and those are only shown to roles that may already see them.
 */
declare(strict_types=1);

/* ---------- Day helpers ---------- */

/** The last $n calendar days ending today (or $end), oldest first, as Y-m-d strings. */
function an_days(int $n = 14, ?string $end = null): array
{
    $end = new DateTimeImmutable($end ?? app_today());
    $out = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        $out[] = $end->modify("-$i days")->format('Y-m-d');
    }
    return $out;
}

/** Short x-axis labels such as "24 Oct". */
function an_labels(array $days): array
{
    return array_map(static fn (string $d): string => (new DateTimeImmutable($d))->format('j M'), $days);
}

/** [start, end-exclusive] datetimes covering the days. */
function an_span(array $days): array
{
    return [$days[0] . ' 00:00:00', (new DateTimeImmutable(end($days)))->modify('+1 day')->format('Y-m-d') . ' 00:00:00'];
}

/** Line up a [date => number] map with the days, filling gaps with zero. */
function an_fill(array $days, array $byDate): array
{
    return array_map(static fn (string $d) => (float) ($byDate[$d] ?? 0), $days);
}

/** Run a "date, number" query between the days' span and return it aligned with the days. */
function an_per_day(array $days, string $sql, array $extra = []): array
{
    [$from, $to] = an_span($days);
    $map = [];
    foreach (db_all($sql, array_merge([$from, $to], $extra)) as $r) {
        $map[$r['d']] = (float) $r['n'];
    }
    return an_fill($days, $map);
}

/* ---------- Counts per day ---------- */

function an_series_new_patients(array $days): array
{
    return an_per_day($days, 'SELECT DATE(created_at) AS d, COUNT(*) AS n FROM patients WHERE created_at >= ? AND created_at < ? GROUP BY DATE(created_at)');
}

function an_series_visits(array $days): array
{
    return an_per_day($days, 'SELECT DATE(visit_date) AS d, COUNT(*) AS n FROM visits WHERE visit_date >= ? AND visit_date < ? GROUP BY DATE(visit_date)');
}

function an_series_triage(array $days): array
{
    return an_per_day($days, 'SELECT DATE(recorded_at) AS d, COUNT(*) AS n FROM triage WHERE recorded_at >= ? AND recorded_at < ? GROUP BY DATE(recorded_at)');
}

/** Consultations per day, for one doctor or for everyone. */
function an_series_consultations(array $days, ?int $doctorId = null): array
{
    return an_per_day(
        $days,
        'SELECT DATE(created_at) AS d, COUNT(*) AS n FROM consultations WHERE created_at >= ? AND created_at < ?' . ($doctorId !== null ? ' AND doctor_id = ?' : '') . ' GROUP BY DATE(created_at)',
        $doctorId !== null ? [$doctorId] : []
    );
}

/** Laboratory requests made and results entered, per day. */
function an_series_lab(array $days): array
{
    return [
        'requested' => an_per_day($days, "SELECT DATE(requested_at) AS d, COUNT(*) AS n FROM lab_requests WHERE requested_at >= ? AND requested_at < ? AND status <> 'Cancelled' GROUP BY DATE(requested_at)"),
        'completed' => an_per_day($days, "SELECT DATE(result_at) AS d, COUNT(*) AS n FROM lab_requests WHERE status = 'Completed' AND result_at >= ? AND result_at < ? GROUP BY DATE(result_at)"),
    ];
}

/** Units of medicine dispensed per day. */
function an_series_dispensed(array $days): array
{
    return an_per_day($days, "SELECT DATE(created_at) AS d, -SUM(quantity) AS n FROM stock_movements WHERE type = 'Dispense' AND created_at >= ? AND created_at < ? GROUP BY DATE(created_at)");
}

/** Money collected per day, for one cashier or for everyone (in whole shillings and cents). */
function an_series_collections(array $days, ?int $userId = null): array
{
    // Net of refunds; a day with more refunded than taken would be negative, and a bar chart shows it as zero.
    return array_map(
        static fn ($v) => max(0.0, (float) $v),
        an_per_day(
            $days,
            'SELECT DATE(paid_at) AS d, SUM(amount) AS n FROM payments WHERE paid_at >= ? AND paid_at < ?' . ($userId !== null ? ' AND received_by = ?' : '') . ' GROUP BY DATE(paid_at)',
            $userId !== null ? [$userId] : []
        )
    );
}

/** Successful and failed sign-ins per day. */
function an_series_signins(array $days): array
{
    return [
        'ok'     => an_per_day($days, "SELECT DATE(created_at) AS d, COUNT(*) AS n FROM audit_log WHERE action = 'login' AND created_at >= ? AND created_at < ? GROUP BY DATE(created_at)"),
        'failed' => an_per_day($days, "SELECT DATE(created_at) AS d, COUNT(*) AS n FROM audit_log WHERE action = 'login_failed' AND created_at >= ? AND created_at < ? GROUP BY DATE(created_at)"),
    ];
}

/** Audit entries per day. */
function an_series_audit(array $days): array
{
    return an_per_day($days, 'SELECT DATE(created_at) AS d, COUNT(*) AS n FROM audit_log WHERE created_at >= ? AND created_at < ? GROUP BY DATE(created_at)');
}

/* ---------- Shares and rankings ---------- */

/** Appointments in the days by outcome: Booked, Attended, Missed, Cancelled. */
function an_appointment_outcomes(array $days): array
{
    $out = array_fill_keys(APPOINTMENT_STATUSES, 0);
    foreach (db_all('SELECT status, COUNT(*) AS n FROM appointments WHERE appointment_date BETWEEN ? AND ? GROUP BY status', [$days[0], end($days)]) as $r) {
        $out[$r['status']] = (int) $r['n'];
    }
    return $out;
}

/** The most common diagnoses (as written by the doctor) in the last $days days, for one doctor or for everyone. */
function an_top_diagnoses(?int $doctorId = null, int $days = 30, int $limit = 5): array
{
    $since = (new DateTimeImmutable(app_today()))->modify('-' . ($days - 1) . ' days')->format('Y-m-d') . ' 00:00:00';
    $sql = "SELECT TRIM(diagnosis) AS label, COUNT(*) AS value FROM consultations WHERE created_at >= ? AND diagnosis IS NOT NULL AND TRIM(diagnosis) <> ''";
    $params = [$since];
    if ($doctorId !== null) {
        $sql .= ' AND doctor_id = ?';
        $params[] = $doctorId;
    }
    return db_all($sql . ' GROUP BY TRIM(diagnosis) ORDER BY value DESC, label LIMIT ' . (int) $limit, $params);
}

/** Medicines dispensed most (units) in the last $days days. */
function an_top_medicines(int $days = 30, int $limit = 5): array
{
    $since = (new DateTimeImmutable(app_today()))->modify('-' . ($days - 1) . ' days')->format('Y-m-d') . ' 00:00:00';
    return db_all(
        "SELECT CONCAT(m.name, IF(m.strength IS NULL OR m.strength = '', '', CONCAT(' ', m.strength))) AS label, -SUM(s.quantity) AS value
           FROM stock_movements s JOIN medicines m ON m.id = s.medicine_id
          WHERE s.type = 'Dispense' AND s.created_at >= ? GROUP BY m.id, m.name, m.strength HAVING value > 0 ORDER BY value DESC, label LIMIT " . (int) $limit,
        [$since]
    );
}

/** Laboratory tests requested most in the last $days days. */
function an_tests_by_type(int $days = 30, int $limit = 5): array
{
    $since = (new DateTimeImmutable(app_today()))->modify('-' . ($days - 1) . ' days')->format('Y-m-d') . ' 00:00:00';
    return db_all(
        "SELECT t.name AS label, COUNT(*) AS value FROM lab_requests r JOIN lab_tests t ON t.id = r.test_id
          WHERE r.requested_at >= ? AND r.status <> 'Cancelled' GROUP BY t.id, t.name ORDER BY value DESC, label LIMIT " . (int) $limit,
        [$since]
    );
}

/** Active medicines by stock status: OK, Low, Out. */
function an_stock_status(): array
{
    $out = ['OK' => 0, 'Low' => 0, 'Out' => 0];
    foreach (medicine_stock('', true) as $m) {
        $out[$m['status']]++;
    }
    return $out;
}

/** Active user accounts per role, as [label, value] rows (roles with nobody are left out). */
function an_users_by_role(): array
{
    return db_all('SELECT r.name AS label, COUNT(*) AS value FROM users u JOIN roles r ON r.id = u.role_id WHERE u.is_active = 1 GROUP BY r.id, r.name ORDER BY value DESC, label');
}

/** Doctors by number of visits in the days. */
function an_top_doctors(array $days, int $limit = 5): array
{
    [$from, $to] = an_span($days);
    return db_all(
        'SELECT u.full_name AS label, COUNT(*) AS value FROM visits v JOIN users u ON u.id = v.doctor_id WHERE v.visit_date >= ? AND v.visit_date < ? GROUP BY u.id, u.full_name ORDER BY value DESC, label LIMIT ' . (int) $limit,
        [$from, $to]
    );
}

/* ---------- Short lists ---------- */

/** Today's booked and attended appointments, in time order. */
function an_todays_appointments(int $limit = 8, ?int $doctorId = null): array
{
    $sql = "SELECT a.id, a.start_time, a.status, a.reason, p.op_number, p.first_name, p.middle_name, p.last_name, d.full_name AS doctor_name
              FROM appointments a JOIN patients p ON p.id = a.patient_id JOIN users d ON d.id = a.doctor_id
             WHERE a.appointment_date = ? AND a.status IN ('Booked','Attended')";
    $params = [app_today()];
    if ($doctorId !== null) {
        $sql .= ' AND a.doctor_id = ?';
        $params[] = $doctorId;
    }
    return db_all($sql . ' ORDER BY a.start_time, a.id LIMIT ' . (int) $limit, $params);
}

/**
 * Invoices still to be paid, oldest first. Invoice numbers rise with time, so walking them in id order and stopping
 * after $limit gives the oldest without sorting every unpaid invoice (which is slow when there are thousands).
 */
function an_unpaid_invoices(int $limit = 8): array
{
    return db_all(
        "SELECT i.id, i.invoice_no, i.total, i.amount_paid, i.status, i.created_at, p.op_number, p.first_name, p.middle_name, p.last_name
           FROM invoices i JOIN visits v ON v.id = i.visit_id JOIN patients p ON p.id = v.patient_id
          WHERE i.status IN ('Unpaid', 'Partially paid') ORDER BY i.id LIMIT " . (int) $limit
    );
}

/** Test results a doctor has not yet had a chance to act on (completed tests on visits that are still open), newest first. */
function an_results_ready(int $doctorId, int $limit = 6): array
{
    return db_all(
        "SELECT r.id, r.is_abnormal, r.result_at, t.name AS test_name, p.op_number, p.first_name, p.middle_name, p.last_name
           FROM lab_requests r JOIN visits v ON v.id = r.visit_id AND v.status = 'Open'
           JOIN lab_tests t ON t.id = r.test_id JOIN patients p ON p.id = v.patient_id
          WHERE r.requested_by = ? AND r.status = 'Completed' ORDER BY r.result_at DESC, r.id DESC LIMIT " . (int) $limit,
        [$doctorId]
    );
}

/** The newest audit entries (who, what, when). */
function an_recent_audit(int $limit = 8): array
{
    return db_all(
        'SELECT a.action, a.entity, a.details, a.created_at, u.full_name, u.username FROM audit_log a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT ' . (int) $limit
    );
}

/** Minutes between a datetime and now, as text such as "12 min" or "1 h 5 min". */
function an_waiting_text(string $since): string
{
    $mins = max(0, (int) floor((app_now()->getTimestamp() - (new DateTimeImmutable($since))->getTimestamp()) / 60));
    if ($mins < 60) {
        return $mins . ' min';
    }
    $h = intdiv($mins, 60);
    return $h >= 24 ? intdiv($h, 24) . ' d' : $h . ' h ' . ($mins % 60) . ' min';
}

/* ---------- Words and shapes shared by the dashboards ---------- */

/** "Good morning", "Good afternoon" or "Good evening" by the hospital's clock. */
function dash_greeting(?DateTimeImmutable $now = null): string
{
    $h = (int) ($now ?? app_now())->format('G');
    return $h < 12 ? 'Good morning' : ($h < 17 ? 'Good afternoon' : 'Good evening');
}

/** The name to greet: the first word that is not a title such as "Dr." or "Sr.". */
function dash_first_name(string $fullName): string
{
    foreach (preg_split('/\s+/u', trim($fullName)) ?: [] as $w) {
        if ($w !== '' && substr($w, -1) !== '.') {
            return $w;
        }
    }
    return trim($fullName);
}

/** Two capital letters for the avatar: first and last word, ignoring titles such as "Dr.". */
function user_initials(string $fullName): string
{
    $words = array_values(array_filter(preg_split('/\s+/u', trim($fullName)) ?: [], static fn ($w) => $w !== '' && substr($w, -1) !== '.'));
    if (!$words) {
        return '?';
    }
    $first = mb_strtoupper(mb_substr($words[0], 0, 1));
    $last = count($words) > 1 ? mb_strtoupper(mb_substr($words[count($words) - 1], 0, 1)) : '';
    return $first . $last;
}
