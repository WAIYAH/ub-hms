<?php
declare(strict_types=1);

suite('Module 5: refunds');

/** A consulted visit (invoice total 500) paid in full. Returns [visitId, invoiceId, paymentId, cashierId, managerId]. */
function paid_visit(string $method = 'Cash', string $reference = ''): array
{
    [$vid] = consulted_visit();
    $cash = make_user('cashier');
    $mgr = make_user('manager');
    $inv = inv_for($vid);
    $r = pay((int) $inv['id'], $method, '500.00', $reference, $cash);
    if (!$r['ok']) {
        throw new RuntimeException('paid_visit: ' . json_encode($r['errors']));
    }
    return [$vid, (int) $inv['id'], (int) $r['payment_id'], $cash, $mgr];
}

function ask_refund(int $paymentId, string $amount, int $by, string $reason = 'Test was not done', string $ref = ''): array
{
    return request_refund($paymentId, ['amount' => $amount, 'reason' => $reason, 'refund_reference' => $ref], $by);
}

test('UT-RFD-001', 'the payments ledger accepts a refund only as a negative line that points at a payment', function () {
    [, $inv, $pay, $cash] = paid_visit();
    $row = fn (string $kind, string $amount, ?int $of) => db_exec(
        'INSERT INTO payments (invoice_id, kind, refund_of, amount, method, received_by) VALUES (?, ?, ?, ?, ?, ?)',
        [$inv, $kind, $of, $amount, 'Cash', $cash]
    );
    assert_throws(fn () => $row('Payment', '-10.00', null), 'a payment cannot be negative');
    assert_throws(fn () => $row('Payment', '10.00', $pay), 'a payment cannot point at another payment');
    assert_throws(fn () => $row('Refund', '10.00', $pay), 'a refund cannot be positive');
    assert_throws(fn () => $row('Refund', '-10.00', null), 'a refund must say which payment it reverses');
    assert_throws(fn () => $row('Refund', '-10.00', 999999), 'the payment it points at must exist');
    assert_throws(fn () => $row('Payment', '0.00', null), 'zero is neither');
    assert_eq(1, $row('Refund', '-10.00', $pay));
});

test('UT-RFD-002', 'asking for a refund creates a pending request only: no money moves and the invoice does not change', function () {
    [, $inv, $pay, $cash] = paid_visit();
    $r = ask_refund($pay, '200', $cash, 'Test was not done', 'rev-001');
    assert_true($r['ok']);
    assert_true((bool) preg_match('/^RF-\d{4}-\d{6}$/', $r['reference']));
    $rr = get_refund_request($r['id']);
    assert_same('Pending', $rr['status']);
    assert_eq(200, $rr['amount']);
    assert_same('REV-001', $rr['refund_reference']);
    assert_eq($cash, $rr['requested_by']);
    assert_null($rr['decided_by']);
    assert_null($rr['refund_payment_id']);
    assert_eq(1, db_value('SELECT COUNT(*) FROM payments WHERE invoice_id = ?', [$inv]));
    $invoice = get_invoice($inv);
    assert_eq(500, $invoice['amount_paid']);
    assert_same('Paid', $invoice['status']);
    $audit = db_one("SELECT details FROM audit_log WHERE action = 'refund_request'");
    assert_contains($r['reference'], $audit['details']);
    assert_not_contains('Test was not done', $audit['details'], 'the audit trail holds the amount and invoice, not the reason');
});

test('UT-RFD-003', 'a refund request is checked: amount, reason and reference', function () {
    [, , $pay, $cash] = paid_visit();
    $bad = [
        'zero'           => [['amount' => '0', 'reason' => 'Valid reason'], 'amount'],
        'words'          => [['amount' => 'lots', 'reason' => 'Valid reason'], 'amount'],
        'three decimals' => [['amount' => '10.555', 'reason' => 'Valid reason'], 'amount'],
        'more than paid' => [['amount' => '500.01', 'reason' => 'Valid reason'], 'amount'],
        'no reason'      => [['amount' => '100', 'reason' => ''], 'reason'],
        'short reason'   => [['amount' => '100', 'reason' => 'no'], 'reason'],
        'odd reference'  => [['amount' => '100', 'reason' => 'Valid reason', 'refund_reference' => 'a b;c'], 'refund_reference'],
    ];
    foreach ($bad as $label => [$in, $field]) {
        $r = request_refund($pay, $in, $cash);
        assert_false($r['ok'], $label);
        assert_has_key($field, $r['errors'], $label);
    }
    assert_eq(0, db_value('SELECT COUNT(*) FROM refund_requests'));
    assert_false(request_refund(999999, ['amount' => '10', 'reason' => 'Valid reason'], $cash)['ok'], 'unknown payment');
    assert_false(request_refund($pay, ['amount' => '1,000', 'reason' => 'Valid reason'], $cash)['ok'], 'a comma is removed, 1000 is still too much');
    assert_true(request_refund($pay, ['amount' => '500.00', 'reason' => 'Paid twice by mistake'], $cash)['ok'], 'the whole payment can be refunded');
});

