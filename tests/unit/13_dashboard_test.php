<?php
declare(strict_types=1);

suite('Dashboards: charts, analytics, notifications and the application shell');

/** The day boundaries the analytics use: put one record at a chosen moment. */
function set_stamp(string $table, string $column, int $id, string $when): void
{
    db_exec("UPDATE `$table` SET `$column` = ? WHERE id = ?", [$when, $id]);
}

/** The highest of the numbers in a list (for chart height checks). */
function bar_heights(string $svg): array
{
    preg_match_all('#<rect x="[\d.]+" y="[\d.]+" width="[\d.]+" height="([\d.]+)"#', $svg, $m);
    return array_map('floatval', $m[1]);
}

/* ---------- Charts ---------- */

test('UT-DSH-001', 'axis scales end on a tidy number and whole-number charts never get fractional gridlines', function () {
    assert_eq(1, chart_nice_max(0.7));
    assert_eq(2, chart_nice_max(1.3));
    assert_eq(5, chart_nice_max(3));
    assert_eq(10, chart_nice_max(7));
    assert_eq(5000, chart_nice_max(2100));
    assert_eq(2000, chart_nice_max(1234));
    assert_eq(1, chart_nice_max(0), 'an empty chart still has a scale');
    foreach ([1, 2, 3, 4, 5, 6, 7, 9, 10, 11, 25, 40, 99, 100, 1234] as $max) {
        [$top, $steps] = chart_scale([0.0, (float) $max], true);
        for ($s = 0; $s <= $steps; $s++) {
            $v = $top * $s / $steps;
            assert_true(abs($v - round($v)) < 1e-9, "max $max: gridline $s is $v, which is not a whole number");
        }
        assert_true($top >= $max, "the axis reaches the largest value ($max)");
    }
});

test('UT-DSH-002', 'axis labels are short and exact', function () {
    foreach ([[0, '0'], [10, '10'], [20, '20'], [100, '100'], [750, '750'], [1000, '1K'], [2500, '2.5K'], [10000, '10K'], [1200000, '1.2M'], [0.5, '0.5'], [15, '15']] as [$v, $want]) {
        assert_same($want, chart_fmt_short((float) $v), "label for $v");
    }
    assert_same('KSh 1,250', chart_fmt(1250.0, 'KSh '));
    assert_same('3', chart_fmt(3.0));
    assert_same('12.50', chart_fmt(12.5));
});

test('UT-DSH-003', 'a bar chart draws each value in proportion, skips zeros and carries its numbers as text', function () {
    $svg = chart_bars(['1 Nov', '2 Nov', '3 Nov'], [['name' => 'Visits', 'values' => [2, 0, 4], 'colour' => 1]], ['summary' => 'Visits per day']);
    $h = bar_heights($svg);
    assert_count(2, $h, 'the zero day draws no bar');
    assert_true(abs($h[1] / $h[0] - 2.0) < 0.02, 'a bar twice as tall for twice the value');
    assert_contains('aria-label="Visits per day"', $svg);
    assert_contains('<title>1 Nov: 2</title>', $svg);
    assert_contains('<caption>Visits per day</caption>', $svg);
    assert_contains('<th scope="row">3 Nov</th><td>4</td>', $svg, 'the hidden table repeats the numbers');
    assert_not_contains('NaN', $svg);
    assert_not_contains('INF', $svg);
});

test('UT-DSH-004', 'a chart with no activity says so, and an empty one shows a calm message', function () {
    $svg = chart_bars(['a', 'b'], [['name' => 'x', 'values' => [0, 0]]]);
    assert_contains('No activity in this period', $svg);
    assert_count(0, bar_heights($svg));
    assert_contains('chart-empty', chart_bars([], []));
    assert_contains('chart-empty', chart_area(['one'], [1]), 'a line needs at least two points');
    assert_contains('chart-empty', chart_hbars([]));
    $area = chart_area(['a', 'b', 'c'], [0, 0, 0]);
    assert_contains('No activity in this period', $area);
});

test('UT-DSH-005', 'long date axes thin their labels so they never collide', function () {
    assert_eq(1, chart_label_step(7));
    assert_eq(1, chart_label_step(10));
    assert_eq(2, chart_label_step(14));
    $labels = array_map(fn ($i) => 'D' . $i, range(1, 14));
    $svg = chart_bars($labels, [['name' => 'x', 'values' => array_fill(0, 14, 1)]]);
    assert_eq(7, substr_count($svg, 'text-anchor="middle"'), 'every second label of 14');
    assert_contains('>D14<', $svg, 'the last day is always labelled');
});

