<?php
declare(strict_types=1);

suite('Module 4: pharmacy and inventory');

function make_medicine(string $name = 'Paracetamol', string $price = '5.00', int $reorder = 20, string $strength = '500 mg', string $form = 'Tablet'): int
{
    $actor = (int) (db_value("SELECT id FROM users WHERE role_id = 7 LIMIT 1") ?: make_user('pharmacist'));
    [$c, $errors] = validate_medicine_input(['name' => $name, 'form' => $form, 'strength' => $strength, 'unit' => 'tablet', 'unit_price' => $price, 'reorder_level' => (string) $reorder, 'is_active' => '1']);
    if ($errors) {
        throw new RuntimeException('make_medicine: ' . json_encode($errors));
    }
    return save_medicine(null, $c, $actor);
}

/** Receive a batch using the real function; expiry is a date string. */
function stock_up(int $medicineId, string $batch, string $expiry, int $qty): int
{
    $actor = (int) (db_value("SELECT id FROM users WHERE role_id = 7 LIMIT 1") ?: make_user('pharmacist'));
    $r = receive_batch(['medicine_id' => $medicineId, 'batch_no' => $batch, 'expiry_date' => $expiry, 'quantity' => (string) $qty], $actor);
    if (!$r['ok']) {
        throw new RuntimeException('stock_up: ' . json_encode($r['errors']));
    }
    return $r['batch_id'];
}

/** Write a prescription for one or more [medicineId => quantity] on a fresh consulted visit; returns [rxId, visitId, doctorId, itemIds]. */
function prescribe_for_test(array $lines, ?string $allergies = null): array
{
    [$vid, $doc, $pat] = consulted_visit();
    if ($allergies !== null) {
        db_exec('UPDATE patients SET allergies = ? WHERE id = ?', [$allergies, $pat]);
    }
    $items = [];
    foreach ($lines as $mid => $qty) {
        $items[] = ['medicine_id' => $mid, 'dosage' => '1 tablet', 'frequency' => 'twice a day', 'duration' => '5 days', 'quantity' => $qty];
    }
    $r = create_prescription($vid, $items, $doc);
    if (!$r['ok']) {
        throw new RuntimeException('prescribe_for_test: ' . $r['error']);
    }
    $ids = array_column(db_all('SELECT id FROM prescription_items WHERE prescription_id = ? ORDER BY id', [$r['id']]), 'id');
    return [$r['id'], $vid, $doc, array_map('intval', $ids), $r['warnings']];
}

function on_hand(int $medicineId): int
{
    foreach (medicine_stock() as $m) {
        if ((int) $m['id'] === $medicineId) {
            return $m['on_hand'];
        }
    }
    return -1;
}

test('UT-PHA-001', 'medicine details are validated and the same medicine cannot be listed twice', function () {
    [, $e] = validate_medicine_input(['name' => '', 'unit_price' => 'abc', 'reorder_level' => '-5']);
    foreach (['name', 'unit_price', 'reorder_level'] as $f) {
        assert_has_key($f, $e);
    }
    make_medicine('Amoxicillin', '12.50', 30, '500 mg', 'Capsule');
    [, $dup] = validate_medicine_input(['name' => 'Amoxicillin', 'form' => 'Capsule', 'strength' => '500 mg', 'unit_price' => '10', 'reorder_level' => '5']);
    assert_has_key('name', $dup);
    make_medicine('Amoxicillin', '8.00', 30, '250 mg', 'Capsule'); // different strength is a different medicine
    assert_eq(2, db_value('SELECT COUNT(*) FROM medicines'));
});