test('UT-RFD-004', 'requests that are still waiting reserve their amount, so the same money cannot be asked for twice', function () {
    [, , $pay, $cash] = paid_visit();
    assert_true(ask_refund($pay, '300', $cash)['ok']);
    assert_eq(200, payment_refundable($pay));
    $second = ask_refund($pay, '300', $cash);
    assert_false($second['ok']);
    assert_contains('200.00', $second['errors']['amount']);
    assert_true(ask_refund($pay, '200', $cash)['ok']);
    assert_eq(0, payment_refundable($pay));
    $third = ask_refund($pay, '1', $cash);
    assert_contains('Nothing is left to refund', $third['errors']['amount']);
});

test('UT-RFD-005', 'approving writes a negative refund line against the payment and lowers what the invoice has received', function () {
    [, $inv, $pay, $cash, $mgr] = paid_visit();
    $r = ask_refund($pay, '200', $cash);
    $d = decide_refund($r['id'], 'approve', 'Agreed', $mgr);
    assert_true($d['ok']);
    $line = db_one('SELECT * FROM payments WHERE id = ?', [$d['payment_id']]);
    assert_same('Refund', $line['kind']);
    assert_eq(-200, $line['amount']);
    assert_eq($pay, $line['refund_of']);
    assert_same('Cash', $line['method']);
    assert_eq($cash, $line['received_by'], 'the till the money leaves is the cashier who asked');
    $invoice = get_invoice($inv);
    assert_eq(300, $invoice['amount_paid']);
    assert_same('Partially paid', $invoice['status']);
    $rr = get_refund_request($r['id']);
    assert_same('Approved', $rr['status']);
    assert_eq($mgr, $rr['decided_by']);
    assert_eq($d['payment_id'], $rr['refund_payment_id']);
    assert_same('Agreed', $rr['decision_note']);
    assert_eq(300, payment_refundable($pay));
    assert_eq(1, db_value("SELECT COUNT(*) FROM audit_log WHERE action = 'refund_approve'"));
    assert_eq(1, db_value("SELECT COUNT(*) FROM audit_log WHERE action = 'refund' AND entity = 'payment'"));
    assert_same([], data_integrity_problems(), 'the books still reconcile');
});

test('UT-RFD-006', 'a refund of the whole payment leaves the invoice unpaid, and part refunds can follow one another', function () {
    [, $inv, $pay, $cash, $mgr] = paid_visit();
    $a = ask_refund($pay, '100', $cash);
    assert_true(decide_refund($a['id'], 'approve', '', $mgr)['ok']);
    assert_eq(400, payment_refundable($pay));
    $b = ask_refund($pay, '400', $cash, 'The rest as well');
    assert_true(decide_refund($b['id'], 'approve', '', $mgr)['ok']);
    $invoice = get_invoice($inv);
    assert_eq(0, $invoice['amount_paid']);
    assert_same('Unpaid', $invoice['status']);
    assert_eq(0, payment_refundable($pay));
    assert_eq(3, db_value('SELECT COUNT(*) FROM payments WHERE invoice_id = ?', [$inv]), 'the original line is still there, with two refund lines');
    assert_same([], data_integrity_problems());
});

test('UT-RFD-007', 'nobody can decide a refund they asked for, in the application or in the database', function () {
    [, , $pay, $cash, $mgr] = paid_visit();
    $r = ask_refund($pay, '100', $cash);
    $self = decide_refund($r['id'], 'approve', '', $cash);
    assert_false($self['ok']);
    assert_contains('cannot decide a refund you asked for', $self['error']);
    assert_same('Pending', get_refund_request($r['id'])['status']);
    assert_eq(1, db_value('SELECT COUNT(*) FROM payments'));
    assert_throws(fn () => db_exec("UPDATE refund_requests SET status = 'Rejected', decided_by = requested_by, decided_at = NOW() WHERE id = ?", [$r['id']]), 'the database refuses it as well');
    assert_true(decide_refund($r['id'], 'approve', '', $mgr)['ok']);
});