test('UT-DSH-006', 'a line chart stays inside its frame and each chart gets its own gradient id', function () {
    $a = chart_area(['a', 'b', 'c', 'd'], [0, 5000, 1200, 800], ['prefix' => 'KSh ', 'name' => 'Collected']);
    $b = chart_area(['a', 'b', 'c', 'd'], [1, 2, 3, 4]);
    preg_match('/linearGradient id="(ga\d+)"/', $a, $ma);
    preg_match('/linearGradient id="(ga\d+)"/', $b, $mb);
    assert_not_null($ma[1] ?? null);
    assert_true($ma[1] !== $mb[1], 'two charts on one page must not share a gradient id');
    preg_match('/<path d="(M[^"]+)" fill="none"/', $a, $line);
    preg_match_all('/-?\d+(?:\.\d+)?/', $line[1], $nums);
    assert_true(count($nums[0]) % 2 === 0 && count($nums[0]) >= 8, 'the curve is made of x, y pairs');
    foreach ($nums[0] as $i => $n) {
        $limit = $i % 2 === 0 ? 480 : 230;
        assert_true((float) $n >= 0 && (float) $n <= $limit, "coordinate $n is inside the frame");
    }
    assert_contains('KSh 5,000', $a, 'tooltips carry the unit');
});

test('UT-DSH-007', 'a ring chart gives each slice its share, ignores empty slices and shows the legend values', function () {
    $svg = chart_donut([['label' => 'Cash', 'value' => 30, 'colour' => 6], ['label' => 'M-Pesa', 'value' => 10, 'colour' => 0], ['label' => 'Card', 'value' => 0]], ['summary' => 'Payments', 'centre' => 'KSh']);
    assert_contains('Cash: 30 (75%)', $svg);
    assert_contains('M-Pesa: 10 (25%)', $svg);
    assert_not_contains('Card', $svg, 'a zero slice is left out');
    assert_contains('40', $svg, 'the total in the middle');
    assert_contains('class="lg-val">30 (75%)', $svg);
    assert_eq(2, substr_count($svg, 'stroke-dasharray'));
    $none = chart_donut([['label' => 'x', 'value' => 0]]);
    assert_contains('No activity in this period', $none);
    assert_contains('no data yet', $none);
});

test('UT-DSH-008', 'a bar list scales to the largest value and shows notes', function () {
    $html = chart_hbars([['label' => 'Malaria', 'value' => 10], ['label' => 'Flu', 'value' => 5, 'note' => '2 patients'], ['label' => 'Rare', 'value' => 0]], ['summary' => 'Diagnoses']);
    assert_contains('width="100%" height="9" rx="4.5" fill=', $html, 'the largest fills the track');
    assert_contains('width="50%"', $html);
    assert_contains('2 patients', $html);
    assert_contains('<li><div class="hb-top"><span class="hb-label">Rare</span>', $html);
});

test('UT-DSH-009', 'text from the records is escaped in every chart (no markup can get in)', function () {
    $evil = '<script>alert(1)</script>';
    $all = chart_bars([$evil], [['name' => $evil, 'values' => [1]]], ['summary' => $evil])
        . chart_area([$evil, 'b'], [1, 2], ['summary' => $evil, 'name' => $evil])
        . chart_donut([['label' => $evil, 'value' => 3]], ['summary' => $evil])
        . chart_hbars([['label' => $evil, 'value' => 3, 'note' => $evil]], ['summary' => $evil]);
    assert_not_contains('<script>', $all);
    assert_contains('&lt;script&gt;', $all);
});

test('UT-DSH-010', 'a small trend line needs at least two numbers and some movement', function () {
    assert_same('', chart_spark([5]));
    assert_same('', chart_spark([0, 0, 0]));
    assert_contains('<polyline', chart_spark([1, 3, 2, 5], 2));
    assert_contains('#7c3aed', chart_spark([1, 3, 2, 5], 2));
    assert_contains('aria-hidden="true"', chart_spark([1, 2]));
});

/* ---------- Analytics ---------- */

test('UT-DSH-011', 'the day list runs oldest to newest and ends on the chosen day', function () {
    freeze_time('2026-11-05 10:00:00');
    $d = an_days(4);
    assert_same(['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05'], $d);
    assert_same(['2 Nov', '3 Nov', '4 Nov', '5 Nov'], an_labels($d));
    assert_same(['2026-02-27', '2026-02-28', '2026-03-01'], an_days(3, '2026-03-01'), 'across a month end');
    assert_same(['2026-11-02 00:00:00', '2026-11-06 00:00:00'], an_span($d), 'to the start of the day after the last');
});

