<?php
/**
 * Dashboards. Each role gets a greeting, quick-action buttons, coloured KPI cards, charts and short live lists,
 * chosen by what the signed-in user's role may see. The numbers come from includes/analytics.php and the module
 * files (laboratory, pharmacy, billing), so the dashboard always shows the current state of the records.
 */
declare(strict_types=1);

/** Chart colour (0 to 7, see CHART_COLOURS) for each payment method, the same on every dashboard. */
const PAYMENT_COLOURS = ['Cash' => 6, 'M-Pesa' => 0, 'Card' => 1, 'Insurance' => 2];

/* ---------- KPI cards ---------- */

/** Pick an icon and a colour for a card from its label, so the module files can keep calling dashboard_tile($label, $value, $href). */
function kpi_style(string $label): array
{
    $rules = [
        '/locked/i' => ['lock', 'amber'],
        '/access requests|messages/i' => ['inbox', 'violet'],
        '/audit/i' => ['history', 'cyan'],
        '/user accounts/i' => ['users', 'teal'],
        '/registered today/i' => ['user-plus', 'green'],
        '/patients registered/i' => ['users', 'blue'],
        '/not yet checked in|booked/i' => ['calendar-check', 'violet'],
        '/appointments/i' => ['calendar', 'blue'],
        '/open visits|in the clinic/i' => ['activity', 'cyan'],
        '/triage/i' => ['thermometer', 'amber'],
        '/waiting for a doctor/i' => ['stethoscope', 'violet'],
        '/results ready/i' => ['file-check', 'green'],
        '/consultations/i' => ['stethoscope', 'teal'],
        '/abnormal/i' => ['alert', 'red'],
        '/completed/i' => ['check-circle', 'green'],
        '/tests/i' => ['flask', 'amber'],
        '/prescriptions/i' => ['pill', 'violet'],
        '/dispensed/i' => ['pill', 'teal'],
        '/stock/i' => ['box', 'amber'],
        '/expir/i' => ['clock', 'amber'],
        '/unpaid|waiting for payment|still to collect/i' => ['wallet', 'amber'],
        '/collected|receipts|paid/i' => ['banknote', 'green'],
        '/visits/i' => ['activity', 'blue'],
    ];
    foreach ($rules as $pattern => $style) {
        if (preg_match($pattern, $label)) {
            return $style;
        }
    }
    return ['chart', 'teal'];
}

/**
 * One coloured KPI card. $class may be 'warn' or 'danger' (the colour then follows the state);
 * $tone, $icon and $spark (a list of numbers for a small line) are optional.
 */
function dashboard_tile(string $label, $value, string $href = '', string $class = '', string $icon = '', string $tone = '', array $spark = []): string
{
    [$ic0, $tone0] = kpi_style($label);
    $tone = $tone !== '' ? $tone : ($class === 'danger' ? 'red' : ($class === 'warn' ? 'amber' : $tone0));
    $sparkIndex = array_search($tone, ['teal', 'blue', 'violet', 'amber', 'red', 'cyan', 'green', 'pink'], true);
    $inner = '<span class="kpi-ic">' . icon($icon !== '' ? $icon : $ic0) . '</span>'
        . '<div class="kpi-body"><div class="num">' . e($value) . '</div><div class="label">' . e($label) . '</div></div>'
        . ($spark ? '<span class="kpi-spark">' . chart_spark($spark, $sparkIndex === false ? 0 : (int) $sparkIndex) . '</span>' : '')
        . ($href !== '' ? '<span class="kpi-go">' . icon('arrow-right') . '</span>' : '');
    return '<div class="stat kpi kpi-' . e($tone) . ($class !== '' ? ' ' . e($class) : '') . (mb_strlen((string) $value) > 6 ? ' kpi-long' : '') . '">'
        . ($href !== '' ? '<a class="kpi-link" href="' . e(url($href)) . '">' . $inner . '</a>' : $inner) . '</div>';
}