test('UT-PHA-002', 'receiving stock creates a batch, a ledger entry and an audit record', function () {
    freeze_time('2026-11-02 09:00:00');
    $m = make_medicine();
    $b = stock_up($m, 'pc2026a', '2027-08-01', 200);
    $batch = db_one('SELECT * FROM medicine_batches WHERE id = ?', [$b]);
    assert_same('PC2026A', $batch['batch_no'], 'batch numbers are stored in upper case');
    assert_eq(200, $batch['quantity']);
    $mv = db_one('SELECT * FROM stock_movements WHERE batch_id = ?', [$b]);
    assert_same('Receive', $mv['type']);
    assert_eq(200, $mv['quantity']);
    assert_eq(1, db_value("SELECT COUNT(*) FROM audit_log WHERE entity = 'medicine_batch' AND action = 'receive'"));
});

test('UT-PHA-003', 'stock that is already expired or has bad details is refused at receipt', function () {
    freeze_time('2026-11-02 09:00:00');
    $m = make_medicine();
    $u = make_user('pharmacist');
    $try = fn (array $o) => receive_batch($o + ['medicine_id' => $m, 'batch_no' => 'B1', 'expiry_date' => '2027-01-01', 'quantity' => '10'], $u);
    assert_has_key('expiry_date', $try(['expiry_date' => '2026-11-02'])['errors'], 'expires today');
    assert_has_key('expiry_date', $try(['expiry_date' => '2026-01-01'])['errors']);
    assert_has_key('quantity', $try(['quantity' => '0'])['errors']);
    assert_has_key('quantity', $try(['quantity' => '-5'])['errors']);
    assert_has_key('quantity', $try(['quantity' => '1.5'])['errors']);
    assert_has_key('batch_no', $try(['batch_no' => ''])['errors']);
    assert_has_key('medicine_id', $try(['medicine_id' => 99999])['errors']);
    assert_eq(0, db_value('SELECT COUNT(*) FROM medicine_batches'));
});

test('UT-PHA-004', 'receiving more of an existing batch adds to it only when the expiry date matches', function () {
    freeze_time('2026-11-02 09:00:00');
    $m = make_medicine();
    $u = make_user('pharmacist');
    $b = stock_up($m, 'TOPUP', '2027-05-05', 50);
    assert_true(receive_batch(['medicine_id' => $m, 'batch_no' => 'TOPUP', 'expiry_date' => '2027-05-05', 'quantity' => '25'], $u)['ok']);
    assert_eq(75, db_value('SELECT quantity FROM medicine_batches WHERE id = ?', [$b]));
    $bad = receive_batch(['medicine_id' => $m, 'batch_no' => 'TOPUP', 'expiry_date' => '2027-06-06', 'quantity' => '25'], $u);
    assert_false($bad['ok']);
    assert_eq(75, db_value('SELECT quantity FROM medicine_batches WHERE id = ?', [$b]));
    assert_eq(1, db_value('SELECT COUNT(*) FROM medicine_batches'));
});

test('UT-PHA-005', 'usable stock ignores expired batches and status is OK, Low or Out against the reorder level', function () {
    freeze_time('2026-11-02 09:00:00');
    $m = make_medicine('Metformin', '3.00', 100);
    assert_same('Out', medicine_stock()[0]['status']);
    stock_up($m, 'M1', '2027-06-01', 100);
    assert_same('Low', medicine_stock()[0]['status'], 'exactly at the reorder level is Low');
    stock_up($m, 'M2', '2027-09-01', 1);
    $row = medicine_stock()[0];
    assert_same('OK', $row['status']);
    assert_eq(101, $row['on_hand']);
    assert_same('2027-06-01', $row['next_expiry']);
    // an expired batch is not usable stock
    db_exec("INSERT INTO medicine_batches (medicine_id, batch_no, expiry_date, quantity) VALUES (?, 'OLD', '2026-10-01', 500)", [$m]);
    $row = medicine_stock()[0];
    assert_eq(101, $row['on_hand'], 'expired stock does not count');
    assert_eq(500, $row['expired_qty']);
});