test('UT-DSH-012', 'records are counted on the day they happened, including the last second of a day', function () {
    freeze_time('2026-11-03 12:00:00');
    $rec = make_user('receptionist');
    $a = make_patient(['national_id' => '55000001', 'first_name' => 'Late'], $rec);
    $b = make_patient(['national_id' => '55000002', 'first_name' => 'Early'], $rec);
    $c = make_patient(['national_id' => '55000003', 'first_name' => 'Old'], $rec);
    set_stamp('patients', 'created_at', $a, '2026-11-02 23:59:59');
    set_stamp('patients', 'created_at', $b, '2026-11-03 00:00:00');
    set_stamp('patients', 'created_at', $c, '2026-10-20 10:00:00');
    $first = make_patient(['national_id' => '55000004', 'first_name' => 'First'], $rec);
    $after = make_patient(['national_id' => '55000005', 'first_name' => 'After'], $rec);
    set_stamp('patients', 'created_at', $first, '2026-11-01 00:00:00');
    set_stamp('patients', 'created_at', $after, '2026-11-04 00:00:00');
    $days = an_days(3, '2026-11-03');
    assert_same([1.0, 1.0, 1.0], an_series_new_patients($days), '1 Nov: the one at midnight sharp; 2 Nov: the late one; 3 Nov: the early one. The old one and the one at midnight on 4 Nov are outside');
    assert_same([0.0, 0.0, 0.0], an_series_new_patients(an_days(3, '2026-11-20')), 'a quiet period is all zeros');
});

test('UT-DSH-013', 'visits, triage and consultations per day, for one doctor or for everyone', function () {
    [$rec, $nurse, $doc, $pat] = clinic_setup();
    $doc2 = make_user('doctor');
    $p2 = make_patient(['national_id' => '55000010'], $rec);
    $v1 = open_visit($pat, $rec);
    $v2 = open_visit($p2, $rec);
    save_triage($v1, ['temperature' => '37.0', 'pulse' => '80'], $nurse);
    save_consultation($v1, good_consultation(), $doc);
    save_consultation($v2, good_consultation(), $doc2);
    stamp_day('2026-11-02');
    db_exec("UPDATE triage SET recorded_at = '2026-11-02 09:15:00'");
    db_exec("UPDATE consultations SET created_at = '2026-11-02 10:00:00'");
    $days = an_days(2, '2026-11-02');
    assert_same([0.0, 2.0], an_series_visits($days));
    assert_same([0.0, 1.0], an_series_triage($days));
    assert_same([0.0, 2.0], an_series_consultations($days));
    assert_same([0.0, 1.0], an_series_consultations($days, $doc), 'only this doctor');
    assert_same([0.0, 1.0], an_series_consultations($days, $doc2));
});

test('UT-DSH-014', 'laboratory requests and results are counted separately, and cancelled requests are not counted', function () {
    [$vid, $doc] = consulted_visit();
    $lab = make_user('lab');
    request_lab_tests($vid, [lab_test_id('Malaria test (BS/MPS)'), lab_test_id('Full haemogram'), lab_test_id('Urinalysis')], 'Fever', $doc);
    $ids = array_map(fn ($r) => (int) $r['id'], visit_lab_requests($vid));
    start_lab_request($ids[0], $lab);
    record_lab_result($ids[0], 'Negative', false, $lab);
    cancel_lab_request($ids[2], $doc);
    db_exec("UPDATE lab_requests SET requested_at = '2026-11-02 09:00:00'");
    db_exec("UPDATE lab_requests SET result_at = '2026-11-03 08:00:00' WHERE status = 'Completed'");
    $s = an_series_lab(an_days(2, '2026-11-03'));
    assert_same([2.0, 0.0], $s['requested'], 'two live requests on 2 Nov; the cancelled one is not counted');
    assert_same([0.0, 1.0], $s['completed'], 'the result came on 3 Nov');
    $types = an_tests_by_type(30);
    assert_eq(2, count($types));
    assert_eq(1, (int) $types[0]['value']);
});

test('UT-DSH-015', 'money collected and units dispensed per day, and the cashier\'s own share', function () {
    $j = full_journey();
    $visit2 = consulted_visit();
    $cash2 = make_user('cashier');
    $inv1 = inv_for($j['visit']);
    $inv2 = inv_for($visit2[0]);
    pay((int) $inv1['id'], 'Cash', '100.00', '', $j['cash']);
    pay((int) $inv2['id'], 'Cash', '40.50', '', $cash2);
    stamp_day('2026-11-02');
    $days = an_days(2, '2026-11-02');
    assert_same([0.0, 140.5], an_series_collections($days), 'everyone');
    assert_same([0.0, 100.0], an_series_collections($days, $j['cash']), 'only this cashier');
    assert_same([0.0, 40.5], an_series_collections($days, $cash2));
    assert_same([0.0, 24.0], an_series_dispensed($days), '24 tablets were dispensed in the journey');
    $top = an_top_medicines(30);
    assert_same('24', (string) (int) $top[0]['value']);
    assert_contains('Artemether', $top[0]['label']);
});