/** The KPI cards the module files and the core records provide for this user (kept for the tests and for callers that want only the cards). */
function dashboard_tiles(array $user): array
{
    $t = [];
    $today = app_today();
    $doctorId = (int) $user['id'];

    if (user_can('appointments.view')) {
        $t[] = dashboard_tile(
            $user['role'] === 'doctor' ? 'My appointments today' : 'Appointments today',
            db_value(
                "SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND status IN ('Booked','Attended')" . ($user['role'] === 'doctor' ? ' AND doctor_id = ?' : ''),
                $user['role'] === 'doctor' ? [$today, $doctorId] : [$today]
            ),
            'appointments/index.php'
        );
    }
    if (user_can('visits.checkin')) {
        $t[] = dashboard_tile('Booked, not yet checked in', db_value("SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND status = 'Booked'", [$today]), 'appointments/index.php');
        $t[] = dashboard_tile('Open visits', db_value("SELECT COUNT(*) FROM visits WHERE status = 'Open'"), 'visits/queue.php');
    }
    if (user_can('triage.record')) {
        $t[] = dashboard_tile(
            'Waiting for triage',
            db_value("SELECT COUNT(*) FROM visits v WHERE v.status = 'Open' AND NOT EXISTS (SELECT 1 FROM triage t WHERE t.visit_id = v.id)"),
            'visits/queue.php?stage=triage'
        );
    }
    if (user_can('consult.record')) {
        $t[] = dashboard_tile(
            'Patients waiting for a doctor',
            db_value(
                "SELECT COUNT(*) FROM visits v WHERE v.status = 'Open' AND EXISTS (SELECT 1 FROM triage t WHERE t.visit_id = v.id)
                    AND NOT EXISTS (SELECT 1 FROM consultations c WHERE c.visit_id = v.id) AND (v.doctor_id IS NULL OR v.doctor_id = ?)",
                [$doctorId]
            ),
            'visits/queue.php?stage=consult'
        );
    }
    foreach (['dashboard_tiles_laboratory', 'dashboard_tiles_pharmacy', 'dashboard_tiles_billing'] as $fn) {
        if (function_exists($fn)) {
            $t = array_merge($t, $fn($user));
        }
    }
    return $t;
}

function dashboard_alerts(array $user): string
{
    $out = '';
    foreach (['dashboard_alerts_pharmacy'] as $fn) {
        if (function_exists($fn)) {
            $out .= $fn($user);
        }
    }
    return $out;
}

/* ---------- Page pieces ---------- */

/** A panel (card with a heading) in the 12-column grid. $o: span (4, 6, 8 or 12), sub, href, link, class. */
function dash_panel(string $title, string $body, array $o = []): string
{
    $span = in_array((int) ($o['span'] ?? 6), [4, 6, 8, 12], true) ? (int) $o['span'] : 6;
    $id = chart_uid('pn');
    $p = '<section class="panel w-' . $span . (isset($o['class']) ? ' ' . e($o['class']) : '') . '" aria-labelledby="' . $id . '"><header class="panel-head"><div><h2 id="' . $id . '">' . e($title) . '</h2>'
        . (!empty($o['sub']) ? '<p>' . e($o['sub']) . '</p>' : '') . '</div>';
    if (!empty($o['href'])) {
        $p .= '<a class="panel-link" href="' . e(url($o['href'])) . '">' . e($o['link'] ?? 'View all') . icon('arrow-right') . '</a>';
    }
    return $p . '</header><div class="panel-body">' . $body . '</div></section>';
}

/** A compact table for a dashboard panel. $rows is a list of rows; a cell is escaped text, or ['html' => '...'] for trusted markup. */
function dash_table(array $head, array $rows, string $empty = 'Nothing to show.', array $nowrap = []): string
{
    if (!$rows) {
        return '<div class="panel-empty">' . icon('check-circle') . '<span>' . e($empty) . '</span></div>';
    }
    $t = '<div class="mini-wrap" tabindex="0" role="group" aria-label="Table: scroll sideways if it does not fit"><table class="mini"><thead><tr>';
    foreach ($head as $h) {
        $t .= '<th scope="col">' . e($h) . '</th>';
    }
    $t .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $t .= '<tr>';
        foreach ($r as $i => $cell) {
            $t .= '<td' . (in_array($i, $nowrap, true) ? ' class="nw"' : '') . '>' . (is_array($cell) ? $cell['html'] : e($cell)) . '</td>';
        }
        $t .= '</tr>';
    }
    return $t . '</tbody></table></div>';
}

