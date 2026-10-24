<?php
/**
 * Module 5: management reports. All reports read directly from the day-to-day
 * records, so they are always up to date. Dates are inclusive.
 */
declare(strict_types=1);

/** Read from/to from the request, defaulting to the first of this month until today. */
function report_range(array $src): array
{
    $from = clean_text($src['from'] ?? '', 10);
    $to = clean_text($src['to'] ?? '', 10);
    if (!is_valid_date($to)) {
        $to = app_today();
    }
    if (!is_valid_date($from)) {
        $from = (new DateTimeImmutable($to))->format('Y-m-01');
    }
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    return [$from, $to];
}

/** Every date from $from to $to as a list (limited to 366 days). */
function date_list(string $from, string $to): array
{
    $out = [];
    $d = new DateTimeImmutable($from);
    $end = new DateTimeImmutable($to);
    while ($d <= $end && count($out) < 366) {
        $out[] = $d->format('Y-m-d');
        $d = $d->modify('+1 day');
    }
    return $out;
}

/* ---------- Attendance ---------- */

function report_attendance(string $from, string $to): array
{
    $days = [];
    foreach (date_list($from, $to) as $d) {
        $days[$d] = ['date' => $d, 'booked' => 0, 'attended' => 0, 'missed' => 0, 'cancelled' => 0, 'walkins' => 0, 'visits' => 0, 'new_patients' => 0];
    }
    foreach (db_all(
        'SELECT appointment_date AS d, status, COUNT(*) AS n FROM appointments WHERE appointment_date BETWEEN ? AND ? GROUP BY appointment_date, status',
        [$from, $to]
    ) as $r) {
        $key = strtolower($r['status']);
        if (isset($days[$r['d']])) {
            $days[$r['d']][$key === 'booked' ? 'booked' : $key] += (int) $r['n'];
        }
    }
    foreach (db_all(
        'SELECT DATE(visit_date) AS d, COUNT(*) AS n, SUM(appointment_id IS NULL) AS walkins FROM visits WHERE DATE(visit_date) BETWEEN ? AND ? GROUP BY DATE(visit_date)',
        [$from, $to]
    ) as $r) {
        $days[$r['d']]['visits'] = (int) $r['n'];
        $days[$r['d']]['walkins'] = (int) $r['walkins'];
    }
    foreach (db_all('SELECT DATE(created_at) AS d, COUNT(*) AS n FROM patients WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY DATE(created_at)', [$from, $to]) as $r) {
        $days[$r['d']]['new_patients'] = (int) $r['n'];
    }
    $totals = ['booked' => 0, 'attended' => 0, 'missed' => 0, 'cancelled' => 0, 'walkins' => 0, 'visits' => 0, 'new_patients' => 0];
    foreach ($days as $d) {
        foreach ($totals as $k => $_) {
            $totals[$k] += $d[$k];
        }
    }
    $seen = $totals['attended'] + $totals['missed'];
    $doctors = db_all(
        "SELECT u.full_name, COUNT(DISTINCT v.id) AS visits, COUNT(DISTINCT CASE WHEN a.id IS NOT NULL THEN a.id END) AS appointments
           FROM visits v JOIN users u ON u.id = v.doctor_id LEFT JOIN appointments a ON a.id = v.appointment_id
          WHERE DATE(v.visit_date) BETWEEN ? AND ? GROUP BY u.id, u.full_name ORDER BY visits DESC, u.full_name",
        [$from, $to]
    );
    return [
        'days' => array_values($days), 'totals' => $totals, 'doctors' => $doctors,
        'attendance_rate' => $seen > 0 ? round($totals['attended'] * 100 / $seen, 1) : null,
    ];
}

/* ---------- Revenue ---------- */

function report_revenue(string $from, string $to): array
{
    $days = [];
    foreach (date_list($from, $to) as $d) {
        $days[$d] = ['date' => $d, 'billed' => '0.00', 'collected' => '0.00', 'payments' => 0];
    }
    foreach (db_all('SELECT DATE(created_at) AS d, SUM(total) AS t FROM invoices WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY DATE(created_at)', [$from, $to]) as $r) {
        $days[$r['d']]['billed'] = number_format((float) $r['t'], 2, '.', '');
    }
    // Money is added up in whole cents so totals never drift.
    $dayCents = [];
    $methodCents = array_fill_keys(PAYMENT_METHODS, 0);
    $methodCount = array_fill_keys(PAYMENT_METHODS, 0);
    $collected = 0;
    foreach (db_all('SELECT DATE(paid_at) AS d, method, SUM(amount) AS a, COUNT(*) AS n FROM payments WHERE DATE(paid_at) BETWEEN ? AND ? GROUP BY DATE(paid_at), method', [$from, $to]) as $r) {
        $c = to_cents($r['a']);
        $dayCents[$r['d']] = ($dayCents[$r['d']] ?? 0) + $c;
        $days[$r['d']]['payments'] += (int) $r['n'];
        $methodCents[$r['method']] += $c;
        $methodCount[$r['method']] += (int) $r['n'];
        $collected += $c;
    }
    foreach ($dayCents as $d => $c) {
        $days[$d]['collected'] = number_format($c / 100, 2, '.', '');
    }
    $methods = [];
    foreach (PAYMENT_METHODS as $m) {
        $methods[$m] = ['amount' => number_format($methodCents[$m] / 100, 2, '.', ''), 'count' => $methodCount[$m]];
    }
    $categories = array_fill_keys(['Consultation', 'Laboratory', 'Pharmacy', 'Service'], '0.00');
    foreach (db_all(
        'SELECT ii.source, SUM(ii.line_total) AS t FROM invoice_items ii JOIN invoices i ON i.id = ii.invoice_id WHERE DATE(i.created_at) BETWEEN ? AND ? GROUP BY ii.source',
        [$from, $to]
    ) as $r) {
        $categories[$r['source']] = number_format((float) $r['t'], 2, '.', '');
    }
    $billed = (string) db_value('SELECT COALESCE(SUM(total), 0) FROM invoices WHERE DATE(created_at) BETWEEN ? AND ?', [$from, $to]);
    $outstanding = (string) db_value("SELECT COALESCE(SUM(total - amount_paid), 0) FROM invoices WHERE DATE(created_at) BETWEEN ? AND ? AND status <> 'Paid'", [$from, $to]);
    return [
        'days' => array_values($days), 'methods' => $methods, 'categories' => $categories,
        'billed' => number_format((float) $billed, 2, '.', ''), 'collected' => number_format($collected / 100, 2, '.', ''),
        'outstanding' => number_format((float) $outstanding, 2, '.', ''),
        'invoices' => (int) db_value('SELECT COUNT(*) FROM invoices WHERE DATE(created_at) BETWEEN ? AND ?', [$from, $to]),
    ];
}