test('UT-DSH-016', 'sign-ins and audit entries per day', function () {
    freeze_time('2026-11-02 09:00:00');
    $u = make_user('nurse', 'dash.nurse');
    attempt_login('dash.nurse', 'wrong', '10.9.9.9');
    attempt_login('dash.nurse', 'wrong', '10.9.9.9');
    audit('login', 'user', $u, null, $u, '10.9.9.9');
    db_exec("UPDATE audit_log SET created_at = '2026-11-02 09:00:00'");
    $s = an_series_signins(an_days(2, '2026-11-02'));
    assert_same([0.0, 1.0], $s['ok']);
    assert_same([0.0, 2.0], $s['failed']);
    assert_true(an_series_audit(an_days(2, '2026-11-02'))[1] >= 3.0);
});

test('UT-DSH-017', 'appointment outcomes, top diagnoses and doctor rankings', function () {
    [$rec, , $doc, $p1] = clinic_setup();
    $p2 = make_patient(['national_id' => '55000020'], $rec);
    $a1 = book_appointment(['patient_id' => $p1, 'doctor_id' => $doc, 'date' => '2026-11-02', 'start_time' => '09:00'], $rec)['id'];
    $a2 = book_appointment(['patient_id' => $p2, 'doctor_id' => $doc, 'date' => '2026-11-02', 'start_time' => '09:20'], $rec)['id'];
    $v1 = check_in_patient($p1, $a1, null, '', $rec)['visit_id'];
    set_appointment_status($a2, 'Cancelled', $rec);
    $v2 = open_visit($p2, $rec, $doc);
    stamp_day('2026-11-02');
    save_consultation($v1, good_consultation(['diagnosis' => ' Malaria ', 'icd10_code' => 'B54']), $doc);
    save_consultation($v2, good_consultation(['diagnosis' => 'Malaria', 'icd10_code' => 'B54']), $doc);
    $out = an_appointment_outcomes(an_days(1, '2026-11-02'));
    assert_eq(1, $out['Attended']);
    assert_eq(1, $out['Cancelled']);
    assert_eq(0, $out['Booked']);
    $top = an_top_diagnoses(null, 30);
    assert_same('Malaria', $top[0]['label'], 'spaces are trimmed so the same diagnosis is counted once');
    assert_eq(2, (int) $top[0]['value']);
    assert_count(0, an_top_diagnoses($doc + 999, 30), 'another doctor has none');
    $docs = an_top_doctors(an_days(1, '2026-11-02'));
    assert_eq(2, (int) $docs[0]['value']);
});

test('UT-DSH-018', 'stock status counts active medicines, and accounts by role leave out switched-off people', function () {
    freeze_time('2026-11-02 09:00:00');
    $ok = make_medicine('Plenty', '1.00', 10);
    stock_up($ok, 'P1', '2028-01-01', 100);
    $low = make_medicine('Few', '1.00', 50);
    stock_up($low, 'F1', '2028-01-01', 20);
    make_medicine('None', '1.00', 5);
    assert_same(['OK' => 1, 'Low' => 1, 'Out' => 1], an_stock_status());
    make_user('nurse');
    $gone = make_user('nurse', null, ['is_active' => 0]);
    make_user('doctor');
    $rows = an_users_by_role();
    $byName = array_column($rows, 'value', 'label');
    assert_eq(1, (int) $byName['Nurse'], 'the switched-off nurse is not counted');
    assert_eq(1, (int) $byName['Doctor / Clinical Officer']);
    assert_not_null($gone);
});

test('UT-DSH-019', 'the short lists show only what is still to do, oldest first', function () {
    $j = full_journey();
    [$vid] = consulted_visit();
    $inv = inv_for($vid);
    assert_same('2026-11-02', app_today());
    $today = an_todays_appointments(5);
    assert_count(1, $today, 'the journey patient has one appointment today');
    assert_same('Attended', $today[0]['status']);
    assert_same('Journey', $today[0]['first_name']);
    assert_same('Fever', $today[0]['reason']);
    assert_count(0, an_todays_appointments(5, $j['doc'] + 1000), 'another doctor has none');
    assert_count(1, an_todays_appointments(5, $j['doc']), 'this doctor has the one');
    $unpaid = an_unpaid_invoices(5);
    assert_eq(1, count(array_filter($unpaid, fn ($r) => (int) $r['id'] === (int) $inv['id'])));
    pay((int) $inv['id'], 'Cash', (string) $inv['total'], '', $j['cash']);
    assert_eq(0, count(array_filter(an_unpaid_invoices(50), fn ($r) => (int) $r['id'] === (int) $inv['id'])), 'a paid invoice leaves the list');
    $log = an_recent_audit(3);
    assert_count(3, $log);
    assert_true(strcmp($log[0]['created_at'], $log[2]['created_at']) >= 0, 'newest first');
});