/** A status as a coloured pill (uses the existing badge colours). */
function dash_pill(string $status): array
{
    return ['html' => badge($status)];
}

/** The greeting band: date, greeting, who you are, and the quick-action buttons in different colours. */
function dash_hero(array $user, array $actions): string
{
    $now = app_now();
    $h = '<section class="dash-hero"><div class="hero-text"><p class="hero-date">' . icon('calendar') . e($now->format('l, j F Y')) . '</p>'
        . '<h1>' . e(dash_greeting($now)) . ', ' . e(mb_strtolower((string) $user['full_name']) === mb_strtolower((string) (role_labels()[$user['role']] ?? '')) ? (string) $user['full_name'] : dash_first_name((string) $user['full_name'])) . '</h1>'
        . '<p class="hero-sub">Welcome, <strong>' . e($user['full_name']) . '</strong>. You are signed in as ' . e(role_labels()[$user['role']] ?? $user['role']) . '. Here is what is happening today.</p></div>';
    if ($actions) {
        $h .= '<div class="hero-actions" role="group" aria-label="Quick actions">';
        foreach ($actions as [$label, $path, $ic, $variant]) {
            $h .= '<a class="btn btn-' . e($variant) . ' btn-icon" href="' . e(url($path)) . '">' . icon($ic) . '<span>' . e($label) . '</span></a>';
        }
        $h .= '</div>';
    }
    return $h . '</section>';
}

/** The quick-action buttons for a role: [label, path, icon, colour, permission]. Only the ones the user may use are shown. */
function dash_actions(array $user): array
{
    $all = [
        'admin' => [
            ['Add a user', 'admin/user.php', 'user-plus', 'success', 'users.manage'],
            ['Access requests', 'admin/requests.php', 'inbox', 'violet', 'requests.manage'],
            ['Audit trail', 'admin/audit.php', 'history', 'info', 'audit.view'],
            ['System health', 'admin/health.php', 'server', 'warning', 'system.health'],
        ],
        'manager' => [
            ['Revenue report', 'reports/revenue.php', 'trending-up', 'success', 'reports.revenue'],
            ['Attendance report', 'reports/attendance.php', 'users', 'info', 'reports.attendance'],
            ['Stock report', 'reports/stock.php', 'box', 'violet', 'reports.stock'],
            ['Laboratory workload', 'reports/lab.php', 'droplet', 'warning', 'reports.lab'],
        ],
        'receptionist' => [
            ['Register a patient', 'patients/register.php', 'user-plus', 'success', 'patients.register'],
            ['Book an appointment', 'appointments/book.php', 'calendar-plus', 'info', 'appointments.book'],
            ['Check in a patient', 'visits/checkin.php', 'log-in', 'violet', 'visits.checkin'],
            ['Find a patient', 'patients/index.php', 'search', 'outline', 'patients.view'],
        ],
        'nurse' => [
            ['Triage queue', 'visits/queue.php?stage=triage', 'thermometer', 'warning', 'triage.record'],
            ['Open visits', 'visits/queue.php', 'activity', 'info', 'visits.view'],
            ['Find a patient', 'patients/index.php', 'search', 'outline', 'patients.view'],
        ],
        'doctor' => [
            ['Consultation queue', 'visits/queue.php?stage=consult', 'stethoscope', 'violet', 'consult.record'],
            ['My test results', 'lab/results.php', 'file-check', 'success', 'lab.request'],
            ['Appointment diary', 'appointments/index.php', 'calendar', 'info', 'appointments.view'],
            ['Find a patient', 'patients/index.php', 'search', 'outline', 'patients.view'],
        ],
        'lab' => [
            ['Open the worklist', 'lab/index.php', 'flask', 'warning', 'lab.worklist'],
        ],
        'pharmacist' => [
            ['Prescriptions', 'pharmacy/index.php', 'pill', 'violet', 'pharmacy.dispense'],
            ['Medicine stock', 'pharmacy/stock.php', 'box', 'info', 'pharmacy.stock'],
            ['Receive stock', 'pharmacy/receive.php', 'package-plus', 'success', 'pharmacy.manage'],
            ['Batches', 'pharmacy/batches.php', 'layers', 'warning', 'pharmacy.stock'],
        ],
        'cashier' => [
            ['Invoices', 'billing/index.php', 'receipt', 'success', 'billing.view'],
        ],
    ];
    $out = [];
    foreach ($all[$user['role']] ?? [] as $a) {
        if (user_can($a[4])) {
            $out[] = array_slice($a, 0, 4);
        }
    }
    return $out;
}

