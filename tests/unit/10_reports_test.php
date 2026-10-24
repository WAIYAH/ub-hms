<?php
declare(strict_types=1);

suite('Module 5: management reports');

/** Pin database timestamps to a known day so the reports can be checked exactly. */
function stamp_day(string $day): void
{
    db_exec("UPDATE appointments SET created_at = ?", ["$day 08:00:00"]);
    db_exec("UPDATE visits SET visit_date = ?", ["$day 09:00:00"]);
    db_exec("UPDATE patients SET created_at = ?", ["$day 07:00:00"]);
    db_exec("UPDATE invoices SET created_at = ?", ["$day 10:00:00"]);
    db_exec("UPDATE payments SET paid_at = ?", ["$day 11:00:00"]);
    db_exec("UPDATE lab_requests SET requested_at = ?", ["$day 09:30:00"]);
    db_exec("UPDATE stock_movements SET created_at = ?", ["$day 12:00:00"]);
}

test('UT-REP-001', 'the report range defaults to this month and swaps dates given the wrong way round', function () {
    freeze_time('2026-11-19 10:00:00');
    assert_same(['2026-11-01', '2026-11-19'], report_range([]));
    assert_same(['2026-10-01', '2026-10-15'], report_range(['to' => '2026-10-15']));
    assert_same(['2026-10-05', '2026-10-20'], report_range(['from' => '2026-10-20', 'to' => '2026-10-05']));
    assert_same(['2026-11-01', '2026-11-19'], report_range(['from' => 'junk', 'to' => 'junk']));
    assert_count(3, date_list('2026-10-30', '2026-11-01'));
});

test('UT-REP-002', 'the attendance report counts appointments by outcome, visits, walk-ins and new patients', function () {
    [$rec, $nurse, $doc, $p1] = clinic_setup();
    $p2 = make_patient(['national_id' => '44000001', 'first_name' => 'Second'], $rec);
    $p3 = make_patient(['national_id' => '44000002', 'first_name' => 'Third'], $rec);
    $a1 = book_appointment(['patient_id' => $p1, 'doctor_id' => $doc, 'date' => '2026-11-02', 'start_time' => '09:00'], $rec)['id'];
    $a2 = book_appointment(['patient_id' => $p2, 'doctor_id' => $doc, 'date' => '2026-11-02', 'start_time' => '09:20'], $rec)['id'];
    $a3 = book_appointment(['patient_id' => $p3, 'doctor_id' => $doc, 'date' => '2026-11-02', 'start_time' => '09:40'], $rec)['id'];
    book_appointment(['patient_id' => $p3, 'doctor_id' => $doc, 'date' => '2026-11-03', 'start_time' => '09:40'], $rec);
    check_in_patient($p1, $a1, null, '', $rec);                      // attended, with appointment
    freeze_time('2026-11-02 12:00:00');
    set_appointment_status($a2, 'Missed', $rec);
    $cancelled = book_appointment(['patient_id' => $p2, 'doctor_id' => $doc, 'date' => '2026-11-04', 'start_time' => '10:00'], $rec)['id'];
    set_appointment_status($cancelled, 'Cancelled', $rec);
    check_in_patient($p3, null, $doc, 'Walk-in', $rec);              // walk-in, a3 stays booked
    stamp_day('2026-11-02');
    db_exec("UPDATE appointments SET appointment_date = '2026-11-02' WHERE id = ?", [$cancelled]);
    $r = report_attendance('2026-11-02', '2026-11-03');
    $t = $r['totals'];
    assert_eq(1, $t['attended']);
    assert_eq(1, $t['missed']);
    assert_eq(1, $t['cancelled']);
    assert_eq(2, $t['booked'], 'a3 and the 3 November booking are still booked');
    assert_eq(2, $t['visits']);
    assert_eq(1, $t['walkins']);
    assert_eq(3, $t['new_patients']);
    assert_eq(50.0, $r['attendance_rate'], '1 attended of 1 attended + 1 missed');
    assert_same('2026-11-02', $r['days'][0]['date']);
    assert_count(2, $r['days']);
    assert_eq(2, $r['doctors'][0]['visits']);
});

test('UT-REP-003', 'an empty period gives zeros and no attendance rate', function () {
    $r = report_attendance('2020-01-01', '2020-01-07');
    assert_count(7, $r['days']);
    assert_eq(0, $r['totals']['visits']);
    assert_null($r['attendance_rate']);
    $rev = report_revenue('2020-01-01', '2020-01-07');
    assert_same('0.00', $rev['billed']);
    assert_same('0.00', $rev['collected']);
    assert_eq(0, report_lab('2020-01-01', '2020-01-07')['totals']['requested']);
});

