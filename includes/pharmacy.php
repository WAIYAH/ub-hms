<?php
/**
 * Module 4: pharmacy and inventory.
 *
 * Stock is kept in batches with expiry dates. Dispensing takes medicine from
 * the batch that expires first (FEFO) and never from an expired batch. Every
 * change of stock is written to stock_movements, so the balance of any
 * medicine can be rebuilt from the ledger.
 */
declare(strict_types=1);

const ADJUST_REASONS = ['Damaged', 'Expired write-off', 'Count correction', 'Returned to supplier', 'Other'];

/* ---------- Medicines ---------- */

function get_medicine(int $id): ?array
{
    return db_one('SELECT * FROM medicines WHERE id = ?', [$id]);
}

/** Returns [clean, errors]. $editingId lets a medicine keep its own name. */
function validate_medicine_input(array $in, ?int $editingId = null): array
{
    $errors = [];
    $c = [
        'name'          => clean_text($in['name'] ?? '', 100),
        'form'          => clean_text($in['form'] ?? '', 30),
        'strength'      => clean_text($in['strength'] ?? '', 30),
        'unit'          => clean_text($in['unit'] ?? '', 20) ?: 'unit',
        'unit_price'    => clean_text($in['unit_price'] ?? '', 12),
        'reorder_level' => clean_text($in['reorder_level'] ?? '', 8),
        'is_active'     => !empty($in['is_active']) ? 1 : 0,
    ];
    if ($c['name'] === '') {
        $errors['name'] = 'Enter the medicine name.';
    }
    if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $c['unit_price'])) {
        $errors['unit_price'] = 'Enter the price per unit, for example 12.50.';
    }
    if (!preg_match('/^\d{1,6}$/', $c['reorder_level'])) {
        $errors['reorder_level'] = 'Enter the stock level at which to reorder, as a whole number.';
    }
    if (!$errors && db_value('SELECT id FROM medicines WHERE name = ? AND form = ? AND strength = ? AND id <> ?', [$c['name'], $c['form'], $c['strength'], $editingId ?? 0])) {
        $errors['name'] = 'This medicine (same name, form and strength) is already in the list.';
    }
    return [$c, $errors];
}

function save_medicine(?int $id, array $c, int $userId): int
{
    if ($id === null) {
        $id = db_insert(
            'INSERT INTO medicines (name, form, strength, unit, unit_price, reorder_level, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$c['name'], $c['form'], $c['strength'], $c['unit'], $c['unit_price'], (int) $c['reorder_level'], $c['is_active']]
        );
        audit('create', 'medicine', $id, 'Medicine added: ' . $c['name'], $userId);
    } else {
        $old = get_medicine($id);
        $changed = [];
        foreach (['name', 'form', 'strength', 'unit', 'unit_price', 'reorder_level', 'is_active'] as $field) {
            if ((string) ($old[$field] ?? '') !== (string) $c[$field]) {
                $changed[] = $field . ($field === 'unit_price' ? ' (' . $old[$field] . ' to ' . $c[$field] . ')' : '');
            }
        }
        db_exec(
            'UPDATE medicines SET name = ?, form = ?, strength = ?, unit = ?, unit_price = ?, reorder_level = ?, is_active = ? WHERE id = ?',
            [$c['name'], $c['form'], $c['strength'], $c['unit'], $c['unit_price'], (int) $c['reorder_level'], $c['is_active'], $id]
        );
        audit('update', 'medicine', $id, $changed ? 'Changed: ' . implode(', ', $changed) : 'Saved with no changes', $userId);
    }
    return $id;
}

function medicine_label(array $m): string
{
    return implode(' ', array_filter([$m['name'], $m['strength'] ?? '', $m['form'] ?? ''], static fn ($part) => $part !== ''));
}

/**
 * Every medicine with its usable stock (not expired), expired stock, the
 * nearest expiry and a status of OK, Low or Out.
 */