/** A person's name from the standard patient columns. */
function dash_patient(array $r): string
{
    return patient_full_name($r);
}

/** Opening a patient from a list. */
function dash_patient_cell(array $r): array
{
    return ['html' => '<strong>' . e(dash_patient($r)) . '</strong><br><span class="muted small">' . e($r['op_number']) . '</span>'];
}

/* ---------- One function per role: returns [kpi cards, panels] ---------- */

function dash_admin(array $user): array
{
    $days = an_days(14);
    $labels = an_labels($days);
    $si = an_series_signins($days);
    $audit = an_series_audit($days);
    $c = shell_counts($user);
    $kpis = [
        dashboard_tile('Active user accounts', db_value('SELECT COUNT(*) FROM users WHERE is_active = 1'), 'admin/users.php'),
        dashboard_tile('Locked accounts', $c['locked'] ?? 0, 'admin/users.php', ($c['locked'] ?? 0) > 0 ? 'warn' : ''),
        dashboard_tile('New access requests and messages', $c['requests_new'] ?? 0, 'admin/requests.php?status=new', ($c['requests_new'] ?? 0) > 0 ? 'warn' : ''),
        dashboard_tile('Audit entries today', (int) end($audit), 'admin/audit.php', '', '', '', $audit),
    ];
    $rows = [];
    foreach (an_recent_audit(7) as $a) {
        $rows[] = [fmt_time(substr($a['created_at'], 11)), (string) ($a['full_name'] ?? 'System'), str_replace('_', ' ', $a['action']) . ($a['entity'] ? ' (' . $a['entity'] . ')' : '')];
    }
    $panels = [
        dash_panel('Sign-ins, last 14 days', chart_bars($labels, [['name' => 'Successful', 'values' => $si['ok'], 'colour' => 0], ['name' => 'Failed', 'values' => $si['failed'], 'colour' => 4]], ['summary' => 'Successful and failed sign-ins per day for the last 14 days']), ['span' => 8, 'sub' => 'Many failures in a day can mean someone is guessing passwords', 'href' => 'admin/audit.php', 'link' => 'Audit trail']),
        dash_panel('Accounts by role', chart_donut(an_users_by_role(), ['summary' => 'Active accounts by role', 'centre' => 'accounts']), ['span' => 4]),
        dash_panel('System activity', chart_area($labels, $audit, ['name' => 'Audit entries', 'summary' => 'Audit trail entries per day for the last 14 days', 'colour' => 5]), ['span' => 6, 'sub' => 'Every sign-in, change and view is recorded']),
        dash_panel('Latest activity', dash_table(['Time', 'Who', 'What'], $rows, 'No activity yet.', [0]), ['span' => 6, 'href' => 'admin/audit.php', 'link' => 'Open the audit trail']),
    ];
    return [$kpis, $panels];
}