test('UT-PHA-006', 'expiry_state flags batches expiring within 90 days and those already expired', function () {
    assert_same('Expired', expiry_state('2026-11-01', '2026-11-02'));
    assert_same('Expiring', expiry_state('2026-11-02', '2026-11-02'), 'expires today but is still usable');
    assert_same('Expiring', expiry_state('2027-01-31', '2026-11-02'), 'exactly 90 days');
    assert_same('OK', expiry_state('2027-02-01', '2026-11-02'), '91 days');
});

test('UT-PHA-007', 'alerts list low stock, out of stock, expiring and expired batches', function () {
    freeze_time('2026-11-02 09:00:00');
    $out = make_medicine('Outdrug', '1', 10);
    $low = make_medicine('Lowdrug', '1', 50);
    $fine = make_medicine('Finedrug', '1', 10);
    $exp = make_medicine('Expdrug', '1', 10);
    stock_up($low, 'L1', '2027-12-01', 20);
    stock_up($fine, 'F1', '2027-12-01', 500);
    stock_up($exp, 'E1', '2026-12-15', 300);                 // expiring in 43 days
    db_exec("INSERT INTO medicine_batches (medicine_id, batch_no, expiry_date, quantity) VALUES (?, 'E0', '2026-10-15', 40)", [$exp]); // expired
    stock_up($exp, 'E2', '2028-01-01', 200);
    $a = stock_alerts();
    assert_same(['Outdrug'], array_column($a['out'], 'name'));
    assert_same(['Lowdrug'], array_column($a['low'], 'name'));
    assert_same(['E1'], array_column($a['expiring'], 'batch_no'));
    assert_same(['E0'], array_column($a['expired'], 'batch_no'));
    sign_in_as(make_user('pharmacist'));
    $html = dashboard_alerts_pharmacy($_SESSION['user']);
    assert_contains('Outdrug', $html);
    assert_contains('Lowdrug', $html);
    assert_contains('Remove from the shelf', $html);
});

test('UT-PHA-008', 'stock adjustments need a reason, cannot go below zero and are recorded in the ledger', function () {
    freeze_time('2026-11-02 09:00:00');
    $m = make_medicine();
    $u = make_user('pharmacist');
    $b = stock_up($m, 'ADJ', '2027-06-01', 30);
    assert_not_null(adjust_batch($b, -31, 'Damaged', $u), 'cannot remove more than the batch holds');
    assert_not_null(adjust_batch($b, -5, 'Because', $u), 'unknown reason');
    assert_not_null(adjust_batch($b, 0, 'Damaged', $u), 'zero is meaningless');
    assert_null(adjust_batch($b, -5, 'Damaged', $u));
    assert_null(adjust_batch($b, 2, 'Count correction', $u));
    assert_eq(27, db_value('SELECT quantity FROM medicine_batches WHERE id = ?', [$b]));
    assert_same([30, -5, 2], array_map('intval', array_column(db_all('SELECT quantity FROM stock_movements WHERE batch_id = ? ORDER BY id', [$b]), 'quantity')));
    assert_not_null(adjust_batch(99999, -1, 'Damaged', $u));
});

test('UT-PHA-009', 'prescribing needs an open consulted visit and complete, valid lines', function () {
    [$vid, $doc, , $rec] = consulted_visit();
    $m = make_medicine();
    $line = ['medicine_id' => $m, 'dosage' => '1 tablet', 'frequency' => 'twice a day', 'duration' => '3 days', 'quantity' => '10'];
    assert_false(create_prescription($vid, [], $doc)['ok'], 'no lines');
    assert_false(create_prescription($vid, [['medicine_id' => '', 'quantity' => '', 'dosage' => '', 'frequency' => '']], $doc)['ok'], 'only empty rows');
    assert_contains('quantity', create_prescription($vid, [array_merge($line, ['quantity' => '0'])], $doc)['error']);
    assert_contains('quantity', create_prescription($vid, [array_merge($line, ['quantity' => '2.5'])], $doc)['error']);
    assert_contains('how often', create_prescription($vid, [array_merge($line, ['frequency' => ''])], $doc)['error']);
    assert_contains('already on this prescription', create_prescription($vid, [$line, $line], $doc)['error']);
    assert_contains('choose a medicine', create_prescription($vid, [array_merge($line, ['medicine_id' => '9999'])], $doc)['error']);
    $ok = create_prescription($vid, [$line, ['medicine_id' => '', 'dosage' => '', 'frequency' => '', 'quantity' => '']], $doc);
    assert_true($ok['ok'], 'blank rows are ignored');
    assert_eq(1, db_value('SELECT COUNT(*) FROM prescription_items'));
    assert_same('Pending', db_value('SELECT status FROM prescriptions WHERE id = ?', [$ok['id']]));
    close_visit($vid, $doc);
    assert_contains('open visit', create_prescription($vid, [$line], $doc)['error']);
});