test('UT-DSH-020', 'waiting time, greeting, first name and initials are worked out sensibly', function () {
    freeze_time('2026-11-02 12:00:00');
    assert_same('0 min', an_waiting_text('2026-11-02 12:00:00'));
    assert_same('12 min', an_waiting_text('2026-11-02 11:48:00'));
    assert_same('1 h 5 min', an_waiting_text('2026-11-02 10:55:00'));
    assert_same('2 d', an_waiting_text('2026-10-31 12:00:00'));
    assert_same('0 min', an_waiting_text('2026-11-02 13:00:00'), 'a time in the future is never negative');
    assert_same('Good morning', dash_greeting(new DateTimeImmutable('2026-11-02 05:00')));
    assert_same('Good afternoon', dash_greeting(new DateTimeImmutable('2026-11-02 12:00')));
    assert_same('Good evening', dash_greeting(new DateTimeImmutable('2026-11-02 17:00')));
    assert_same('Samuel', dash_first_name('Dr. Samuel Kamau'));
    assert_same('Faith', dash_first_name('Faith Achieng'));
    assert_same('Amani', dash_first_name('Amani'));
    assert_same('SK', user_initials('Dr. Samuel Kamau'));
    assert_same('FA', user_initials('faith achieng'));
    assert_same('A', user_initials('Amani'));
    assert_same('MA', user_initials('Mary Akinyi Ahmed'));
    assert_same('?', user_initials('   '));
});

/* ---------- Notifications and badges ---------- */

test('UT-DSH-021', 'notifications are listed most urgent first, with correct wording and links', function () {
    $user = ['id' => 1, 'role' => 'manager'];
    $n = notifications_for($user, ['stock_out' => 2, 'expired' => 1, 'stock_low' => 1, 'expiring' => 3, 'triage_wait' => 1, 'unpaid' => 4]);
    $levels = array_column($n, 'level');
    $rank = ['danger' => 0, 'warn' => 1, 'info' => 2];
    $sorted = $levels;
    usort($sorted, fn ($a, $b) => $rank[$a] <=> $rank[$b]);
    assert_same($sorted, $levels, 'urgent things come before routine ones');
    assert_same(['danger', 'danger'], array_slice($levels, 0, 2), 'out of stock and expired come first');
    assert_same('2 medicines out of stock', $n[0]['title']);
    assert_same('1 batch expired', $n[1]['title']);
    assert_contains('3 batches expiring soon', implode('|', array_column($n, 'title')));
    assert_contains('1 patient waiting for triage', implode('|', array_column($n, 'title')));
    foreach ($n as $x) {
        assert_true(in_array($x['level'], ['danger', 'warn', 'info'], true));
        assert_true(icon($x['icon']) !== '', "icon {$x['icon']} exists");
        assert_true(is_file(APP_ROOT . '/' . strtok($x['href'], '?')), "{$x['href']} is a real page");
    }
    assert_count(0, notifications_for($user, []), 'nothing to do, nothing to show');
    assert_count(0, notifications_for($user, ['failed_24h' => 20]), 'twenty failed sign-ins is normal; more is a warning');
    assert_count(1, notifications_for($user, ['failed_24h' => 21]));
});

test('UT-DSH-022', 'each role is told only about the work it can act on', function () {
    $r = make_user('receptionist');
    $n = make_user('nurse');
    $d = make_user('doctor');
    $l = make_user('lab');
    $p = make_user('pharmacist');
    $c = make_user('cashier');
    $a = make_user('admin');
    $m = make_user('manager');
    $key = static fn (int $id, string $role) => array_keys(shell_counts(['id' => $id, 'role' => $role]));
    assert_same(['booked_today', 'sched_conflicts'], $key($r, 'receptionist'));
    assert_same(['triage_wait'], $key($n, 'nurse'));
    assert_same(['consult_wait', 'results_ready'], $key($d, 'doctor'));
    assert_same(['lab_wait', 'lab_progress'], $key($l, 'lab'));
    assert_same(['rx_wait', 'stock_out', 'stock_low', 'expired', 'expiring'], $key($p, 'pharmacist'));
    assert_same(['unpaid'], $key($c, 'cashier'));
    assert_same(['stock_out', 'stock_low', 'expired', 'expiring', 'sched_conflicts', 'refunds_pending', 'erasures_open'], $key($m, 'manager'));
    $adminKeys = $key($a, 'admin');
    foreach (['requests_new', 'locked', 'backup', 'failed_24h'] as $k) {
        assert_true(in_array($k, $adminKeys, true), "the administrator is told about $k");
    }
    assert_false(in_array('unpaid', $adminKeys, true));
});