function medicine_stock(string $search = '', bool $activeOnly = false): array
{
    $today = app_today();
    $sql = 'SELECT m.*,
                   COALESCE(SUM(CASE WHEN b.expiry_date >= ? THEN b.quantity END), 0) AS on_hand,
                   COALESCE(SUM(CASE WHEN b.expiry_date <  ? THEN b.quantity END), 0) AS expired_qty,
                   MIN(CASE WHEN b.expiry_date >= ? AND b.quantity > 0 THEN b.expiry_date END) AS next_expiry
              FROM medicines m LEFT JOIN medicine_batches b ON b.medicine_id = m.id WHERE 1 = 1';
    $params = [$today, $today, $today];
    if ($activeOnly) {
        $sql .= ' AND m.is_active = 1';
    }
    if ($search !== '') {
        $sql .= ' AND (m.name LIKE ? OR m.form LIKE ? OR m.strength LIKE ?)';
        $like = '%' . like_escape($search) . '%';
        array_push($params, $like, $like, $like);
    }
    $rows = db_all($sql . ' GROUP BY m.id ORDER BY m.name, m.strength', $params);
    foreach ($rows as &$r) {
        $r['on_hand'] = (int) $r['on_hand'];
        $r['expired_qty'] = (int) $r['expired_qty'];
        $r['status'] = $r['on_hand'] <= 0 ? 'Out' : ($r['on_hand'] <= (int) $r['reorder_level'] ? 'Low' : 'OK');
    }
    unset($r);
    return $rows;
}

/* ---------- Batches and stock movements ---------- */

function expiry_state(string $expiryDate, ?string $today = null): string
{
    $today = $today ?? app_today();
    if ($expiryDate < $today) {
        return 'Expired';
    }
    $limit = (new DateTimeImmutable($today))->modify('+' . (int) config('stock.expiry_warning_days', 90) . ' days')->format('Y-m-d');
    return $expiryDate <= $limit ? 'Expiring' : 'OK';
}

/**
 * Receive stock. Adding to an existing batch number is allowed only when the
 * expiry date is the same. Returns ['ok' => bool, 'errors' => [], 'batch_id' => n].
 */
function receive_batch(array $in, int $userId): array
{
    $errors = [];
    $medicineId = (int) ($in['medicine_id'] ?? 0);
    $batchNo = strtoupper(clean_text($in['batch_no'] ?? '', 40));
    $expiry = clean_text($in['expiry_date'] ?? '', 10);
    $qtyRaw = clean_text($in['quantity'] ?? '', 9);

    if (!get_medicine($medicineId)) {
        $errors['medicine_id'] = 'Choose a medicine.';
    }
    if ($batchNo === '' || !preg_match('/^[A-Z0-9][A-Z0-9\/._-]{0,39}$/', $batchNo)) {
        $errors['batch_no'] = 'Enter the batch number printed on the pack (letters, numbers, - / .).';
    }
    if (!is_valid_date($expiry)) {
        $errors['expiry_date'] = 'Enter the expiry date.';
    } elseif ($expiry <= app_today()) {
        $errors['expiry_date'] = 'This stock has already expired and cannot be received.';
    }
    if (!preg_match('/^[1-9]\d{0,6}$/', $qtyRaw)) {
        $errors['quantity'] = 'Enter the quantity received as a whole number above zero.';
    }
    if ($errors) {
        return ['ok' => false, 'errors' => $errors, 'batch_id' => 0];
    }
    $qty = (int) $qtyRaw;

    try {
        $batchId = db_tx(function () use ($medicineId, $batchNo, $expiry, $qty, $userId): int {
            $existing = db_one('SELECT id, expiry_date FROM medicine_batches WHERE medicine_id = ? AND batch_no = ? FOR UPDATE', [$medicineId, $batchNo]);
            if ($existing) {
                if ($existing['expiry_date'] !== $expiry) {
                    throw new RuntimeException('expiry_mismatch');
                }
                $id = (int) $existing['id'];
                db_exec('UPDATE medicine_batches SET quantity = quantity + ? WHERE id = ?', [$qty, $id]);
            } else {
                $id = db_insert('INSERT INTO medicine_batches (medicine_id, batch_no, expiry_date, quantity) VALUES (?, ?, ?, ?)', [$medicineId, $batchNo, $expiry, $qty]);
            }
            db_insert(
                'INSERT INTO stock_movements (medicine_id, batch_id, type, quantity, reference, user_id) VALUES (?, ?, ?, ?, ?, ?)',
                [$medicineId, $id, 'Receive', $qty, 'Batch ' . $batchNo, $userId]
            );
            audit('receive', 'medicine_batch', $id, "Received $qty units, batch $batchNo", $userId);
            return $id;
        });
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'expiry_mismatch') {
            return ['ok' => false, 'errors' => ['batch_no' => 'This batch number already exists with a different expiry date. Check the pack.'], 'batch_id' => 0];
        }
        throw $e;
    }
    return ['ok' => true, 'errors' => [], 'batch_id' => $batchId];
}