test('UT-PHA-010', 'prescribing is refused before the consultation is recorded', function () {
    [$rec, , $doc, $pat] = clinic_setup();
    $vid = open_visit($pat, $rec);
    $m = make_medicine();
    $r = create_prescription($vid, [['medicine_id' => $m, 'dosage' => '1', 'frequency' => 'daily', 'quantity' => '5']], $doc);
    assert_false($r['ok']);
    assert_contains('Record the consultation', $r['error']);
});

test('UT-PHA-011', 'a recorded allergy that matches the medicine name produces a reminder', function () {
    $m = make_medicine('Aspirin', '2', 10, '75 mg');
    [, , , , $warnings] = prescribe_for_test([$m => 10], 'Aspirin, peanuts');
    assert_count(1, $warnings);
    assert_contains('Aspirin', $warnings[0]);
    $m2 = make_medicine('Cetirizine', '2', 10, '10 mg');
    [, , , , $none] = prescribe_for_test([$m2 => 10], 'Aspirin, peanuts');
    assert_count(0, $none);
    assert_null(allergy_warning(null, ['name' => 'Aspirin']));
    assert_null(allergy_warning('', ['name' => 'Aspirin']));
});

test('UT-PHA-012', 'dispensing deducts stock, updates the prescription and writes the ledger', function () {
    freeze_time('2026-11-02 09:00:00');
    $m = make_medicine();
    stock_up($m, 'D1', '2027-06-01', 100);
    $ph = make_user('pharmacist');
    [$rx, , , $items] = prescribe_for_test([$m => 20]);
    $r = dispense_prescription($rx, [$items[0] => 20], $ph);
    assert_true($r['ok']);
    assert_eq(80, on_hand($m));
    assert_eq(20, db_value('SELECT dispensed_qty FROM prescription_items WHERE id = ?', [$items[0]]));
    assert_same('Dispensed', db_value('SELECT status FROM prescriptions WHERE id = ?', [$rx]));
    $mv = db_one("SELECT * FROM stock_movements WHERE type = 'Dispense'");
    assert_eq(-20, $mv['quantity']);
    assert_eq($ph, $mv['user_id']);
    assert_count(0, pharmacy_queue(), 'a dispensed prescription leaves the queue');
});

test('UT-PHA-013', 'stock is taken from the earliest-expiring batch first and can span batches', function () {
    freeze_time('2026-11-02 09:00:00');
    $m = make_medicine();
    $late = stock_up($m, 'LATE', '2028-01-01', 100);
    $early = stock_up($m, 'EARLY', '2027-03-01', 30);
    $mid = stock_up($m, 'MID', '2027-09-01', 100);
    $ph = make_user('pharmacist');
    [$rx, , , $items] = prescribe_for_test([$m => 50]);
    $r = dispense_prescription($rx, [$items[0] => 50], $ph);
    assert_true($r['ok']);
    $q = fn (int $b) => (int) db_value('SELECT quantity FROM medicine_batches WHERE id = ?', [$b]);
    assert_eq(0, $q($early), 'earliest batch emptied first');
    assert_eq(80, $q($mid), 'then the next to expire');
    assert_eq(100, $q($late), 'latest untouched');
    assert_same([['batch_no' => 'EARLY', 'qty' => 30], ['batch_no' => 'MID', 'qty' => 20]], $r['lines'][0]['batches']);
    assert_eq(2, db_value("SELECT COUNT(*) FROM stock_movements WHERE type = 'Dispense'"));
});