test('UT-RFD-008', 'a rejection needs a reason and leaves the money record untouched; a decision cannot be made twice', function () {
    [, $inv, $pay, $cash, $mgr] = paid_visit();
    $r = ask_refund($pay, '100', $cash);
    $no = decide_refund($r['id'], 'reject', '', $mgr);
    assert_false($no['ok']);
    assert_contains('why the refund is rejected', $no['error']);
    assert_same('Pending', get_refund_request($r['id'])['status']);
    assert_true(decide_refund($r['id'], 'reject', 'The receipt shows the test was done', $mgr)['ok']);
    $rr = get_refund_request($r['id']);
    assert_same('Rejected', $rr['status']);
    assert_same('The receipt shows the test was done', $rr['decision_note']);
    assert_eq(1, db_value('SELECT COUNT(*) FROM payments WHERE invoice_id = ?', [$inv]));
    assert_eq(500, get_invoice($inv)['amount_paid']);
    assert_contains('already been rejected', decide_refund($r['id'], 'approve', '', $mgr)['error']);
    assert_false(decide_refund($r['id'], 'maybe', '', $mgr)['ok']);
    assert_false(decide_refund(999999, 'approve', '', $mgr)['ok']);
    assert_eq(500, payment_refundable($pay), 'a rejected request releases its reservation');
});

test('UT-RFD-009', 'only the cashier who asked can withdraw a waiting request', function () {
    [, , $pay, $cash, $mgr] = paid_visit();
    $other = make_user('cashier');
    $r = ask_refund($pay, '100', $cash);
    assert_contains('Only the person who asked', (string) cancel_refund_request($r['id'], $other));
    assert_contains('Only the person who asked', (string) cancel_refund_request($r['id'], $mgr));
    assert_null(cancel_refund_request($r['id'], $cash));
    $rr = get_refund_request($r['id']);
    assert_same('Cancelled', $rr['status']);
    assert_contains('already been cancelled', (string) cancel_refund_request($r['id'], $cash));
    assert_contains('already been cancelled', decide_refund($r['id'], 'approve', '', $mgr)['error']);
    assert_eq(500, payment_refundable($pay));
    assert_contains('does not exist', (string) cancel_refund_request(999999, $cash));
});

test('UT-RFD-010', 'a request that is no longer covered by the payment cannot be approved', function () {
    [, $inv, $pay, $cash, $mgr] = paid_visit();
    $first = ask_refund($pay, '400', $cash);
    // a second request made around the reservation, as if two had been waiting from before the rule existed
    db_exec("INSERT INTO refund_requests (payment_id, invoice_id, amount, reason, requested_by) VALUES (?, ?, 300, 'Duplicate request', ?)", [$pay, $inv, $cash]);
    $dup = (int) db_value('SELECT MAX(id) FROM refund_requests');
    assert_true(decide_refund($first['id'], 'approve', '', $mgr)['ok']);
    $late = decide_refund($dup, 'approve', '', $mgr);
    assert_false($late['ok']);
    assert_contains('already been refunded', $late['error']);
    assert_eq(100, get_invoice($inv)['amount_paid']);
    assert_same('Pending', get_refund_request($dup)['status'], 'it stays pending, to be rejected');
    assert_same([], data_integrity_problems());
});

test('UT-RFD-011', 'a refund voucher is numbered REF and a payment receipt RCT, and a refunded M-Pesa code does not block a new payment', function () {
    [$vid, $inv, $pay, $cash, $mgr] = paid_visit('M-Pesa', 'QJK4H2M9XP');
    $r = ask_refund($pay, '100', $cash, 'Overcharged', 'REVERSAL01');
    $d = decide_refund($r['id'], 'approve', '', $mgr);
    $refund = get_payment($d['payment_id']);
    assert_true((bool) preg_match('/^REF-\d{4}-\d{6}$/', receipt_number($refund)));
    assert_true((bool) preg_match('/^RCT-\d{4}-\d{6}$/', receipt_number(get_payment($pay))));
    assert_same('M-Pesa', $refund['method']);
    assert_same('REVERSAL01', $refund['reference']);
    // the reversal code sits on a refund line, so it is not a "used payment code"
    $inv2 = inv_for(consulted_visit()[0]);
    assert_true(pay((int) $inv2['id'], 'M-Pesa', '100', 'REVERSAL01', $cash)['ok'], 'only payment lines count for the duplicate check');
    assert_false(pay((int) $inv2['id'], 'M-Pesa', '100', 'QJK4H2M9XP', $cash)['ok'], 'the original payment code is still used');
});