function dash_manager(array $user): array
{
    $days = an_days(14);
    $labels = an_labels($days);
    $today = app_today();
    $monthStart = (new DateTimeImmutable($today))->format('Y-m-01');
    $coll = an_series_collections($days);
    $vis = an_series_visits($days);
    $month = report_revenue($monthStart, $today);
    $all = collections_for_day($today);
    $kpis = [
        dashboard_tile('Collected today', fmt_money($all['total']), 'reports/revenue.php', '', '', '', $coll),
        dashboard_tile('Unpaid balances', fmt_money(db_value("SELECT COALESCE(SUM(total - amount_paid), 0) FROM invoices WHERE status <> 'Paid'")), 'reports/revenue.php', 'warn'),
        dashboard_tile('Visits today', (int) end($vis), 'reports/attendance.php', '', '', '', $vis),
    ];
    $kpis = array_merge($kpis, array_slice(dashboard_tiles_pharmacy($user), 0, 2));

    $cats = [];
    foreach ($month['categories'] as $name => $amount) {
        $cats[] = ['label' => $name, 'value' => (float) $amount];
    }
    $methods = [];
    foreach ($month['methods'] as $name => $m) {
        $methods[] = ['label' => $name, 'value' => (float) $m['amount'], 'note' => plural((int) $m['count'], 'payment')];
    }
    $docs = an_top_doctors(an_days(30));
    $panels = [
        dash_panel('Money collected, last 14 days', chart_area($labels, $coll, ['prefix' => 'KSh ', 'name' => 'Collected (KSh)', 'summary' => 'Money collected per day for the last 14 days', 'colour' => 6]), ['span' => 8, 'href' => 'reports/revenue.php', 'link' => 'Revenue report']),
        dash_panel('Where the money comes from', chart_donut($cats, ['prefix' => 'KSh ', 'summary' => 'Money billed this month by kind of service', 'centre' => 'billed']), ['span' => 4, 'sub' => 'This month so far']),
        dash_panel('Patients seen per day', chart_bars($labels, [['name' => 'Visits', 'values' => $vis, 'colour' => 1]], ['summary' => 'Visits per day for the last 14 days']), ['span' => 6, 'href' => 'reports/attendance.php', 'link' => 'Attendance report']),
        dash_panel('How patients pay', chart_hbars($methods, ['prefix' => 'KSh ', 'cycle' => true, 'summary' => 'Money collected this month by payment method']), ['span' => 6, 'sub' => 'This month so far']),
        dash_panel('Busiest doctors', chart_hbars($docs, ['colour' => 2, 'summary' => 'Visits per doctor in the last 30 days']), ['span' => 6, 'sub' => 'Visits in the last 30 days']),
        dash_panel('Most requested tests', chart_hbars(an_tests_by_type(), ['colour' => 3, 'summary' => 'Laboratory tests requested in the last 30 days']), ['span' => 6, 'sub' => 'Last 30 days', 'href' => 'reports/lab.php', 'link' => 'Laboratory report']),
    ];
    $alerts = dashboard_alerts($user);
    if ($alerts !== '') {
        $panels[] = '<div class="w-12 alert-stack">' . $alerts . '</div>';
    }
    return [$kpis, $panels];
}

function dash_receptionist(array $user): array
{
    $days = an_days(14);
    $labels = an_labels($days);
    $np = an_series_new_patients($days);
    $kpis = [
        dashboard_tile('Patients registered', db_value('SELECT COUNT(*) FROM patients'), 'patients/index.php'),
        dashboard_tile('Registered today', (int) end($np), 'patients/register.php', '', '', '', $np),
        dashboard_tile('Appointments today', db_value("SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND status IN ('Booked','Attended')", [app_today()]), 'appointments/index.php'),
        dashboard_tile('Booked, not yet checked in', db_value("SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND status = 'Booked'", [app_today()]), 'appointments/index.php'),
        dashboard_tile('Open visits', db_value("SELECT COUNT(*) FROM visits WHERE status = 'Open'"), 'visits/queue.php'),
    ];
    $appts = [];
    foreach (an_todays_appointments(8) as $a) {
        $appts[] = [fmt_time($a['start_time']), dash_patient_cell($a), $a['doctor_name'], dash_pill($a['status'])];
    }
    $waiting = [];
    foreach (array_slice(visit_queue(), 0, 6) as $v) {
        $waiting[] = [dash_patient_cell($v), $v['stage'], an_waiting_text($v['visit_date'])];
    }
    $out = an_appointment_outcomes($days);
    $slices = [['label' => 'Attended', 'value' => $out['Attended'], 'colour' => 6], ['label' => 'Booked', 'value' => $out['Booked'], 'colour' => 1], ['label' => 'Missed', 'value' => $out['Missed'], 'colour' => 3], ['label' => 'Cancelled', 'value' => $out['Cancelled'], 'colour' => 4]];
    $panels = [
        dash_panel('Appointments today', dash_table(['Time', 'Patient', 'Doctor', 'Status'], $appts, 'No appointments are booked for today.', [0, 3]), ['span' => 8, 'href' => 'appointments/index.php', 'link' => 'Open the diary']),
        dash_panel('In the clinic now', dash_table(['Patient', 'Stage', 'Waiting'], $waiting, 'Nobody is waiting.', [2]), ['span' => 4, 'href' => 'visits/queue.php', 'link' => 'All open visits']),
        dash_panel('New patients, last 14 days', chart_bars($labels, [['name' => 'New patients', 'values' => $np, 'colour' => 6]], ['summary' => 'Patients registered per day for the last 14 days']), ['span' => 6]),
        dash_panel('How appointments ended', chart_donut($slices, ['summary' => 'Appointments in the last 14 days by outcome', 'centre' => 'appointments']), ['span' => 6, 'sub' => 'Last 14 days']),
    ];
    return [$kpis, $panels];
}