/**
 * Correct a batch balance (damage, expiry write-off, count correction).
 * $change is positive or negative; the balance can never go below zero.
 */
function adjust_batch(int $batchId, int $change, string $reason, int $userId): ?string
{
    if ($change === 0) {
        return 'Enter the amount to add or remove.';
    }
    if (!in_array($reason, ADJUST_REASONS, true)) {
        return 'Choose a reason.';
    }
    if (abs($change) > 1000000) {
        return 'That amount is too large.';
    }
    $err = null;
    db_tx(function () use ($batchId, $change, $reason, $userId, &$err): void {
        $b = db_one('SELECT * FROM medicine_batches WHERE id = ? FOR UPDATE', [$batchId]);
        if (!$b) {
            $err = 'Batch not found.';
            return;
        }
        if ((int) $b['quantity'] + $change < 0) {
            $err = 'The batch holds only ' . (int) $b['quantity'] . ' units, so you cannot remove ' . abs($change) . '.';
            return;
        }
        db_exec('UPDATE medicine_batches SET quantity = quantity + ? WHERE id = ?', [$change, $batchId]);
        db_insert(
            'INSERT INTO stock_movements (medicine_id, batch_id, type, quantity, reference, user_id) VALUES (?, ?, ?, ?, ?, ?)',
            [$b['medicine_id'], $batchId, 'Adjust', $change, $reason, $userId]
        );
        audit('adjust', 'medicine_batch', $batchId, ($change > 0 ? '+' : '') . "$change units: $reason", $userId);
    });
    return $err;
}

/** Batches with their expiry state, nearest expiry first. $onlyWithStock hides empty batches. */
function list_batches(?int $medicineId = null, bool $onlyWithStock = true): array
{
    $sql = 'SELECT b.*, m.name, m.form, m.strength FROM medicine_batches b JOIN medicines m ON m.id = b.medicine_id WHERE 1 = 1';
    $params = [];
    if ($medicineId !== null) {
        $sql .= ' AND b.medicine_id = ?';
        $params[] = $medicineId;
    }
    if ($onlyWithStock) {
        $sql .= ' AND b.quantity > 0';
    }
    $rows = db_all($sql . ' ORDER BY b.expiry_date, b.id', $params);
    foreach ($rows as &$r) {
        $r['state'] = expiry_state($r['expiry_date']);
    }
    unset($r);
    return $rows;
}

/** Medicines that are low or out, and batches that are expiring or expired. */
function stock_alerts(): array
{
    $low = $out = [];
    foreach (medicine_stock('', true) as $m) {
        if ($m['status'] === 'Out') {
            $out[] = $m;
        } elseif ($m['status'] === 'Low') {
            $low[] = $m;
        }
    }
    $expiring = $expired = [];
    foreach (list_batches(null, true) as $b) {
        if ($b['state'] === 'Expired') {
            $expired[] = $b;
        } elseif ($b['state'] === 'Expiring') {
            $expiring[] = $b;
        }
    }
    return ['low' => $low, 'out' => $out, 'expiring' => $expiring, 'expired' => $expired];
}

function stock_movements_for(int $medicineId, int $limit = 50): array
{
    return db_all(
        'SELECT s.*, u.full_name, b.batch_no FROM stock_movements s JOIN users u ON u.id = s.user_id
           LEFT JOIN medicine_batches b ON b.id = s.batch_id WHERE s.medicine_id = ? ORDER BY s.id DESC LIMIT ' . (int) $limit,
        [$medicineId]
    );
}

/* ---------- Prescribing ---------- */

/**
 * A reminder, not a clinical check: warns when a word in the patient's recorded
 * allergies appears in the medicine's name.
 */
function allergy_warning(?string $allergies, array $medicine): ?string
{
    if ($allergies === null || trim($allergies) === '') {
        return null;
    }
    $name = strtolower($medicine['name']);
    foreach (preg_split('/[,;\/\n]+/', strtolower($allergies)) ?: [] as $a) {
        $a = trim($a);
        if (strlen($a) >= 4 && (strpos($name, $a) !== false || strpos($a, $name) !== false)) {
            return 'Recorded allergy "' . trim($a) . '" matches ' . $medicine['name'] . '.';
        }
    }
    return null;
}