test('UT-DSH-023', 'the counts follow the real records, and badges leave out zeros and add the stock numbers together', function () {
    [$rec, $nurse, $doc, $pat] = clinic_setup();
    open_visit($pat, $rec);
    $nurseCounts = shell_counts(['id' => $nurse, 'role' => 'nurse']);
    assert_eq(1, $nurseCounts['triage_wait']);
    $badges = nav_badges(['id' => $nurse, 'role' => 'nurse'], ['triage_wait' => 1, 'consult_wait' => 0]);
    assert_same(['triage' => 1], $badges);
    $b = nav_badges(['id' => 1, 'role' => 'pharmacist'], ['stock_out' => 2, 'stock_low' => 3, 'expired' => 0, 'expiring' => 1, 'rx_wait' => 4]);
    assert_same(['pharmacy' => 4, 'stock' => 5, 'batches' => 1], $b);
    assert_same([], nav_badges(['id' => 1, 'role' => 'nurse'], []));
});

/* ---------- Cards, dashboards and the shell ---------- */

test('UT-DSH-024', 'a card keeps the number and label markup, picks its icon and colour, and reflects its state', function () {
    $t = dashboard_tile('Waiting for triage', 3, 'visits/queue.php?stage=triage', 'warn');
    assert_contains('<div class="num">3</div><div class="label">Waiting for triage</div>', $t);
    assert_contains('kpi-amber', $t, 'a warning is amber');
    assert_contains('href="', $t);
    assert_contains('kpi-red', dashboard_tile('Out', 1, '', 'danger'));
    assert_not_contains('<a ', dashboard_tile('No link', 1), 'a card without a destination is not a link');
    assert_contains('kpi-long', dashboard_tile('Money', 'KSh 13,690.00'));
    assert_contains('class="spark"', dashboard_tile('Visits today', 5, '', '', '', '', [1, 2, 3]));
    assert_contains('&lt;b&gt;', dashboard_tile('<b>x</b>', '<i>1</i>'));
    assert_same(['lock', 'amber'], kpi_style('Locked accounts'));
    assert_same(['chart', 'teal'], kpi_style('Something new'));
    foreach (['Locked accounts', 'Waiting for triage', 'Collected today', 'Prescriptions waiting', 'Audit entries today', 'Open visits', 'Tests waiting', 'Unpaid balances', 'Batches expiring or expired', 'Test results ready for my patients'] as $label) {
        assert_true(icon(kpi_style($label)[0]) !== '', "$label has a real icon");
    }
});

test('UT-DSH-025', 'every role gets a complete dashboard with its own numbers and none of another role\'s', function () {
    freeze_time('2026-11-02 09:00:00');
    $expect = [
        'admin'        => [['Active user accounts', 'Sign-ins, last 14 days', 'Accounts by role'], ['Unpaid balances', 'Prescriptions waiting', 'Waiting for triage']],
        'manager'      => [['Collected today', 'Money collected, last 14 days', 'How patients pay'], ['Active user accounts', 'Waiting for triage', 'Invoices waiting for payment']],
        'receptionist' => [['Patients registered', 'Appointments today', 'In the clinic now'], ['Unpaid balances', 'Active user accounts', 'Prescriptions waiting']],
        'nurse'        => [['Waiting for triage', 'Triage done, last 14 days'], ['Unpaid balances', 'Prescriptions waiting', 'Active user accounts']],
        'doctor'       => [['My consultations today', 'What I diagnose most', 'Waiting for me'], ['Unpaid balances', 'Active user accounts', 'Prescriptions waiting']],
        'lab'          => [['Tests waiting', 'Worklist: oldest first'], ['Unpaid balances', 'Patients registered', 'Prescriptions waiting']],
        'pharmacist'   => [['Prescriptions waiting', 'Units dispensed, last 14 days', 'Stock status'], ['Unpaid balances', 'Patients registered', 'Waiting for triage']],
        'cashier'      => [['Invoices waiting for payment', 'Collected by me today', 'Balance still to collect'], ['Patients registered', 'Active user accounts', 'Waiting for triage']],
    ];
    foreach ($expect as $role => [$must, $mustNot]) {
        $u = sign_in_as(make_user($role, null, ['full_name' => 'Dr. Test Person']));
        $html = render_dashboard($u);
        assert_contains('Good morning, Test', $html, "$role: greeting uses the first name, not the title");
        assert_contains('class="kpi-grid', $html);
        assert_contains('aria-label="Quick actions"', $html);
        foreach ($must as $text) {
            assert_contains($text, $html, "$role should see '$text'");
        }
        foreach ($mustNot as $text) {
            assert_not_contains($text, $html, "$role must not see '$text'");
        }
        assert_not_contains('Warning:', $html);
        assert_not_contains('Notice:', $html);
        assert_not_contains('Deprecated:', $html);
        assert_not_contains('style="', $html, "$role: no inline styles (the content security policy forbids them)");
        assert_not_contains('<script', $html);
        $_SESSION = [];
    }
});