function dash_nurse(array $user): array
{
    $days = an_days(14);
    $labels = an_labels($days);
    $tri = an_series_triage($days);
    $queue = visit_queue();
    $stages = ['Waiting for triage' => 0, 'Waiting for doctor' => 0, 'With doctor' => 0];
    foreach ($queue as $v) {
        $stages[$v['stage']] = ($stages[$v['stage']] ?? 0) + 1;
    }
    $kpis = [
        dashboard_tile('Waiting for triage', $stages['Waiting for triage'], 'visits/queue.php?stage=triage', $stages['Waiting for triage'] > 0 ? 'warn' : ''),
        dashboard_tile('Triage done today', (int) end($tri), 'visits/queue.php', '', 'check-circle', 'green', $tri),
        dashboard_tile('Patients in the clinic', count($queue), 'visits/queue.php'),
        dashboard_tile('Appointments today', db_value("SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND status IN ('Booked','Attended')", [app_today()]), 'appointments/index.php'),
    ];
    $rows = [];
    foreach (array_filter($queue, static fn ($v) => $v['stage'] === 'Waiting for triage') as $v) {
        $rows[] = [dash_patient_cell($v), $v['sex'] . ', ' . age_label($v['date_of_birth']), fmt_time(substr($v['visit_date'], 11)), an_waiting_text($v['visit_date'])];
        if (count($rows) >= 8) {
            break;
        }
    }
    $slices = [['label' => 'Waiting for triage', 'value' => $stages['Waiting for triage'], 'colour' => 3], ['label' => 'Waiting for doctor', 'value' => $stages['Waiting for doctor'], 'colour' => 1], ['label' => 'With doctor', 'value' => $stages['With doctor'], 'colour' => 6]];
    $panels = [
        dash_panel('Triage done, last 14 days', chart_bars($labels, [['name' => 'Triage records', 'values' => $tri, 'colour' => 3]], ['summary' => 'Triage records per day for the last 14 days']), ['span' => 8]),
        dash_panel('Where patients are now', chart_donut($slices, ['summary' => 'Open visits by stage', 'centre' => 'in the clinic']), ['span' => 4]),
        dash_panel('Waiting for triage', dash_table(['Patient', 'Sex and age', 'Arrived', 'Waiting'], $rows, 'Nobody is waiting for triage.', [1, 2, 3]), ['span' => 12, 'href' => 'visits/queue.php?stage=triage', 'link' => 'Open the triage queue']),
    ];
    return [$kpis, $panels];
}