test('UT-RFD-012', 'collections for the day are net of refunds and count payments and refunds separately', function () {
    [, , $pay, $cash, $mgr] = paid_visit();
    $r = ask_refund($pay, '120.50', $cash);
    decide_refund($r['id'], 'approve', '', $mgr);
    $day = collections_for_day(date('Y-m-d'));
    assert_eq(379.5, $day['total']);
    assert_eq(1, $day['count'], 'one payment taken');
    assert_eq(1, $day['refunds']);
    assert_eq(120.5, $day['refunded']);
    assert_eq(379.5, $day['methods']['Cash']);
    $mine = collections_for_day(date('Y-m-d'), $cash);
    assert_eq(379.5, $mine['total']);
    $someoneElse = collections_for_day(date('Y-m-d'), $mgr);
    assert_eq(0, $someoneElse['total']);
});

test('UT-RFD-013', 'the revenue report shows collected net of refunds and the refunded amount by day and method', function () {
    [, , $pay, $cash, $mgr] = paid_visit('Card', 'AB12CD');
    $r = ask_refund($pay, '200', $cash);
    decide_refund($r['id'], 'approve', '', $mgr);
    $today = date('Y-m-d');
    $rep = report_revenue($today, $today);
    assert_eq(300, $rep['collected']);
    assert_eq(200, $rep['refunded']);
    assert_eq(1, $rep['refund_count']);
    assert_eq(300, $rep['methods']['Card']['amount']);
    assert_eq(200, $rep['methods']['Card']['refunded']);
    assert_eq(1, $rep['methods']['Card']['count'], 'payments are counted without the refund lines');
    assert_eq(300, $rep['days'][0]['collected']);
    assert_eq(200, $rep['days'][0]['refunded']);
    assert_eq(1, $rep['days'][0]['payments']);
    assert_eq(200, $rep['outstanding'], 'the refunded 200 is owed again');
});

test('UT-RFD-014', 'the daily chart shows net collections and never a negative bar', function () {
    [, , $pay, $cash, $mgr] = paid_visit();
    $r = ask_refund($pay, '500', $cash);
    decide_refund($r['id'], 'approve', '', $mgr);
    // move the refund to the next day so that day is net negative
    db_exec("UPDATE payments SET paid_at = '2026-11-03 09:00:00' WHERE kind = 'Refund'");
    db_exec("UPDATE payments SET paid_at = '2026-11-02 09:00:00' WHERE kind = 'Payment'");
    freeze_time('2026-11-03 12:00:00');
    $days = an_days(3);
    $series = an_series_collections($days);
    assert_same([0.0, 500.0, 0.0], array_values($series), 'a day with more refunded than taken is shown as zero');
    assert_eq(-500, db_value("SELECT SUM(amount) FROM payments WHERE DATE(paid_at) = '2026-11-03'"));
});

test('UT-RFD-015', 'a charge can be removed after the money for it has been refunded', function () {
    [$vid, $doc, $pat] = consulted_visit();
    $cash = make_user('cashier');
    $mgr = make_user('manager');
    request_lab_tests($vid, [lab_test_id('Malaria test (BS/MPS)')], '', $doc);
    $inv = inv_for($vid);
    assert_eq(800, $inv['total']);
    $p = pay((int) $inv['id'], 'Cash', '800.00', '', $cash);
    $labId = (int) visit_lab_requests($vid)[0]['id'];
    assert_contains('request a refund', (string) cancel_lab_request($labId, $doc));
    $r = ask_refund($p['payment_id'], '300', $cash, 'The test was cancelled');
    decide_refund($r['id'], 'approve', '', $mgr);
    assert_null(cancel_lab_request($labId, $doc));
    $after = get_invoice((int) $inv['id']);
    assert_eq(500, $after['total']);
    assert_eq(500, $after['amount_paid']);
    assert_same('Paid', $after['status']);
    assert_same([], data_integrity_problems());
});