test('UT-PHA-014', 'expired stock is never dispensed even when it is the only stock', function () {
    freeze_time('2026-11-02 09:00:00');
    $m = make_medicine();
    db_exec("INSERT INTO medicine_batches (medicine_id, batch_no, expiry_date, quantity) VALUES (?, 'DEAD', '2026-10-01', 500)", [$m]);
    $good = stock_up($m, 'GOOD', '2027-06-01', 10);
    $ph = make_user('pharmacist');
    [$rx, , , $items] = prescribe_for_test([$m => 15]);
    $r = dispense_prescription($rx, [$items[0] => 15], $ph);
    assert_false($r['ok']);
    assert_contains('only 10 in stock', $r['error']);
    assert_eq(10, db_value('SELECT quantity FROM medicine_batches WHERE id = ?', [$good]), 'nothing was taken');
    assert_eq(500, db_value("SELECT quantity FROM medicine_batches WHERE batch_no = 'DEAD'"));
    assert_true(dispense_prescription($rx, [$items[0] => 10], $ph)['ok'], 'the usable 10 can be given');
    assert_eq(500, db_value("SELECT quantity FROM medicine_batches WHERE batch_no = 'DEAD'"));
});

test('UT-PHA-015', 'a partial dispense leaves the rest on the prescription until it is completed', function () {
    freeze_time('2026-11-02 09:00:00');
    $m = make_medicine();
    stock_up($m, 'P1', '2027-06-01', 100);
    $ph = make_user('pharmacist');
    [$rx, , , $items] = prescribe_for_test([$m => 30]);
    assert_true(dispense_prescription($rx, [$items[0] => 12], $ph)['ok']);
    assert_same('Partially dispensed', db_value('SELECT status FROM prescriptions WHERE id = ?', [$rx]));
    assert_count(1, pharmacy_queue());
    $p = get_prescription($rx);
    assert_eq(18, $p['items'][0]['remaining']);
    assert_false(dispense_prescription($rx, [$items[0] => 19], $ph)['ok'], 'more than is left');
    assert_true(dispense_prescription($rx, [$items[0] => 18], $ph)['ok']);
    assert_same('Dispensed', db_value('SELECT status FROM prescriptions WHERE id = ?', [$rx]));
    assert_eq(70, on_hand($m));
    assert_false(dispense_prescription($rx, [$items[0] => 1], $ph)['ok'], 'nothing left to give');
});

test('UT-PHA-016', 'dispensing several medicines is all-or-nothing', function () {
    freeze_time('2026-11-02 09:00:00');
    $a = make_medicine('Drug A');
    $b = make_medicine('Drug B');
    stock_up($a, 'A1', '2027-06-01', 100);
    stock_up($b, 'B1', '2027-06-01', 5);
    $ph = make_user('pharmacist');
    [$rx, , , $items] = prescribe_for_test([$a => 10, $b => 10]);
    $r = dispense_prescription($rx, [$items[0] => 10, $items[1] => 10], $ph);
    assert_false($r['ok']);
    assert_eq(100, on_hand($a), 'the first medicine was rolled back');
    assert_eq(5, on_hand($b));
    assert_eq(0, db_value("SELECT COUNT(*) FROM stock_movements WHERE type = 'Dispense'"));
    assert_same('Pending', db_value('SELECT status FROM prescriptions WHERE id = ?', [$rx]));
    assert_true(dispense_prescription($rx, [$items[0] => 10, $items[1] => 5], $ph)['ok'], 'giving what is in stock works');
});