function dash_doctor(array $user): array
{
    $days = an_days(14);
    $labels = an_labels($days);
    $id = (int) $user['id'];
    $mine = an_series_consultations($days, $id);
    $tiles = dashboard_tiles($user);
    $kpis = array_merge(array_slice($tiles, 0, 2), [dashboard_tile('Test results ready for my patients', results_ready_count($id), 'lab/results.php', '', '', 'green'), dashboard_tile('My consultations today', (int) end($mine), 'visits/queue.php?stage=consult', '', '', '', $mine)]);
    $queue = [];
    foreach (array_slice(visit_queue('consult', $id), 0, 6) as $v) {
        if ($v['stage'] !== 'Waiting for doctor') {
            continue;
        }
        $queue[] = [dash_patient_cell($v), (string) ($v['reason'] ?? ''), an_waiting_text($v['visit_date'])];
    }
    $ready = [];
    foreach (an_results_ready($id, 6) as $r) {
        $ready[] = [dash_patient_cell($r), $r['test_name'], dash_pill($r['is_abnormal'] ? 'Abnormal' : 'Normal')];
    }
    $panels = [
        dash_panel('My consultations, last 14 days', chart_bars($labels, [['name' => 'Consultations', 'values' => $mine, 'colour' => 2]], ['summary' => 'Consultations you recorded per day for the last 14 days']), ['span' => 8]),
        dash_panel('What I diagnose most', chart_hbars(an_top_diagnoses($id), ['colour' => 0, 'summary' => 'Your most common diagnoses in the last 30 days']), ['span' => 4, 'sub' => 'Last 30 days']),
        dash_panel('Waiting for me', dash_table(['Patient', 'Reason for visit', 'Waiting'], $queue, 'No patient is waiting for a doctor.', [2]), ['span' => 6, 'href' => 'visits/queue.php?stage=consult', 'link' => 'Consultation queue']),
        dash_panel('Test results ready', dash_table(['Patient', 'Test', 'Result'], $ready, 'No new results.'), ['span' => 6, 'href' => 'lab/results.php', 'link' => 'All my results']),
    ];
    return [$kpis, $panels];
}

function dash_lab(array $user): array
{
    $days = an_days(14);
    $labels = an_labels($days);
    $series = an_series_lab($days);
    $abn = (int) db_value("SELECT COUNT(*) FROM lab_requests WHERE status = 'Completed' AND is_abnormal = 1 AND result_at >= ? AND result_at < ?", [app_today() . ' 00:00:00', (new DateTimeImmutable(app_today()))->modify('+1 day')->format('Y-m-d') . ' 00:00:00']);
    $kpis = array_merge(dashboard_tiles_laboratory($user), [
        dashboard_tile('Completed today', (int) end($series['completed']), 'lab/index.php', '', '', '', $series['completed']),
        dashboard_tile('Abnormal results today', $abn, 'lab/index.php', $abn > 0 ? 'warn' : ''),
    ]);
    $rows = [];
    foreach (lab_worklist(LAB_OPEN_STATUSES, 8) as $r) {
        $rows[] = [$r['test_name'], dash_patient_cell($r), $r['requested_by_name'], an_waiting_text($r['requested_at']), dash_pill($r['status'])];
    }
    $panels = [
        dash_panel('Requested and completed, last 14 days', chart_bars($labels, [['name' => 'Requested', 'values' => $series['requested'], 'colour' => 3], ['name' => 'Completed', 'values' => $series['completed'], 'colour' => 6]], ['summary' => 'Laboratory tests requested and completed per day for the last 14 days']), ['span' => 8]),
        dash_panel('Most requested tests', chart_hbars(an_tests_by_type(), ['colour' => 5, 'summary' => 'Laboratory tests requested in the last 30 days']), ['span' => 4, 'sub' => 'Last 30 days']),
        dash_panel('Worklist: oldest first', dash_table(['Test', 'Patient', 'Requested by', 'Waiting', 'Status'], $rows, 'No tests are waiting.', [3, 4]), ['span' => 12, 'href' => 'lab/index.php', 'link' => 'Open the worklist']),
    ];
    return [$kpis, $panels];
}