test('UT-RFD-016', 'the integrity check notices a refund with no approved request, an oversized refund, or one on the wrong invoice', function () {
    [$vid, $inv, $pay, $cash, $mgr] = paid_visit();
    assert_same([], data_integrity_problems());
    // a refund line written behind the application's back: the totals add up, but no request approved it
    db_exec("INSERT INTO payments (invoice_id, kind, refund_of, amount, method, received_by) VALUES (?, 'Refund', ?, -100, 'Cash', ?)", [$inv, $pay, $cash]);
    db_exec("UPDATE invoices SET amount_paid = 400, status = 'Partially paid' WHERE id = ?", [$inv]);
    $p = implode('|', data_integrity_problems());
    assert_contains('A refund has no matching approved request', $p);
    assert_not_contains('Amount paid differs', $p);

    // a refund larger than the payment it reverses (and so more than the invoice ever received)
    [, $inv2, $pay2, $cash2] = paid_visit();
    db_exec("INSERT INTO payments (invoice_id, kind, refund_of, amount, method, received_by) VALUES (?, 'Refund', ?, -300, 'Cash', ?), (?, 'Refund', ?, -300, 'Cash', ?)", [$inv2, $pay2, $cash2, $inv2, $pay2, $cash2]);
    db_exec("UPDATE invoices SET amount_paid = 0, status = 'Unpaid' WHERE id = ?", [$inv2]);
    assert_contains('A refund is larger than the payment it reverses', implode('|', data_integrity_problems()));

    // a refund recorded on a different invoice from the payment it points at
    [, $inv3, $pay3, $cash3] = paid_visit();
    db_exec("INSERT INTO payments (invoice_id, kind, refund_of, amount, method, received_by) VALUES (?, 'Refund', ?, -50, 'Cash', ?)", [$inv, $pay3, $cash3]);
    assert_contains('A refund is recorded on a different invoice from its payment', implode('|', data_integrity_problems()));
});

test('UT-RFD-017', 'cashiers ask and see, the manager decides and sees, and nobody else is involved in refunds', function () {
    assert_true(role_can('cashier', 'refunds.request'));
    assert_true(role_can('cashier', 'refunds.view'));
    assert_false(role_can('cashier', 'refunds.approve'), 'the person who asks cannot also decide');
    assert_true(role_can('manager', 'refunds.approve'));
    assert_true(role_can('manager', 'refunds.view'));
    assert_false(role_can('manager', 'refunds.request'), 'the person who decides cannot also ask');
    assert_false(role_can('manager', 'billing.view'), 'the manager decides without opening invoices, so never sees patient names');
    foreach (['admin', 'receptionist', 'nurse', 'doctor', 'lab', 'pharmacist'] as $role) {
        foreach (['refunds.view', 'refunds.request', 'refunds.approve'] as $perm) {
            assert_false(role_can($role, $perm), "$role $perm");
        }
    }
});

test('UT-RFD-018', 'the refund list carries invoice numbers and amounts but never a patient name, and can be searched', function () {
    [, $inv, $pay, $cash] = paid_visit();
    $r = ask_refund($pay, '100', $cash);
    $rows = list_refund_requests();
    assert_count(1, $rows);
    foreach (['first_name', 'last_name', 'middle_name', 'op_number', 'patient_id'] as $key) {
        assert_false(array_key_exists($key, $rows[0]), "the list row has no $key");
    }
    assert_same(get_invoice($inv)['invoice_no'], $rows[0]['invoice_no']);
    assert_count(1, list_refund_requests(['status' => 'Pending']));
    assert_count(0, list_refund_requests(['status' => 'Approved']));
    assert_count(1, list_refund_requests(['q' => $r['reference']]));
    assert_count(1, list_refund_requests(['q' => substr(get_invoice($inv)['invoice_no'], 0, 8)]));
    assert_count(0, list_refund_requests(['q' => 'RF-9999']));
    assert_count(1, list_refund_requests(['status' => 'nonsense']), 'an unknown status filter is ignored, not trusted');
    assert_count(0, list_refund_requests(['q' => '%']), 'a percent sign is searched for, not used as a wildcard');
});

test('UT-RFD-019', 'the manager is told how many refunds wait, and the count and badge clear once they are decided', function () {
    [, , $pay, $cash, $mgr] = paid_visit();
    $r = ask_refund($pay, '100', $cash);
    $c = shell_counts(['id' => $mgr + 5000, 'role' => 'manager']);
    assert_eq(1, $c['refunds_pending']);
    $titles = implode('|', array_column(notifications_for(['id' => $mgr, 'role' => 'manager'], $c), 'title'));
    assert_contains('1 refund request waiting for your decision', $titles);
    assert_eq(1, nav_badges(['id' => $mgr, 'role' => 'manager'], $c)['refunds']);
    assert_false(array_key_exists('refunds_pending', shell_counts(['id' => $cash + 5000, 'role' => 'cashier'])), 'the cashier is not asked to decide');
    decide_refund($r['id'], 'reject', 'No', $mgr);
    $c2 = shell_counts(['id' => $mgr + 6000, 'role' => 'manager']);
    assert_eq(0, $c2['refunds_pending']);
    assert_false(isset(nav_badges(['id' => $mgr, 'role' => 'manager'], $c2)['refunds']));
});