/**
 * Create a prescription for an open visit.
 * $items: list of [medicine_id, dosage, frequency, duration, quantity].
 * Returns ['ok' => bool, 'error' => string, 'id' => n, 'warnings' => []].
 */
function create_prescription(int $visitId, array $items, int $doctorId): array
{
    $v = get_visit($visitId);
    if (!$v || $v['status'] !== 'Open') {
        return ['ok' => false, 'error' => 'Prescriptions can only be written on an open visit.', 'id' => 0, 'warnings' => []];
    }
    if (!(int) $v['has_consultation']) {
        return ['ok' => false, 'error' => 'Record the consultation before prescribing.', 'id' => 0, 'warnings' => []];
    }
    $clean = [];
    $warnings = [];
    $seen = [];
    foreach ($items as $i => $it) {
        $mid = (int) ($it['medicine_id'] ?? 0);
        if ($mid === 0) {
            continue; // an empty row on the form
        }
        $n = $i + 1;
        $m = db_one('SELECT * FROM medicines WHERE id = ? AND is_active = 1', [$mid]);
        if (!$m) {
            return ['ok' => false, 'error' => "Line $n: choose a medicine from the list.", 'id' => 0, 'warnings' => []];
        }
        if (isset($seen[$mid])) {
            return ['ok' => false, 'error' => "Line $n: " . medicine_label($m) . ' is already on this prescription.', 'id' => 0, 'warnings' => []];
        }
        $seen[$mid] = true;
        $qty = clean_text((string) ($it['quantity'] ?? ''), 6);
        if (!preg_match('/^[1-9]\d{0,3}$/', $qty)) {
            return ['ok' => false, 'error' => "Line $n: enter the quantity to dispense as a whole number from 1 to 9999.", 'id' => 0, 'warnings' => []];
        }
        $dosage = clean_text($it['dosage'] ?? '', 60);
        $frequency = clean_text($it['frequency'] ?? '', 60);
        if ($dosage === '' || $frequency === '') {
            return ['ok' => false, 'error' => "Line $n: enter the dose and how often to take it.", 'id' => 0, 'warnings' => []];
        }
        $clean[] = ['medicine_id' => $mid, 'dosage' => $dosage, 'frequency' => $frequency, 'duration' => clean_text($it['duration'] ?? '', 60), 'quantity' => (int) $qty];
        if ($w = allergy_warning($v['allergies'], $m)) {
            $warnings[] = $w;
        }
    }
    if (!$clean) {
        return ['ok' => false, 'error' => 'Choose at least one medicine.', 'id' => 0, 'warnings' => []];
    }
    $id = db_tx(function () use ($visitId, $doctorId, $clean): int {
        $rx = db_insert('INSERT INTO prescriptions (visit_id, prescribed_by) VALUES (?, ?)', [$visitId, $doctorId]);
        foreach ($clean as $c) {
            db_insert(
                'INSERT INTO prescription_items (prescription_id, medicine_id, dosage, frequency, duration, quantity) VALUES (?, ?, ?, ?, ?, ?)',
                [$rx, $c['medicine_id'], $c['dosage'], $c['frequency'], $c['duration'] !== '' ? $c['duration'] : null, $c['quantity']]
            );
        }
        audit('create', 'prescription', $rx, count($clean) . ' item(s) prescribed', $doctorId);
        return $rx;
    });
    return ['ok' => true, 'error' => '', 'id' => $id, 'warnings' => $warnings];
}

/** All prescribed items on a visit (for history and the consultation page). */
function visit_prescription_items(int $visitId): array
{
    return db_all(
        'SELECT i.*, p.status AS prescription_status, p.created_at, m.name AS medicine_name, m.form, m.strength, m.unit
           FROM prescription_items i JOIN prescriptions p ON p.id = i.prescription_id JOIN medicines m ON m.id = i.medicine_id
          WHERE p.visit_id = ? ORDER BY p.id, i.id',
        [$visitId]
    );
}

/* ---------- Dispensing ---------- */

