<?php
/**
 * Module 5: billing.
 *
 * An invoice is created automatically for each visit the first time a billable
 * service is recorded, and is kept in step with what has actually happened:
 * the consultation, laboratory tests, medicines dispensed and procedures.
 * Cashiers cannot type or edit charges; they only take payments.
 */
declare(strict_types=1);

const PAYMENT_METHODS = ['Cash', 'M-Pesa', 'Card', 'Insurance'];

/* ---------- Building the invoice from recorded services ---------- */

/** Everything the visit should be charged for right now: list of [source, source_id, description, quantity, unit_price]. */
function billable_lines(int $visitId): array
{
    $lines = [];

    $c = db_one('SELECT c.id, s.name, s.price FROM consultations c JOIN services s ON s.id = c.service_id WHERE c.visit_id = ?', [$visitId]);
    if ($c) {
        $lines[] = ['Consultation', (int) $c['id'], $c['name'], 1, $c['price']];
    }
    foreach (db_all(
        "SELECT r.id, t.name, t.price FROM lab_requests r JOIN lab_tests t ON t.id = r.test_id WHERE r.visit_id = ? AND r.status <> 'Cancelled' ORDER BY r.id",
        [$visitId]
    ) as $r) {
        $lines[] = ['Laboratory', (int) $r['id'], $r['name'], 1, $r['price']];
    }
    foreach (db_all(
        'SELECT i.id, i.dispensed_qty, m.name, m.form, m.strength, m.unit_price
           FROM prescription_items i JOIN prescriptions p ON p.id = i.prescription_id JOIN medicines m ON m.id = i.medicine_id
          WHERE p.visit_id = ? AND i.dispensed_qty > 0 ORDER BY i.id',
        [$visitId]
    ) as $i) {
        $lines[] = ['Pharmacy', (int) $i['id'], medicine_label($i), (int) $i['dispensed_qty'], $i['unit_price']];
    }
    foreach (db_all(
        'SELECT vs.id, vs.quantity, s.name, s.price FROM visit_services vs JOIN services s ON s.id = vs.service_id WHERE vs.visit_id = ? ORDER BY vs.id',
        [$visitId]
    ) as $s) {
        $lines[] = ['Service', (int) $s['id'], $s['name'], (int) $s['quantity'], $s['price']];
    }
    return $lines;
}

function invoice_status_for(string $total, string $paid): string
{
    $t = to_cents($total);
    $p = to_cents($paid);
    if ($p <= 0) {
        return 'Unpaid';
    }
    return $p >= $t ? 'Paid' : 'Partially paid';
}

/**
 * Bring the visit's invoice up to date with what has been recorded. Safe to
 * call as often as needed: it adds new charges, updates quantities, removes
 * charges whose source was cancelled, and recalculates the total and status.
 * Runs inside the caller's transaction. Throws RuntimeException('billing:...')
 * if removing a charge would leave the invoice over-paid.
 */