/* ---------- Stock ---------- */

function report_stock(string $from, string $to): array
{
    $moves = [];
    foreach (db_all(
        "SELECT medicine_id, type, SUM(quantity) AS q FROM stock_movements WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY medicine_id, type",
        [$from, $to]
    ) as $r) {
        $moves[(int) $r['medicine_id']][$r['type']] = (int) $r['q'];
    }
    $rows = [];
    foreach (medicine_stock() as $m) {
        $mv = $moves[(int) $m['id']] ?? [];
        $m['received'] = $mv['Receive'] ?? 0;
        $m['dispensed'] = abs($mv['Dispense'] ?? 0);
        $m['adjusted'] = $mv['Adjust'] ?? 0;
        $m['value'] = number_format($m['on_hand'] * (float) $m['unit_price'], 2, '.', '');
        $rows[] = $m;
    }
    return ['rows' => $rows, 'alerts' => stock_alerts(), 'value' => number_format(array_sum(array_column($rows, 'value')), 2, '.', '')];
}

/* ---------- Laboratory workload ---------- */

function report_lab(string $from, string $to): array
{
    $tests = db_all(
        "SELECT t.name,
                COUNT(r.id) AS requested,
                SUM(r.status = 'Completed') AS completed,
                SUM(r.status = 'Cancelled') AS cancelled,
                SUM(r.status IN ('Requested','In progress')) AS pending,
                SUM(r.status = 'Completed' AND r.is_abnormal = 1) AS abnormal,
                AVG(CASE WHEN r.status = 'Completed' THEN TIMESTAMPDIFF(MINUTE, r.requested_at, r.result_at) END) AS avg_minutes
           FROM lab_tests t JOIN lab_requests r ON r.test_id = t.id
          WHERE DATE(r.requested_at) BETWEEN ? AND ? GROUP BY t.id, t.name ORDER BY requested DESC, t.name",
        [$from, $to]
    );
    $doctors = db_all(
        "SELECT u.full_name, COUNT(r.id) AS requested, SUM(r.status = 'Completed') AS completed
           FROM lab_requests r JOIN users u ON u.id = r.requested_by WHERE DATE(r.requested_at) BETWEEN ? AND ? GROUP BY u.id, u.full_name ORDER BY requested DESC",
        [$from, $to]
    );
    $tot = ['requested' => 0, 'completed' => 0, 'cancelled' => 0, 'pending' => 0, 'abnormal' => 0];
    foreach ($tests as &$t) {
        foreach ($tot as $k => $_) {
            $t[$k] = (int) $t[$k];
            $tot[$k] += $t[$k];
        }
        $t['avg_minutes'] = $t['avg_minutes'] !== null ? (int) round((float) $t['avg_minutes']) : null;
    }
    unset($t);
    $avg = db_value("SELECT AVG(TIMESTAMPDIFF(MINUTE, requested_at, result_at)) FROM lab_requests WHERE status = 'Completed' AND DATE(requested_at) BETWEEN ? AND ?", [$from, $to]);
    return ['tests' => $tests, 'doctors' => $doctors, 'totals' => $tot, 'avg_minutes' => $avg !== null ? (int) round((float) $avg) : null];
}

/** The date-range form shown at the top of every report, with a CSV download button. */
function render_report_filters(string $from, string $to, string $path): string
{
    $csv = url($path, ['from' => $from, 'to' => $to, 'format' => 'csv']);
    return '<form method="get" class="filters no-print"><div class="field"><label for="from">From</label><input id="from" name="from" type="date" value="' . e($from) . '"></div>'
        . '<div class="field"><label for="to">To</label><input id="to" name="to" type="date" value="' . e($to) . '"></div>'
        . '<button class="btn" type="submit">Show report</button>'
        . '<a class="btn btn-secondary" href="' . e($csv) . '">Download CSV</a>'
        . '<button class="btn btn-secondary" type="button" data-print>Print</button></form>';
}

/* ---------- CSV export ---------- */

/** Neutralise spreadsheet formulas: a cell that starts with = + - @ is prefixed so it opens as text. */
function csv_safe($value): string
{
    $s = (string) $value;
    if ($s === '' || preg_match('/^-?\d+(\.\d+)?$/', $s)) {
        return $s; // plain numbers, including negative ones, are safe
    }
    return strpbrk($s[0], "=+-@\t\r") !== false ? "'" . $s : $s;
}

/** Send a CSV download and stop. */
function send_csv(string $filename, array $header, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF"); // lets Excel read UTF-8
    fputcsv($out, array_map('csv_safe', $header), ',', '"', '');
    foreach ($rows as $row) {
        fputcsv($out, array_map('csv_safe', $row), ',', '"', '');
    }
    fclose($out);
    exit;
}
