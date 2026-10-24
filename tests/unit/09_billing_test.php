<?php
declare(strict_types=1);

suite('Module 5: billing and payments');

function inv_for(int $visitId): array
{
    $inv = get_invoice_for_visit($visitId);
    if (!$inv) {
        throw new RuntimeException('no invoice for visit');
    }
    return $inv;
}

function item_amounts(int $invoiceId): array
{
    $out = [];
    foreach (invoice_items($invoiceId) as $it) {
        $out[$it['description']] = $it['line_total'];
    }
    return $out;
}

function pay(int $invoiceId, string $method, string $amount, string $reference, int $cashier): array
{
    return record_payment($invoiceId, ['method' => $method, 'amount' => $amount, 'reference' => $reference], $cashier);
}

test('UT-BIL-001', 'the first recorded service creates the invoice automatically with the consultation fee', function () {
    [$vid] = consulted_visit();
    $inv = inv_for($vid);
    assert_true((bool) preg_match('/^INV-\d{4}-\d{6}$/', $inv['invoice_no']), 'invoice number format');
    assert_same('Unpaid', $inv['status']);
    assert_eq(500, $inv['total']);
    assert_eq(0, $inv['amount_paid']);
    $items = invoice_items((int) $inv['id']);
    assert_count(1, $items);
    assert_same('Consultation', $items[0]['source']);
    assert_same('General consultation', $items[0]['description']);
});

test('UT-BIL-002', 'no invoice exists until something billable has been recorded', function () {
    [$rec, , , $pat] = clinic_setup();
    $vid = open_visit($pat, $rec);
    assert_null(get_invoice_for_visit($vid));
    billing_sync_visit($vid, $rec);
    assert_null(get_invoice_for_visit($vid), 'syncing an empty visit creates nothing');
});

test('UT-BIL-003', 'laboratory tests are added to the invoice at the test price and removed if cancelled', function () {
    [$vid, $doc] = consulted_visit();
    request_lab_tests($vid, [lab_test_id('Malaria test (BS/MPS)'), lab_test_id('Full haemogram')], '', $doc);
    $inv = inv_for($vid);
    assert_eq(500 + 300 + 800, $inv['total']);
    $ids = array_map(fn ($r) => (int) $r['id'], visit_lab_requests($vid));
    assert_null(cancel_lab_request($ids[0], $doc));
    $inv = inv_for($vid);
    assert_eq(500 + 800, $inv['total']);
    assert_count(2, invoice_items((int) $inv['id']));
});

test('UT-BIL-004', 'dispensed medicines are charged by the quantity actually given', function () {
    freeze_time('2026-11-02 09:00:00');
    $m = make_medicine('Amoxicillin', '12.50', 10, '500 mg', 'Capsule');
    stock_up($m, 'AM1', '2027-06-01', 100);
    $ph = make_user('pharmacist');
    [$rx, $vid, , $items] = prescribe_for_test([$m => 20]);
    assert_eq(500, inv_for($vid)['total'], 'prescribing alone is not charged');
    dispense_prescription($rx, [$items[0] => 8], $ph);
    $inv = inv_for($vid);
    assert_eq(500 + 100, $inv['total'], '8 x 12.50');
    dispense_prescription($rx, [$items[0] => 12], $ph);
    $inv = inv_for($vid);
    assert_eq(500 + 250, $inv['total'], 'quantity grows to 20 x 12.50 on the same line');
    $lines = array_values(array_filter(invoice_items((int) $inv['id']), fn ($i) => $i['source'] === 'Pharmacy'));
    assert_count(1, $lines);
    assert_eq(20, $lines[0]['quantity']);
    assert_eq(12.5, $lines[0]['unit_price']);
});

test('UT-BIL-005', 'procedures are charged by quantity', function () {
    [$vid, $doc] = consulted_visit();
    $nurse = make_user('nurse');
    record_visit_service($vid, service_id('Wound dressing'), 2, $nurse);
    record_visit_service($vid, service_id('Injection administration'), 1, $nurse);
    $inv = inv_for($vid);
    assert_eq(500 + 800 + 150, $inv['total']);
    $amounts = item_amounts((int) $inv['id']);
    assert_eq(800, $amounts['Wound dressing']);
});