test('UT-PHA-017', 'dispensing needs at least one quantity', function () {
    freeze_time('2026-11-02 09:00:00');
    $m = make_medicine();
    stock_up($m, 'Z1', '2027-06-01', 10);
    [$rx, , , $items] = prescribe_for_test([$m => 5]);
    $ph = make_user('pharmacist');
    assert_false(dispense_prescription($rx, [], $ph)['ok']);
    assert_false(dispense_prescription($rx, [$items[0] => 0], $ph)['ok']);
    assert_false(dispense_prescription(99999, [1 => 1], $ph)['ok']);
    assert_eq(10, on_hand($m));
});

test('UT-PHA-018', 'the stock ledger always agrees with the batch balances', function () {
    freeze_time('2026-11-02 09:00:00');
    $m = make_medicine();
    $ph = make_user('pharmacist');
    $b1 = stock_up($m, 'R1', '2027-03-01', 100);
    stock_up($m, 'R2', '2027-09-01', 60);
    [$rx, , , $items] = prescribe_for_test([$m => 130]);
    dispense_prescription($rx, [$items[0] => 130], $ph);
    adjust_batch($b1, 0 + 0 === 0 ? 3 : 0, 'Count correction', $ph);
    $ledger = (int) db_value('SELECT SUM(quantity) FROM stock_movements WHERE medicine_id = ?', [$m]);
    $shelf = (int) db_value('SELECT SUM(quantity) FROM medicine_batches WHERE medicine_id = ?', [$m]);
    assert_eq($shelf, $ledger);
    assert_eq(33, $shelf);
});

test('UT-PHA-019', 'the database refuses a negative batch balance even if the application is bypassed', function () {
    freeze_time('2026-11-02 09:00:00');
    $m = make_medicine();
    $b = stock_up($m, 'NEG', '2027-06-01', 5);
    $e = assert_throws(fn () => db_exec('UPDATE medicine_batches SET quantity = quantity - 6 WHERE id = ?', [$b]));
    assert_true($e instanceof PDOException);
    assert_eq(5, db_value('SELECT quantity FROM medicine_batches WHERE id = ?', [$b]));
});

test('UT-PHA-020', 'the pharmacy queue lists the oldest prescription first', function () {
    freeze_time('2026-11-02 09:00:00');
    $m = make_medicine();
    stock_up($m, 'Q1', '2027-06-01', 100);
    [$rx1] = prescribe_for_test([$m => 1]);
    [$rx2] = prescribe_for_test([$m => 1]);
    db_exec("UPDATE prescriptions SET created_at = '2020-01-01 08:00:00' WHERE id = ?", [$rx2]); // the database clock is real time
    $q = pharmacy_queue();
    assert_same([$rx2, $rx1], array_map(fn ($r) => (int) $r['id'], $q));
});

test('UT-PHA-021', 'editing a medicine is audited with the fields that changed and the price movement', function () {
    $m = make_medicine('Editdrug', '10.00', 20);
    $u = make_user('pharmacist');
    [$c] = validate_medicine_input(['name' => 'Editdrug', 'form' => 'Tablet', 'strength' => '500 mg', 'unit' => 'tablet', 'unit_price' => '12.50', 'reorder_level' => '20', 'is_active' => '1']);
    save_medicine($m, $c, $u);
    $detail = (string) db_value("SELECT details FROM audit_log WHERE entity = 'medicine' AND action = 'update' ORDER BY id DESC LIMIT 1");
    assert_contains('unit_price (10.00 to 12.50)', $detail);
    assert_not_contains('reorder_level', $detail, 'unchanged fields are not listed');
    save_medicine($m, $c, $u);
    assert_contains('Saved with no changes', (string) db_value("SELECT details FROM audit_log WHERE entity = 'medicine' ORDER BY id DESC LIMIT 1"));
});