/** Prescriptions waiting for the pharmacy, oldest first. */
function pharmacy_queue(): array
{
    return db_all(
        "SELECT rx.*, v.patient_id, p.op_number, p.first_name, p.middle_name, p.last_name, p.allergies, d.full_name AS doctor_name,
                (SELECT COUNT(*) FROM prescription_items i WHERE i.prescription_id = rx.id) AS item_count
           FROM prescriptions rx JOIN visits v ON v.id = rx.visit_id JOIN patients p ON p.id = v.patient_id JOIN users d ON d.id = rx.prescribed_by
          WHERE rx.status IN ('Pending','Partially dispensed') ORDER BY rx.created_at, rx.id"
    );
}

function get_prescription(int $id): ?array
{
    $rx = db_one(
        'SELECT rx.*, v.patient_id, v.status AS visit_status, p.op_number, p.first_name, p.middle_name, p.last_name, p.sex, p.date_of_birth, p.allergies,
                d.full_name AS doctor_name
           FROM prescriptions rx JOIN visits v ON v.id = rx.visit_id JOIN patients p ON p.id = v.patient_id JOIN users d ON d.id = rx.prescribed_by
          WHERE rx.id = ?',
        [$id]
    );
    if (!$rx) {
        return null;
    }
    $rx['items'] = db_all(
        'SELECT i.*, m.name AS medicine_name, m.form, m.strength, m.unit, m.unit_price
           FROM prescription_items i JOIN medicines m ON m.id = i.medicine_id WHERE i.prescription_id = ? ORDER BY i.id',
        [$id]
    );
    $stock = [];
    foreach (medicine_stock() as $m) {
        $stock[(int) $m['id']] = $m['on_hand'];
    }
    foreach ($rx['items'] as &$it) {
        $it['remaining'] = (int) $it['quantity'] - (int) $it['dispensed_qty'];
        $it['available'] = $stock[(int) $it['medicine_id']] ?? 0;
    }
    unset($it);
    return $rx;
}

/**
 * Dispense quantities for the items of one prescription. $quantities maps
 * prescription_item_id => quantity (0 or missing skips an item). The whole
 * dispensing is one transaction: if any line fails, nothing is dispensed.
 * Stock is taken from the batch that expires first and never from an expired batch.
 * Returns ['ok' => bool, 'error' => string, 'lines' => [[item, batches => [[batch_no, qty]]]]].
 */
function dispense_prescription(int $prescriptionId, array $quantities, int $userId): array
{
    try {
        return db_tx(function () use ($prescriptionId, $quantities, $userId): array {
            $rx = db_one('SELECT * FROM prescriptions WHERE id = ? FOR UPDATE', [$prescriptionId]);
            if (!$rx) {
                throw new RuntimeException('dispense:Prescription not found.');
            }
            $items = db_all(
                'SELECT i.*, m.name, m.form, m.strength FROM prescription_items i JOIN medicines m ON m.id = i.medicine_id WHERE i.prescription_id = ? ORDER BY i.id FOR UPDATE',
                [$prescriptionId]
            );
            $today = app_today();
            $lines = [];
            $any = false;
            foreach ($items as $it) {
                $qty = (int) ($quantities[$it['id']] ?? 0);
                if ($qty <= 0) {
                    continue;
                }
                $label = medicine_label($it);
                $remaining = (int) $it['quantity'] - (int) $it['dispensed_qty'];
                if ($qty > $remaining) {
                    throw new RuntimeException("dispense:$label: only $remaining still to dispense.");
                }
                $batches = db_all(
                    'SELECT * FROM medicine_batches WHERE medicine_id = ? AND quantity > 0 AND expiry_date >= ? ORDER BY expiry_date, id FOR UPDATE',
                    [$it['medicine_id'], $today]
                );
                $available = array_sum(array_column($batches, 'quantity'));
                if ($available < $qty) {
                    throw new RuntimeException("dispense:$label: only $available in stock (expired stock cannot be dispensed).");
                }
                $need = $qty;
                $taken = [];
                foreach ($batches as $b) {
                    if ($need === 0) {
                        break;
                    }
                    $take = min($need, (int) $b['quantity']);
                    if (db_exec('UPDATE medicine_batches SET quantity = quantity - ? WHERE id = ? AND quantity >= ?', [$take, $b['id'], $take]) !== 1) {
                        throw new RuntimeException("dispense:$label: stock changed while dispensing. Please try again.");
                    }
                    db_insert(
                        'INSERT INTO stock_movements (medicine_id, batch_id, type, quantity, reference, user_id) VALUES (?, ?, ?, ?, ?, ?)',
                        [$it['medicine_id'], $b['id'], 'Dispense', -$take, 'Rx ' . $prescriptionId . ' item ' . $it['id'], $userId]
                    );
                    $taken[] = ['batch_no' => $b['batch_no'], 'qty' => $take];
                    $need -= $take;
                }
                db_exec('UPDATE prescription_items SET dispensed_qty = dispensed_qty + ? WHERE id = ?', [$qty, $it['id']]);
                $lines[] = ['item' => $label, 'qty' => $qty, 'batches' => $taken];
                $any = true;
            }
            if (!$any) {
                throw new RuntimeException('dispense:Enter a quantity for at least one medicine.');
            }
            $left = (int) db_value('SELECT COALESCE(SUM(quantity - dispensed_qty), 0) FROM prescription_items WHERE prescription_id = ?', [$prescriptionId]);
            db_exec('UPDATE prescriptions SET status = ? WHERE id = ?', [$left === 0 ? 'Dispensed' : 'Partially dispensed', $prescriptionId]);
            audit('dispense', 'prescription', $prescriptionId, count($lines) . ' item(s) dispensed', $userId);
            if (function_exists('billing_sync_visit')) {
                billing_sync_visit((int) $rx['visit_id'], $userId);
            }
            return ['ok' => true, 'error' => '', 'lines' => $lines];
        });
    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), 'dispense:') === 0) {
            return ['ok' => false, 'error' => substr($e->getMessage(), 9), 'lines' => []];
        }
        throw $e;
    }
}