function billing_sync_visit(int $visitId, int $userId): void
{
    db_tx(function () use ($visitId, $userId): void {
        $lines = billable_lines($visitId);
        $inv = db_one('SELECT * FROM invoices WHERE visit_id = ? FOR UPDATE', [$visitId]);
        if (!$inv) {
            if (!$lines) {
                return; // nothing to charge yet
            }
            try {
                $id = db_insert('INSERT INTO invoices (invoice_no, visit_id, created_by) VALUES (?, ?, ?)', ['TMP' . bin2hex(random_bytes(6)), $visitId, $userId]);
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                // another request created the invoice a moment ago
                $id = (int) db_value('SELECT id FROM invoices WHERE visit_id = ?', [$visitId]);
            }
            $number = sprintf('INV-%s-%06d', app_now()->format('Y'), $id);
            db_exec("UPDATE invoices SET invoice_no = ? WHERE id = ? AND invoice_no LIKE 'TMP%'", [$number, $id]);
            audit('create', 'invoice', $id, "Invoice $number created automatically for visit $visitId", $userId);
            $inv = db_one('SELECT * FROM invoices WHERE id = ? FOR UPDATE', [$id]);
        }
        $invoiceId = (int) $inv['id'];
        $hasPayments = to_cents($inv['amount_paid']) > 0;

        $keep = [];
        foreach ($lines as [$source, $sourceId, $description, $qty, $price]) {
            $keep[] = $source . ':' . $sourceId;
            $existing = db_one('SELECT id FROM invoice_items WHERE invoice_id = ? AND source = ? AND source_id = ?', [$invoiceId, $source, $sourceId]);
            if (!$existing) {
                db_insert(
                    'INSERT INTO invoice_items (invoice_id, source, source_id, description, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?, ?, ROUND(? * ?, 2))',
                    [$invoiceId, $source, $sourceId, mb_substr($description, 0, 150), $qty, $price, $price, $qty]
                );
            } elseif ($hasPayments) {
                // once money has been taken the agreed price stays; only the quantity can grow (more medicine dispensed)
                db_exec('UPDATE invoice_items SET quantity = ?, line_total = ROUND(unit_price * ?, 2) WHERE id = ?', [$qty, $qty, $existing['id']]);
            } else {
                db_exec(
                    'UPDATE invoice_items SET description = ?, quantity = ?, unit_price = ?, line_total = ROUND(? * ?, 2) WHERE id = ?',
                    [mb_substr($description, 0, 150), $qty, $price, $price, $qty, $existing['id']]
                );
            }
        }
        foreach (db_all('SELECT id, source, source_id FROM invoice_items WHERE invoice_id = ?', [$invoiceId]) as $item) {
            if (!in_array($item['source'] . ':' . $item['source_id'], $keep, true)) {
                db_exec('DELETE FROM invoice_items WHERE id = ?', [$item['id']]);
            }
        }

        $total = (string) db_value('SELECT COALESCE(SUM(line_total), 0) FROM invoice_items WHERE invoice_id = ?', [$invoiceId]);
        if (to_cents($total) < to_cents($inv['amount_paid'])) {
            throw new RuntimeException('billing:This charge cannot be removed because the invoice has already been paid beyond the new total. Ask the cashier to request a refund, which the manager must approve, then try again.');
        }
        db_exec('UPDATE invoices SET total = ?, status = ? WHERE id = ?', [$total, invoice_status_for($total, $inv['amount_paid']), $invoiceId]);
    });
}

/* ---------- Reading invoices ---------- */

function get_invoice(int $id): ?array
{
    return db_one(
        'SELECT i.*, v.patient_id, v.visit_date, p.op_number, p.first_name, p.middle_name, p.last_name, p.phone, u.full_name AS created_by_name
           FROM invoices i JOIN visits v ON v.id = i.visit_id JOIN patients p ON p.id = v.patient_id JOIN users u ON u.id = i.created_by WHERE i.id = ?',
        [$id]
    );
}

function get_invoice_for_visit(int $visitId): ?array
{
    $id = db_value('SELECT id FROM invoices WHERE visit_id = ?', [$visitId]);
    return $id ? get_invoice((int) $id) : null;
}

function invoice_items(int $invoiceId): array
{
    return db_all('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY FIELD(source, \'Consultation\', \'Service\', \'Laboratory\', \'Pharmacy\'), id', [$invoiceId]);
}

function invoice_payments(int $invoiceId): array
{
    return db_all('SELECT p.*, u.full_name AS received_by_name FROM payments p JOIN users u ON u.id = p.received_by WHERE p.invoice_id = ? ORDER BY p.id', [$invoiceId]);
}

function invoice_balance(array $inv): string
{
    return number_format((to_cents($inv['total']) - to_cents($inv['amount_paid'])) / 100, 2, '.', '');
}

/** RCT-year-number for a payment; REF-year-number for the voucher of a refund. */
function receipt_number(array $payment): string
{
    return sprintf(($payment['kind'] ?? 'Payment') === 'Refund' ? 'REF-%s-%06d' : 'RCT-%s-%06d', (new DateTimeImmutable($payment['paid_at']))->format('Y'), $payment['id']);
}

function get_payment(int $id): ?array
{
    return db_one(
        'SELECT pay.*, u.full_name AS received_by_name, i.invoice_no, i.total, i.amount_paid, i.visit_id, v.patient_id,
                p.op_number, p.first_name, p.middle_name, p.last_name
           FROM payments pay JOIN invoices i ON i.id = pay.invoice_id JOIN visits v ON v.id = i.visit_id
           JOIN patients p ON p.id = v.patient_id JOIN users u ON u.id = pay.received_by WHERE pay.id = ?',
        [$id]
    );
}