test('UT-REP-004', 'the revenue report separates billed from collected, by method and by type of service', function () {
    $lab = make_user('lab');
    $cashier = make_user('cashier');
    [$v1, $doc] = consulted_visit();
    request_lab_tests($v1, [lab_test_id('Full haemogram')], '', $doc);         // 500 + 800
    $inv1 = (int) get_invoice_for_visit($v1)['id'];
    record_payment($inv1, ['method' => 'Cash', 'amount' => '300', 'reference' => ''], $cashier);
    record_payment($inv1, ['method' => 'M-Pesa', 'amount' => '500', 'reference' => 'REVTEST001'], $cashier);
    [$v2] = consulted_visit();                                                  // 500
    record_payment((int) get_invoice_for_visit($v2)['id'], ['method' => 'Insurance', 'amount' => '500', 'reference' => 'CLAIM-0001'], $cashier);
    stamp_day('2026-09-15');
    $r = report_revenue('2026-09-01', '2026-09-30');
    assert_same('1800.00', $r['billed']);
    assert_same('1300.00', $r['collected']);
    assert_same('500.00', $r['outstanding']);
    assert_eq(2, $r['invoices']);
    assert_same('300.00', $r['methods']['Cash']['amount']);
    assert_same('500.00', $r['methods']['M-Pesa']['amount']);
    assert_same('500.00', $r['methods']['Insurance']['amount']);
    assert_same('0.00', $r['methods']['Card']['amount']);
    assert_eq(3, array_sum(array_column($r['methods'], 'count')));
    assert_same('1000.00', $r['categories']['Consultation']);
    assert_same('800.00', $r['categories']['Laboratory']);
    assert_same('0.00', $r['categories']['Pharmacy']);
    $day = array_values(array_filter($r['days'], fn ($d) => $d['date'] === '2026-09-15'))[0];
    assert_same('1800.00', $day['billed']);
    assert_same('1300.00', $day['collected']);
    assert_eq(3, $day['payments']);
    $other = report_revenue('2026-10-01', '2026-10-31');
    assert_same('0.00', $other['billed']);
});

test('UT-REP-005', 'the stock report shows movements in the period, stock value and alerts', function () {
    freeze_time('2026-11-02 09:00:00');
    $m = make_medicine('Reportdrug', '2.00', 100);
    $b = stock_up($m, 'RP1', '2027-06-01', 150);
    $ph = make_user('pharmacist');
    [$rx, , , $items] = prescribe_for_test([$m => 30]);
    dispense_prescription($rx, [$items[0] => 30], $ph);
    adjust_batch($b, -4, 'Damaged', $ph);
    stamp_day('2026-09-15');
    $r = report_stock('2026-09-01', '2026-09-30');
    $row = array_values(array_filter($r['rows'], fn ($x) => $x['name'] === 'Reportdrug'))[0];
    assert_eq(150, $row['received']);
    assert_eq(30, $row['dispensed']);
    assert_eq(-4, $row['adjusted']);
    assert_eq(116, $row['on_hand']);
    assert_same('232.00', $row['value']);
    assert_same('OK', $row['status']);
    $quiet = report_stock('2026-10-01', '2026-10-31');
    $row2 = array_values(array_filter($quiet['rows'], fn ($x) => $x['name'] === 'Reportdrug'))[0];
    assert_eq(0, $row2['received'], 'movements outside the period are not counted');
    assert_eq(116, $row2['on_hand'], 'but stock on hand is always current');
    assert_true(isset($r['alerts']['low']));
});

test('UT-REP-006', 'the laboratory report counts requests, completions, abnormal results and time taken', function () {
    $lab = make_user('lab');
    [$vid, $doc] = consulted_visit();
    request_lab_tests($vid, [lab_test_id('Malaria test (BS/MPS)'), lab_test_id('Full haemogram'), lab_test_id('Urinalysis'), lab_test_id('Widal test')], '', $doc);
    $ids = array_map(fn ($r) => (int) $r['id'], visit_lab_requests($vid));
    record_lab_result($ids[0], 'Positive', true, $lab);
    record_lab_result($ids[1], 'Hb 13', false, $lab);
    cancel_lab_request($ids[3], $doc);
    stamp_day('2026-09-15');
    db_exec("UPDATE lab_requests SET result_at = '2026-09-15 10:30:00' WHERE status = 'Completed'");   // 60 minutes after 09:30
    $r = report_lab('2026-09-01', '2026-09-30');
    assert_eq(4, $r['totals']['requested']);
    assert_eq(2, $r['totals']['completed']);
    assert_eq(1, $r['totals']['pending']);
    assert_eq(1, $r['totals']['cancelled']);
    assert_eq(1, $r['totals']['abnormal']);
    assert_eq(60, $r['avg_minutes']);
    $malaria = array_values(array_filter($r['tests'], fn ($t) => $t['name'] === 'Malaria test (BS/MPS)'))[0];
    assert_eq(1, $malaria['abnormal']);
    assert_eq(60, $malaria['avg_minutes']);
    assert_eq(4, $r['doctors'][0]['requested']);
    assert_eq(2, $r['doctors'][0]['completed']);
});

test('UT-REP-007', 'CSV export neutralises spreadsheet formulas but leaves numbers alone', function () {
    assert_same("'=SUM(A1:A9)", csv_safe('=SUM(A1:A9)'));
    assert_same("'+cmd|' /C calc'!A0", csv_safe("+cmd|' /C calc'!A0"));
    assert_same("'@import", csv_safe('@import'));
    assert_same("'-evil", csv_safe('-evil'));
    assert_same('-5', csv_safe('-5'));
    assert_same('1234.50', csv_safe('1234.50'));
    assert_same('Paracetamol 500 mg', csv_safe('Paracetamol 500 mg'));
    assert_same('', csv_safe(''));
});

test('UT-REP-008', 'only the managers and the right departments may open each report', function () {
    foreach (['reports.attendance', 'reports.revenue', 'reports.lab', 'reports.stock'] as $p) {
        assert_true(role_can('manager', $p), "manager: $p");
        assert_false(role_can('cashier', $p), "cashier: $p");
        assert_false(role_can('nurse', $p), "nurse: $p");
        assert_false(role_can('receptionist', $p), "receptionist: $p");
    }
    assert_true(role_can('pharmacist', 'reports.stock'));
    assert_false(role_can('pharmacist', 'reports.revenue'));
    assert_false(role_can('lab', 'reports.revenue'));
    assert_false(role_can('doctor', 'reports.revenue'));
    assert_false(role_can('admin', 'reports.revenue'));
});