/* ---------- Dashboard ---------- */

function dashboard_tiles_pharmacy(array $user): array
{
    $t = [];
    if (user_can('pharmacy.dispense')) {
        $t[] = dashboard_tile('Prescriptions waiting', db_value("SELECT COUNT(*) FROM prescriptions WHERE status IN ('Pending','Partially dispensed')"), 'pharmacy/index.php');
    }
    if (user_can('pharmacy.stock') && (user_can('pharmacy.dispense') || $user['role'] === 'manager')) {
        $a = stock_alerts();
        $t[] = dashboard_tile('Medicines low or out of stock', count($a['low']) + count($a['out']), 'pharmacy/stock.php', count($a['out']) ? 'danger' : (count($a['low']) ? 'warn' : ''));
        $t[] = dashboard_tile('Batches expiring or expired', count($a['expiring']) + count($a['expired']), 'pharmacy/batches.php', count($a['expired']) ? 'danger' : (count($a['expiring']) ? 'warn' : ''));
    }
    return $t;
}

/** A box on the dashboard listing medicines to reorder and batches to remove. */
function dashboard_alerts_pharmacy(array $user): string
{
    if (!user_can('pharmacy.stock') || !(user_can('pharmacy.dispense') || $user['role'] === 'manager')) {
        return '';
    }
    $a = stock_alerts();
    if (!$a['low'] && !$a['out'] && !$a['expiring'] && !$a['expired']) {
        return '';
    }
    $li = static fn (string $text): string => '<li>' . e($text) . '</li>';
    $out = '';
    if ($a['expired'] || $a['out']) {
        $out .= '<div class="card alert-card danger"><h2>Needs action now</h2><ul>';
        foreach ($a['out'] as $m) {
            $out .= $li(medicine_label($m) . ' is out of stock.');
        }
        foreach ($a['expired'] as $b) {
            $out .= $li(medicine_label($b) . ', batch ' . $b['batch_no'] . ': ' . $b['quantity'] . ' units expired on ' . fmt_date($b['expiry_date']) . '. Remove from the shelf.');
        }
        $out .= '</ul></div>';
    }
    if ($a['low'] || $a['expiring']) {
        $out .= '<div class="card alert-card"><h2>Reorder and use soon</h2><ul>';
        foreach ($a['low'] as $m) {
            $out .= $li(medicine_label($m) . ': ' . $m['on_hand'] . ' left (reorder level ' . $m['reorder_level'] . ').');
        }
        foreach ($a['expiring'] as $b) {
            $out .= $li(medicine_label($b) . ', batch ' . $b['batch_no'] . ': ' . $b['quantity'] . ' units expire on ' . fmt_date($b['expiry_date']) . '.');
        }
        $out .= '</ul></div>';
    }
    return $out;
}