/**
 * Invoice list. Filters: status, from, to (invoice date), q (invoice number, outpatient
 * number or name), open (only invoices with a balance). Returns [rows, total].
 */
function list_invoices(array $f, int $limit = 25, int $offset = 0): array
{
    $where = ' WHERE 1 = 1';
    $params = [];
    if (!empty($f['status']) && in_array($f['status'], ['Unpaid', 'Partially paid', 'Paid'], true)) {
        $where .= ' AND i.status = ?';
        $params[] = $f['status'];
    }
    if (!empty($f['open'])) {
        $where .= " AND i.status <> 'Paid'";
    }
    if (!empty($f['from']) && is_valid_date($f['from'])) {
        $where .= ' AND i.created_at >= ?';
        $params[] = $f['from'] . ' 00:00:00';
    }
    if (!empty($f['to']) && is_valid_date($f['to'])) {
        $where .= ' AND i.created_at <= ?';
        $params[] = $f['to'] . ' 23:59:59';
    }
    if (!empty($f['q'])) {
        $q = trim((string) $f['q']);
        if (stripos($q, 'INV') === 0) {
            $where .= ' AND i.invoice_no LIKE ?';
            $params[] = like_escape(strtoupper($q)) . '%';
        } else {
            [$clause, $p] = patient_search_clause($q);
            $where .= ' AND ' . $clause;
            $params = array_merge($params, $p);
        }
    }
    $from = ' FROM invoices i JOIN visits v ON v.id = i.visit_id JOIN patients p ON p.id = v.patient_id';
    $total = (int) db_value('SELECT COUNT(*)' . $from . $where, $params);
    $rows = db_all(
        'SELECT i.*, p.op_number, p.first_name, p.middle_name, p.last_name' . $from . $where
        . ' ORDER BY i.id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
        $params
    );
    return [$rows, $total];
}

/* ---------- Taking payments ---------- */

/** Returns [clean, errors] for the payment form against a given invoice. */
function validate_payment_input(array $inv, array $in): array
{
    $errors = [];
    $method = clean_text($in['method'] ?? '', 12);
    $amountRaw = str_replace(',', '', clean_text($in['amount'] ?? '', 14));
    $reference = strtoupper(clean_text($in['reference'] ?? '', 40));

    if (!in_array($method, PAYMENT_METHODS, true)) {
        $errors['method'] = 'Choose how the patient is paying.';
    }
    if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $amountRaw) || to_cents($amountRaw) <= 0) {
        $errors['amount'] = 'Enter the amount paid, for example 1500 or 1500.50.';
    } elseif (to_cents($amountRaw) > to_cents($inv['total']) - to_cents($inv['amount_paid'])) {
        $errors['amount'] = 'The amount is more than the balance of ' . fmt_money(invoice_balance($inv)) . '.';
    }
    if (!isset($errors['method'])) {
        if ($method === 'M-Pesa') {
            if (!preg_match('/^[A-Z0-9]{10}$/', $reference)) {
                $errors['reference'] = 'Enter the 10-character M-Pesa confirmation code, for example QJK4H2M9XP.';
            } elseif (db_value("SELECT id FROM payments WHERE kind = 'Payment' AND method = 'M-Pesa' AND reference = ?", [$reference])) {
                $errors['reference'] = 'This M-Pesa code has already been used for a payment.';
            }
        } elseif ($method === 'Card') {
            if (!preg_match('/^[A-Z0-9]{4,20}$/', $reference)) {
                $errors['reference'] = 'Enter the card approval code from the receipt slip (4 to 20 letters or numbers).';
            }
        } elseif ($method === 'Insurance') {
            if (!preg_match('/^[A-Z0-9][A-Z0-9\/-]{3,29}$/', $reference)) {
                $errors['reference'] = 'Enter the insurer\'s claim or authorisation number.';
            }
        } else {
            $reference = '';
        }
    }
    return [['method' => $method, 'amount' => $amountRaw, 'reference' => $reference], $errors];
}

/**
 * Record a payment against an invoice. The amount cannot exceed the balance.
 * Returns ['ok' => bool, 'errors' => [], 'payment_id' => n].
 */