test('UT-DSH-026', 'the quick-action buttons are limited to what the role may do and lead to real pages', function () {
    foreach (array_keys(role_labels()) as $role) {
        $u = sign_in_as(make_user($role));
        $actions = dash_actions($u);
        foreach ($actions as [$label, $path, $ic, $variant]) {
            assert_true(is_file(APP_ROOT . '/' . strtok($path, '?')), "$role: $path exists");
            assert_true(icon($ic) !== '', "$role: icon $ic exists");
            assert_true(in_array($variant, ['success', 'info', 'violet', 'warning', 'danger', 'outline', 'dark'], true), "$role: $variant is a known colour");
        }
        $_SESSION = [];
    }
    $doc = sign_in_as(make_user('doctor'));
    assert_count(0, array_filter(dash_actions($doc), fn ($a) => str_contains($a[1], 'admin/')), 'a doctor has no administrator shortcuts');
    $cash = sign_in_as(make_user('cashier'));
    assert_same(['Invoices'], array_column(dash_actions($cash), 0));
});

test('UT-DSH-027', 'the header offers the right things to each person and the sign-out stays a protected form', function () {
    $u = sign_in_as(make_user('receptionist', 'rec.h', ['full_name' => 'Faith Achieng']));
    $h = shell_header($u);
    assert_contains('role="search"', $h, 'a receptionist can search patients');
    assert_contains('Register a patient', $h);
    assert_contains('Book an appointment', $h);
    assert_not_contains('Add a user account', $h);
    assert_contains('View profile', $h);
    assert_contains('profile.php', $h);
    assert_contains('settings.php', $h);
    assert_contains('Change password', $h);
    assert_contains('action="' . url('logout.php') . '"', $h);
    assert_contains('method="post"', $h);
    assert_contains('name="_csrf"', $h, 'signing out needs the security token');
    assert_contains('>FA<', $h);
    assert_contains('@rec.h', $h);
    assert_not_contains('style="', $h);
    $_SESSION = [];

    $c = sign_in_as(make_user('cashier'));
    $hc = shell_header($c);
    assert_not_contains('role="search"', $hc, 'a cashier cannot search patients');
    assert_not_contains('dd-add', $hc, 'a cashier has nothing to quick-add');
    $_SESSION = [];
    $a = sign_in_as(make_user('admin'));
    $ha = shell_header($a);
    assert_contains('Add a user account', $ha);
    assert_not_contains('Register a patient', $ha);
});

test('UT-DSH-028', 'the bell shows how many things need attention and says so in words', function () {
    [$rec, $nurse] = clinic_setup();
    $pat2 = make_patient(['national_id' => '56000001'], $rec);
    open_visit($pat2, $rec);
    $u = sign_in_as($nurse);
    $h = shell_header($u);
    assert_contains('Notifications: 1 need attention', $h);
    assert_contains('1 patient waiting for triage', $h);
    assert_contains('class="top-badge"', $h);
    $_SESSION = [];
    $quiet = sign_in_as(make_user('lab'));
    $hq = shell_header($quiet);
    assert_contains('Notifications: nothing needs attention', $hq);
    assert_contains('You are all caught up', $hq);
    assert_not_contains('top-badge', $hq);
});

test('UT-DSH-029', 'the sidebar shows only permitted pages, marks the current one and carries badges with a text alternative', function () {
    $u = sign_in_as(make_user('cashier'));
    $s = shell_sidebar($u, 'billing');
    assert_contains('aria-current="page"', $s);
    assert_eq(1, substr_count($s, 'aria-current="page"'));
    assert_contains('billing/index.php', $s);
    assert_not_contains('patients/index.php', $s);
    assert_not_contains('admin/', $s);
    assert_not_contains('lab/index.php', $s);
    $_SESSION = [];
    $a = sign_in_as(make_user('admin'));
    $sa = shell_sidebar($a, 'users');
    assert_contains('admin/requests.php', $sa);
    assert_not_contains('billing/index.php', $sa);
    foreach (nav_items() as [, $items]) {
        foreach ($items as [, , , $key]) {
            assert_true(icon(nav_icon($key)) !== '', "$key has an icon");
            assert_true(nav_icon($key) !== 'chart' || $key === 'x', "$key has its own icon, not the fallback");
        }
    }
});

test('UT-DSH-030', 'names are escaped in the avatar and the header', function () {
    $u = sign_in_as(make_user('nurse', 'x.y', ['full_name' => '<img src=x onerror=alert(1)> Nurse']));
    $h = shell_header($u);
    assert_not_contains('<img src=x', $h);
    assert_contains('&lt;img src=x onerror=alert(1)&gt;', $h);
    assert_not_contains('<script', shell_avatar(['id' => 1, 'full_name' => '<script>']));
});

/* ---------- Profile and settings ---------- */