test('UT-BIL-006', 'syncing again never duplicates a charge', function () {
    [$vid, $doc] = consulted_visit();
    request_lab_tests($vid, [lab_test_id('Urinalysis')], '', $doc);
    for ($i = 0; $i < 4; $i++) {
        billing_sync_visit($vid, $doc);
    }
    $inv = inv_for($vid);
    assert_count(2, invoice_items((int) $inv['id']));
    assert_eq(900, $inv['total']);
    assert_eq(1, db_value('SELECT COUNT(*) FROM invoices'));
    $e = assert_throws(fn () => db_exec("INSERT INTO invoice_items (invoice_id, source, source_id, description, quantity, unit_price, line_total) SELECT invoice_id, source, source_id, 'dup', 1, 1, 1 FROM invoice_items LIMIT 1"));
    assert_true($e instanceof PDOException, 'the database also refuses a duplicate source');
});

test('UT-BIL-007', 'a full journey produces one itemised invoice with the right total', function () {
    freeze_time('2026-11-02 09:00:00');
    $m = make_medicine('Paracetamol', '5.00', 10);
    stock_up($m, 'PAR1', '2027-06-01', 500);
    $lab = make_user('lab');
    $ph = make_user('pharmacist');
    $nurse = make_user('nurse');
    [$vid, $doc] = consulted_visit();
    request_lab_tests($vid, [lab_test_id('Malaria test (BS/MPS)'), lab_test_id('Full haemogram')], '', $doc);
    foreach (visit_lab_requests($vid) as $r) {
        record_lab_result((int) $r['id'], 'done', false, $lab);
    }
    $rx = create_prescription($vid, [['medicine_id' => $m, 'dosage' => '1', 'frequency' => 'tds', 'duration' => '5 days', 'quantity' => 40]], $doc);
    $items = db_all('SELECT id FROM prescription_items WHERE prescription_id = ?', [$rx['id']]);
    dispense_prescription($rx['id'], [(int) $items[0]['id'] => 40], $ph);
    record_visit_service($vid, service_id('Wound dressing'), 2, $nurse);
    $inv = inv_for($vid);
    // consultation 500 + malaria 300 + haemogram 800 + 40 x 5.00 + 2 x 400 dressing
    assert_eq(500 + 300 + 800 + 200 + 800, $inv['total']);
    assert_count(5, invoice_items((int) $inv['id']));
    $sum = (float) db_value('SELECT SUM(line_total) FROM invoice_items WHERE invoice_id = ?', [$inv['id']]);
    assert_eq((float) $inv['total'], $sum, 'the stored total equals the sum of its lines');
});

test('UT-BIL-008', 'partial then full payment moves the invoice from Unpaid to Partially paid to Paid', function () {
    [$vid] = consulted_visit();
    $cashier = make_user('cashier');
    $inv = inv_for($vid);
    $r = pay((int) $inv['id'], 'Cash', '200', '', $cashier);
    assert_true($r['ok']);
    $inv = inv_for($vid);
    assert_same('Partially paid', $inv['status']);
    assert_eq(200, $inv['amount_paid']);
    assert_same('300.00', invoice_balance($inv));
    assert_true(pay((int) $inv['id'], 'Cash', '300', '', $cashier)['ok']);
    $inv = inv_for($vid);
    assert_same('Paid', $inv['status']);
    assert_same('0.00', invoice_balance($inv));
    assert_count(2, invoice_payments((int) $inv['id']));
});

test('UT-BIL-009', 'a payment can never exceed the balance and the amount must be a valid sum of money', function () {
    [$vid] = consulted_visit();
    $cashier = make_user('cashier');
    $id = (int) inv_for($vid)['id'];
    foreach (['500.01', '0', '-50', 'abc', '', '10.999', '1e3'] as $bad) {
        $r = pay($id, 'Cash', $bad, '', $cashier);
        assert_false($r['ok'], "amount '$bad' should be refused");
        assert_has_key('amount', $r['errors']);
    }
    assert_eq(0, db_value('SELECT COUNT(*) FROM payments'));
    assert_true(pay($id, 'Cash', '1,000', '', $cashier)['ok'] === false, 'more than the 500 balance');
    assert_true(pay($id, 'Cash', '500.00', '', $cashier)['ok']);
    assert_false(pay($id, 'Cash', '1', '', $cashier)['ok'], 'nothing left to pay');
});