function record_payment(int $invoiceId, array $in, int $userId): array
{
    return db_tx(function () use ($invoiceId, $in, $userId): array {
        // The invoice row is locked, so two cashiers paying the same invoice at once are handled one after the other.
        $inv = db_one('SELECT * FROM invoices WHERE id = ? FOR UPDATE', [$invoiceId]);
        if (!$inv) {
            return ['ok' => false, 'errors' => ['amount' => 'Invoice not found.'], 'payment_id' => 0];
        }
        [$c, $errors] = validate_payment_input($inv, $in);
        if ($errors) {
            return ['ok' => false, 'errors' => $errors, 'payment_id' => 0];
        }
        $amount = number_format(to_cents($c['amount']) / 100, 2, '.', '');
        $pid = db_insert(
            'INSERT INTO payments (invoice_id, amount, method, reference, received_by) VALUES (?, ?, ?, ?, ?)',
            [$invoiceId, $amount, $c['method'], $c['reference'] !== '' ? $c['reference'] : null, $userId]
        );
        $paid = number_format((to_cents($inv['amount_paid']) + to_cents($amount)) / 100, 2, '.', '');
        db_exec('UPDATE invoices SET amount_paid = ?, status = ? WHERE id = ?', [$paid, invoice_status_for($inv['total'], $paid), $invoiceId]);
        audit('payment', 'payment', $pid, fmt_money($amount) . ' by ' . $c['method'] . ' on ' . $inv['invoice_no'], $userId);
        return ['ok' => true, 'errors' => [], 'payment_id' => $pid];
    });
}

/**
 * Money taken on one day, by method, optionally for one cashier. The amounts are net of refunds paid out that day.
 * Returns ['total' => net, 'count' => payments taken, 'refunds' => refunds paid out, 'refunded' => their sum, 'methods' => [method => net]].
 */
function collections_for_day(string $date, ?int $userId = null): array
{
    $sql = 'SELECT method, kind, COALESCE(SUM(amount), 0) AS amount, COUNT(*) AS n FROM payments WHERE DATE(paid_at) = ?';
    $params = [$date];
    if ($userId !== null) {
        $sql .= ' AND received_by = ?';
        $params[] = $userId;
    }
    $out = ['total' => '0.00', 'count' => 0, 'refunds' => 0, 'refunded' => '0.00', 'methods' => array_fill_keys(PAYMENT_METHODS, '0.00')];
    $byMethod = array_fill_keys(PAYMENT_METHODS, 0);
    $cents = 0;
    $refundedCents = 0;
    foreach (db_all($sql . ' GROUP BY method, kind', $params) as $r) {
        $byMethod[$r['method']] += to_cents($r['amount']);
        $cents += to_cents($r['amount']);
        if ($r['kind'] === 'Refund') {
            $out['refunds'] += (int) $r['n'];
            $refundedCents -= to_cents($r['amount']);
        } else {
            $out['count'] += (int) $r['n'];
        }
    }
    foreach ($byMethod as $m => $c) {
        $out['methods'][$m] = number_format($c / 100, 2, '.', '');
    }
    $out['total'] = number_format($cents / 100, 2, '.', '');
    $out['refunded'] = number_format($refundedCents / 100, 2, '.', '');
    return $out;
}

/* ---------- Dashboard ---------- */

function dashboard_tiles_billing(array $user): array
{
    $t = [];
    if (user_can('billing.pay')) {
        $mine = collections_for_day(app_today(), (int) $user['id']);
        $t[] = dashboard_tile('Invoices waiting for payment', db_value("SELECT COUNT(*) FROM invoices WHERE status <> 'Paid'"), 'billing/index.php');
        $t[] = dashboard_tile('Collected by me today', fmt_money($mine['total']), 'billing/index.php');
    }
    if (user_can('reports.revenue')) {
        $all = collections_for_day(app_today());
        $t[] = dashboard_tile('Collected today', fmt_money($all['total']), 'reports/revenue.php');
        $t[] = dashboard_tile('Unpaid balances', fmt_money(db_value("SELECT COALESCE(SUM(total - amount_paid), 0) FROM invoices WHERE status <> 'Paid'")), 'reports/revenue.php', 'warn');
    }
    return $t;
}