function dash_pharmacist(array $user): array
{
    $days = an_days(14);
    $labels = an_labels($days);
    $disp = an_series_dispensed($days);
    $kpis = array_merge(dashboard_tiles_pharmacy($user), [dashboard_tile('Units dispensed today', (int) end($disp), 'pharmacy/index.php', '', '', '', $disp)]);
    $rows = [];
    foreach (array_slice(pharmacy_queue(), 0, 6) as $rx) {
        $rows[] = [dash_patient_cell($rx), $rx['doctor_name'], plural((int) $rx['item_count'], 'item'), an_waiting_text($rx['created_at'])];
    }
    $st = an_stock_status();
    $slices = [['label' => 'In stock', 'value' => $st['OK'], 'colour' => 6], ['label' => 'Low', 'value' => $st['Low'], 'colour' => 3], ['label' => 'Out of stock', 'value' => $st['Out'], 'colour' => 4]];
    $panels = [
        dash_panel('Units dispensed, last 14 days', chart_area($labels, $disp, ['name' => 'Units dispensed', 'summary' => 'Units of medicine dispensed per day for the last 14 days', 'colour' => 2]), ['span' => 8]),
        dash_panel('Stock status', chart_donut($slices, ['summary' => 'Active medicines by stock status', 'centre' => 'medicines']), ['span' => 4, 'sub' => 'Active medicines']),
        dash_panel('Dispensed most', chart_hbars(an_top_medicines(), ['colour' => 0, 'summary' => 'Medicines dispensed most in the last 30 days (units)']), ['span' => 6, 'sub' => 'Units, last 30 days']),
        dash_panel('Prescriptions waiting', dash_table(['Patient', 'Doctor', 'Items', 'Waiting'], $rows, 'No prescriptions are waiting.', [2, 3]), ['span' => 6, 'href' => 'pharmacy/index.php', 'link' => 'Open prescriptions']),
    ];
    $alerts = dashboard_alerts($user);
    if ($alerts !== '') {
        $panels[] = '<div class="w-12 alert-stack">' . $alerts . '</div>';
    }
    return [$kpis, $panels];
}

function dash_cashier(array $user): array
{
    $days = an_days(14);
    $labels = an_labels($days);
    $id = (int) $user['id'];
    $mine = an_series_collections($days, $id);
    $today = collections_for_day(app_today(), $id);
    $unpaid = db_one("SELECT COUNT(*) AS n, COALESCE(SUM(total - amount_paid), 0) AS s FROM invoices WHERE status IN ('Unpaid', 'Partially paid')");
    $kpis = [
        dashboard_tile('Invoices waiting for payment', (int) $unpaid['n'], 'billing/index.php'),
        dashboard_tile('Collected by me today', fmt_money($today['total']), 'billing/index.php', '', '', '', $mine),
        dashboard_tile('Receipts issued by me today', $today['count'], 'billing/index.php'),
        dashboard_tile('Balance still to collect', fmt_money($unpaid['s']), 'billing/index.php', 'warn'),
    ];
    $rows = [];
    foreach (an_unpaid_invoices(8) as $i) {
        $rows[] = [$i['invoice_no'], dash_patient_cell($i), fmt_money(number_format((float) $i['total'] - (float) $i['amount_paid'], 2, '.', '')), dash_pill($i['status']), fmt_date(substr($i['created_at'], 0, 10))];
    }
    $slices = [];
    foreach ($today['methods'] as $m => $amt) {
        $slices[] = ['label' => $m, 'value' => (float) $amt, 'colour' => PAYMENT_COLOURS[$m] ?? 0];
    }
    $panels = [
        dash_panel('Money I collected, last 14 days', chart_bars($labels, [['name' => 'Collected (KSh)', 'values' => $mine, 'colour' => 6]], ['prefix' => 'KSh ', 'summary' => 'Money you collected per day for the last 14 days']), ['span' => 8]),
        dash_panel('How my payments came today', chart_donut($slices, ['prefix' => 'KSh ', 'summary' => 'Money you collected today by payment method', 'centre' => 'KSh today']), ['span' => 4]),
        dash_panel('Invoices waiting for payment', dash_table(['Invoice', 'Patient', 'Balance', 'Status', 'Date'], $rows, 'Every invoice is paid.', [0, 2, 3, 4]), ['span' => 12, 'href' => 'billing/index.php', 'link' => 'Open invoices']),
    ];
    return [$kpis, $panels];
}

/** The whole dashboard for the signed-in user, as HTML. */
function render_dashboard(array $user): string
{
    $fn = 'dash_' . $user['role'];
    [$kpis, $panels] = function_exists($fn) ? $fn($user) : [dashboard_tiles($user), []];
    $h = dash_hero($user, dash_actions($user));
    if ($kpis) {
        $h .= '<div class="kpi-grid kpi-n' . count($kpis) . '">' . implode('', $kpis) . '</div>';
    }
    if ($panels) {
        $h .= '<div class="dash-grid">' . implode('', $panels) . '</div>';
    }
    return $h;
}