test('UT-BIL-010', 'each payment method is accepted with the right reference', function () {
    [$vid, $doc] = consulted_visit();
    request_lab_tests($vid, [lab_test_id('Full haemogram')], '', $doc);   // total 1300
    $cashier = make_user('cashier');
    $id = (int) inv_for($vid)['id'];
    assert_true(pay($id, 'Cash', '100', '', $cashier)['ok']);
    assert_true(pay($id, 'M-Pesa', '200', 'qjk4h2m9xp', $cashier)['ok'], 'M-Pesa code is stored in upper case');
    assert_true(pay($id, 'Card', '300', 'AP1234', $cashier)['ok']);
    assert_true(pay($id, 'Insurance', '700', 'AAR/2026/0042', $cashier)['ok']);
    assert_same('Paid', inv_for($vid)['status']);
    $methods = array_column(invoice_payments($id), 'method');
    assert_same(['Cash', 'M-Pesa', 'Card', 'Insurance'], $methods);
    assert_same('QJK4H2M9XP', db_value("SELECT reference FROM payments WHERE method = 'M-Pesa'"));
    assert_null(db_value("SELECT reference FROM payments WHERE method = 'Cash'"));
});

test('UT-BIL-011', 'references are required where the method needs them and an M-Pesa code cannot be used twice', function () {
    [$vid, $doc] = consulted_visit();
    request_lab_tests($vid, [lab_test_id('Full haemogram')], '', $doc);
    $cashier = make_user('cashier');
    $id = (int) inv_for($vid)['id'];
    assert_has_key('reference', pay($id, 'M-Pesa', '100', '', $cashier)['errors']);
    assert_has_key('reference', pay($id, 'M-Pesa', '100', 'SHORT', $cashier)['errors']);
    assert_has_key('reference', pay($id, 'M-Pesa', '100', 'QJK4H2M9X!', $cashier)['errors']);
    assert_has_key('reference', pay($id, 'Card', '100', 'A1', $cashier)['errors']);
    assert_has_key('reference', pay($id, 'Insurance', '100', '', $cashier)['errors']);
    assert_has_key('method', pay($id, 'Cheque', '100', '', $cashier)['errors']);
    assert_true(pay($id, 'M-Pesa', '100', 'QJK4H2M9XP', $cashier)['ok']);
    $dup = pay($id, 'M-Pesa', '100', 'QJK4H2M9XP', $cashier);
    assert_false($dup['ok']);
    assert_contains('already been used', $dup['errors']['reference']);
});

test('UT-BIL-012', 'a charge added after payment raises the balance and reopens the invoice', function () {
    [$vid, $doc] = consulted_visit();
    $cashier = make_user('cashier');
    $id = (int) inv_for($vid)['id'];
    pay($id, 'Cash', '500', '', $cashier);
    assert_same('Paid', inv_for($vid)['status']);
    request_lab_tests($vid, [lab_test_id('Urinalysis')], '', $doc);
    $inv = inv_for($vid);
    assert_same('Partially paid', $inv['status']);
    assert_same('400.00', invoice_balance($inv));
});

test('UT-BIL-013', 'a charge cannot be removed if the invoice would then be over-paid', function () {
    [$vid, $doc] = consulted_visit();
    $cashier = make_user('cashier');
    request_lab_tests($vid, [lab_test_id('Full haemogram')], '', $doc);   // 1300
    $id = (int) inv_for($vid)['id'];
    pay($id, 'Cash', '1300', '', $cashier);
    $reqId = (int) visit_lab_requests($vid)[0]['id'];
    $err = cancel_lab_request($reqId, $doc);
    assert_not_null($err);
    assert_contains('already been paid', $err);
    assert_same('Requested', get_lab_request($reqId)['status'], 'the cancellation was rolled back');
    assert_eq(1300, inv_for($vid)['total']);
});

test('UT-BIL-014', 'before payment a price change updates the line; after payment the agreed price is kept', function () {
    [$vid, $doc] = consulted_visit();
    request_lab_tests($vid, [lab_test_id('Urinalysis')], '', $doc);
    $cashier = make_user('cashier');
    db_exec("UPDATE lab_tests SET price = 450 WHERE name = 'Urinalysis'");
    billing_sync_visit($vid, $doc);
    assert_eq(950, inv_for($vid)['total'], 'unpaid invoice follows the new price');
    pay((int) inv_for($vid)['id'], 'Cash', '100', '', $cashier);
    db_exec("UPDATE lab_tests SET price = 999 WHERE name = 'Urinalysis'");
    billing_sync_visit($vid, $doc);
    assert_eq(950, inv_for($vid)['total'], 'a part-paid invoice keeps its agreed price');
});

test('UT-BIL-015', 'money is calculated exactly with no rounding drift', function () {
    freeze_time('2026-11-02 09:00:00');
    $m = make_medicine('Cheapdrug', '0.10', 1);
    stock_up($m, 'CH1', '2027-06-01', 1000);
    [$rx, $vid, , $items] = prescribe_for_test([$m => 33]);
    dispense_prescription($rx, [$items[0] => 33], make_user('pharmacist'));
    assert_eq(503.30, inv_for($vid)['total']);
    $cashier = make_user('cashier');
    $id = (int) inv_for($vid)['id'];
    pay($id, 'Cash', '0.10', '', $cashier);
    pay($id, 'Cash', '0.20', '', $cashier);
    assert_same('503.00', invoice_balance(inv_for($vid)));
});