test('UT-DSH-031', 'a person can change or remove only their own phone number, and the audit trail does not record the number', function () {
    $id = make_user('nurse', 'own.phone');
    $other = make_user('nurse', 'other.person', ['phone' => '+254700000099']);
    assert_true(update_own_phone($id, '0712 345 678')['ok']);
    assert_same('+254712345678', get_user($id)['phone'], 'stored in one standard form');
    assert_same('+254700000099', get_user($other)['phone'], 'nobody else is touched');
    $bad = update_own_phone($id, '123');
    assert_false($bad['ok']);
    assert_contains('Kenyan mobile number', (string) $bad['error']);
    assert_same('+254712345678', get_user($id)['phone'], 'a bad number changes nothing');
    assert_true(update_own_phone($id, '')['ok']);
    assert_null(get_user($id)['phone'], 'a blank removes it');
    $details = implode('|', array_column(db_all("SELECT details FROM audit_log WHERE entity = 'user' AND entity_id = ? AND action = 'update'", [$id]), 'details'));
    assert_contains('Own phone number changed', $details);
    assert_contains('Own phone number removed', $details);
    assert_not_contains('712345678', $details);
});

test('UT-DSH-032', 'a person\'s activity list shows only their own entries, newest first, in plain words', function () {
    $a = make_user('nurse', 'act.a');
    $b = make_user('nurse', 'act.b');
    audit('login', 'user', $a, null, $a, '10.0.0.1');
    audit('password_change', 'user', $a, null, $a, '10.0.0.1');
    audit('login', 'user', $b, null, $b, '10.0.0.2');
    $rows = user_recent_activity($a, 10);
    assert_count(2, $rows);
    assert_same('password_change', $rows[0]['action'], 'newest first');
    assert_same('Changed your password', audit_action_label('password_change'));
    assert_same('Signed in', audit_action_label('login'));
    assert_same('Some new action', audit_action_label('some_new_action'));
    assert_same('A page was refused (no permission)', audit_action_label('access_denied'));
    assert_same('A form was refused (wrong security token)', audit_action_label('csrf_rejected'));
    assert_count(1, user_recent_activity($a, 1), 'the limit is respected');
});

test('UT-DSH-033', 'results ready lists a doctor\'s completed tests on open visits only, newest first', function () {
    [$vid, $doc, $pat, $rec] = consulted_visit();
    $other = make_user('doctor');
    $lab = make_user('lab');
    request_lab_tests($vid, [lab_test_id('Malaria test (BS/MPS)'), lab_test_id('Full haemogram')], 'Fever', $doc);
    $ids = array_map(fn ($r) => (int) $r['id'], visit_lab_requests($vid));
    foreach ($ids as $id) {
        start_lab_request($id, $lab);
    }
    record_lab_result($ids[0], 'Negative', false, $lab);
    record_lab_result($ids[1], 'Low haemoglobin', true, $lab);
    db_exec("UPDATE lab_requests SET result_at = '2026-11-02 09:00:00' WHERE id = ?", [$ids[0]]);
    db_exec("UPDATE lab_requests SET result_at = '2026-11-02 10:00:00' WHERE id = ?", [$ids[1]]);
    $rows = an_results_ready($doc, 6);
    assert_count(2, $rows);
    assert_eq($ids[1], (int) $rows[0]['id'], 'the newest result is first');
    assert_eq(1, (int) $rows[0]['is_abnormal']);
    assert_true((bool) preg_match('/^OP\d{2}-\d{5}$/', $rows[0]['op_number']), 'the patient is identified by outpatient number');
    assert_count(1, an_results_ready($doc, 1), 'the limit is respected');
    assert_count(0, an_results_ready($other, 6), 'another doctor sees none of these');
    assert_eq(count($rows), results_ready_count($doc), 'it agrees with the number on the card');
    close_visit($vid, $doc);
    assert_count(0, an_results_ready($doc, 6), 'once the visit is closed the results are no longer "ready"');
    assert_eq(0, results_ready_count($doc));
});

test('UT-DSH-034', 'unpaid invoices are listed oldest first, and a part-paid invoice is still unpaid', function () {
    $first = consulted_visit();
    $second = consulted_visit();
    $third = consulted_visit();
    $i1 = inv_for($first[0]);
    $i2 = inv_for($second[0]);
    $i3 = inv_for($third[0]);
    $cash = make_user('cashier');
    pay((int) $i2['id'], 'Cash', '100.00', '', $cash);                      // part paid: still owes money
    pay((int) $i3['id'], 'Cash', (string) $i3['total'], '', $cash);         // fully paid: leaves the list
    $ids = array_map(fn ($r) => (int) $r['id'], an_unpaid_invoices(10));
    assert_same([(int) $i1['id'], (int) $i2['id']], $ids, 'oldest first; the paid one is gone; the part-paid one stays');
    assert_count(1, an_unpaid_invoices(1), 'the limit is respected');
    assert_same('Partially paid', an_unpaid_invoices(10)[1]['status']);
});