test('UT-BIL-016', 'payments are audited with amount and method and receipts have a number', function () {
    [$vid] = consulted_visit();
    $cashier = make_user('cashier');
    $r = pay((int) inv_for($vid)['id'], 'M-Pesa', '250', 'ABCDE12345', $cashier);
    $a = db_one("SELECT * FROM audit_log WHERE action = 'payment'");
    assert_eq($cashier, $a['user_id']);
    assert_eq($r['payment_id'], $a['entity_id']);
    assert_contains('M-Pesa', $a['details']);
    assert_contains('250', $a['details']);
    assert_not_contains('ABCDE12345', $a['details'], 'the reference is not repeated in the log');
    $p = get_payment($r['payment_id']);
    assert_true((bool) preg_match('/^RCT-\d{4}-\d{6}$/', receipt_number($p)));
    assert_same('Test', $p['first_name']);
});

test('UT-BIL-017', 'the invoice list filters by status, balance, text and date', function () {
    freeze_time('2026-11-02 09:00:00');
    [$v1] = consulted_visit();
    [$v2, , $p2] = consulted_visit();
    $cashier = make_user('cashier');
    pay((int) inv_for($v2)['id'], 'Cash', '500', '', $cashier);
    assert_eq(2, list_invoices([])[1]);
    assert_eq(1, list_invoices(['status' => 'Paid'])[1]);
    assert_eq(1, list_invoices(['open' => 1])[1]);
    assert_eq(1, list_invoices(['q' => inv_for($v1)['invoice_no']])[1]);
    assert_eq(1, list_invoices(['q' => get_patient($p2)['op_number']])[1]);
    assert_eq(0, list_invoices(['q' => 'Nobody'])[1]);
    assert_eq(0, list_invoices(['from' => '2000-01-01', 'to' => '2000-01-02'])[1]);
    assert_eq(2, list_invoices(['from' => date('Y-m-d'), 'to' => date('Y-m-d')])[1]);
});

test('UT-BIL-018', 'collections for a day are totalled by method and by cashier', function () {
    [$v1] = consulted_visit();
    [$v2] = consulted_visit();
    $c1 = make_user('cashier');
    $c2 = make_user('cashier');
    pay((int) inv_for($v1)['id'], 'Cash', '300', '', $c1);
    pay((int) inv_for($v1)['id'], 'M-Pesa', '200', 'MPESA00001', $c1);
    pay((int) inv_for($v2)['id'], 'Card', '500', 'CARD0001', $c2);
    $today = date('Y-m-d');
    $all = collections_for_day($today);
    assert_same('1000.00', $all['total']);
    assert_eq(3, $all['count']);
    assert_same('300.00', $all['methods']['Cash']);
    $mine = collections_for_day($today, $c1);
    assert_same('500.00', $mine['total']);
    assert_same('0.00', $mine['methods']['Card']);
    assert_same('0.00', collections_for_day('2000-01-01')['total']);
});

test('UT-BIL-019', 'the database refuses a payment of zero or less and a second invoice for one visit', function () {
    [$vid] = consulted_visit();
    $inv = inv_for($vid);
    $cashier = make_user('cashier');
    $e = assert_throws(fn () => db_exec("INSERT INTO payments (invoice_id, amount, method, received_by) VALUES (?, 0, 'Cash', ?)", [$inv['id'], $cashier]));
    assert_true($e instanceof PDOException);
    $e2 = assert_throws(fn () => db_exec("INSERT INTO invoices (invoice_no, visit_id, created_by) VALUES ('X1', ?, ?)", [$vid, $cashier]));
    assert_true($e2 instanceof PDOException);
});

test('UT-BIL-020', 'the cashier dashboard shows invoices waiting and money collected', function () {
    [$vid] = consulted_visit();
    $cashier = make_user('cashier');
    pay((int) inv_for($vid)['id'], 'Cash', '100', '', $cashier);
    unset($GLOBALS['__now']); // payments are stamped with the database clock, so use the real date here
    sign_in_as($cashier);
    $html = implode(' ', dashboard_tiles_billing($_SESSION['user']));
    assert_contains('Invoices waiting for payment', $html);
    assert_contains('KSh 100.00', $html);
});
