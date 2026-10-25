<?php
/**
 * Refunds.
 *
 * Money already taken is never edited or deleted. A refund is a NEW row in the payments ledger with kind 'Refund' and a
 * negative amount, pointing at the payment it reverses (refund_of), so the cash-up, the invoice balance and the revenue
 * report all stay in step. Two people are involved: the cashier asks (with a reason) and the Hospital Manager decides.
 * Nobody can decide a request they made themselves, and the database enforces that as well.
 *
 * The refund row is written when the manager approves. Its "received_by" is the cashier who asked, because that is
 * the till the money leaves; the approver is on the request (decided_by) and in the audit trail.
 */
declare(strict_types=1);

const REFUND_STATUSES = ['Pending', 'Approved', 'Rejected', 'Cancelled'];

/** [already refunded, reserved by requests still waiting], both in cents, for one payment. */
function payment_refund_cents(int $paymentId): array
{
    $approved = -to_cents(db_value("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE kind = 'Refund' AND refund_of = ?", [$paymentId]));
    $pending = to_cents(db_value("SELECT COALESCE(SUM(amount), 0) FROM refund_requests WHERE payment_id = ? AND status = 'Pending'", [$paymentId]));
    return [$approved, $pending];
}

/** How much of a payment can still be asked back, as a decimal string. */
function payment_refundable(int $paymentId): string
{
    $p = db_one("SELECT amount FROM payments WHERE id = ? AND kind = 'Payment'", [$paymentId]);
    if (!$p) {
        return '0.00';
    }
    [$approved, $pending] = payment_refund_cents($paymentId);
    return number_format(max(0, to_cents($p['amount']) - $approved - $pending) / 100, 2, '.', '');
}

/** Returns [clean, errors] for the refund request form; $availableCents is what can still be refunded on the payment. */
function validate_refund_input(array $in, int $availableCents): array
{
    $errors = [];
    $amountRaw = str_replace(',', '', clean_text($in['amount'] ?? '', 14));
    $reason = clean_text($in['reason'] ?? '', 255);
    $reference = strtoupper(clean_text($in['refund_reference'] ?? '', 40));

    if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $amountRaw) || to_cents($amountRaw) <= 0) {
        $errors['amount'] = 'Enter the amount to give back, for example 500 or 500.50.';
    } elseif (to_cents($amountRaw) > $availableCents) {
        $errors['amount'] = $availableCents > 0
            ? 'The most that can still be refunded on this payment is ' . fmt_money(number_format($availableCents / 100, 2, '.', '')) . '.'
            : 'Nothing is left to refund on this payment.';
    }
    if (mb_strlen($reason) < 5) {
        $errors['reason'] = 'Say why the money is being refunded (at least a few words). The manager reads this.';
    }
    if ($reference !== '' && !preg_match('/^[A-Z0-9][A-Z0-9\/-]{0,39}$/', $reference)) {
        $errors['refund_reference'] = 'Use letters, numbers, slash or hyphen only, or leave this blank.';
    }
    return [['amount' => $amountRaw, 'reason' => $reason, 'refund_reference' => $reference], $errors];
}

/**
 * The cashier asks for a refund. Returns ['ok' => true, 'id' => n, 'reference' => 'RF-...'] or ['ok' => false, 'errors' => [...]].
 */
function request_refund(int $paymentId, array $in, int $userId): array
{
    return db_tx(function () use ($paymentId, $in, $userId): array {
        // Lock order (invoice, then payment) matches decide_refund(), so two people working at once wait for each other.
        $invoiceId = (int) db_value("SELECT invoice_id FROM payments WHERE id = ? AND kind = 'Payment'", [$paymentId]);
        $inv = $invoiceId ? db_one('SELECT * FROM invoices WHERE id = ? FOR UPDATE', [$invoiceId]) : null;
        $pay = $inv ? db_one("SELECT * FROM payments WHERE id = ? AND kind = 'Payment' FOR UPDATE", [$paymentId]) : null;
        if (!$pay) {
            return ['ok' => false, 'errors' => ['amount' => 'That payment does not exist.']];
        }
        [$approved, $pending] = payment_refund_cents($paymentId);
        [$c, $errors] = validate_refund_input($in, to_cents($pay['amount']) - $approved - $pending);
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }
        $amount = number_format(to_cents($c['amount']) / 100, 2, '.', '');
        $id = db_insert(
            'INSERT INTO refund_requests (payment_id, invoice_id, amount, reason, refund_reference, requested_by) VALUES (?, ?, ?, ?, ?, ?)',
            [$paymentId, $invoiceId, $amount, $c['reason'], $c['refund_reference'] !== '' ? $c['refund_reference'] : null, $userId]
        );
        $reference = sprintf('RF-%s-%06d', app_now()->format('Y'), $id);
        db_exec('UPDATE refund_requests SET reference = ? WHERE id = ?', [$reference, $id]);
        // The audit trail holds the invoice number and amount, not the reason or the patient.
        audit('refund_request', 'refund', $id, "$reference: " . fmt_money($amount) . ' on ' . $inv['invoice_no'], $userId);
        return ['ok' => true, 'id' => $id, 'reference' => $reference];
    });
}

/**
 * The manager approves or rejects. $decision is 'approve' or 'reject'; a rejection needs a note.
 * Returns ['ok' => true] or ['ok' => false, 'error' => text].
 */
function decide_refund(int $requestId, string $decision, string $note, int $userId): array
{
    $note = clean_text($note, 255);
    if (!in_array($decision, ['approve', 'reject'], true)) {
        return ['ok' => false, 'error' => 'Choose approve or reject.'];
    }
    if ($decision === 'reject' && $note === '') {
        return ['ok' => false, 'error' => 'Say why the refund is rejected, so the cashier can explain it.'];
    }
    return db_tx(function () use ($requestId, $decision, $note, $userId): array {
        $rr = db_one('SELECT * FROM refund_requests WHERE id = ? FOR UPDATE', [$requestId]);
        if (!$rr) {
            return ['ok' => false, 'error' => 'That refund request does not exist.'];
        }
        if ($rr['status'] !== 'Pending') {
            return ['ok' => false, 'error' => 'This request has already been ' . strtolower($rr['status']) . '.'];
        }
        if ((int) $rr['requested_by'] === $userId) {
            return ['ok' => false, 'error' => 'You cannot decide a refund you asked for. Another person must approve it.'];
        }
        if ($decision === 'reject') {
            db_exec("UPDATE refund_requests SET status = 'Rejected', decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ?", [$userId, $note, $requestId]);
            audit('refund_reject', 'refund', $requestId, $rr['reference'] . ' rejected', $userId);
            return ['ok' => true];
        }

        $inv = db_one('SELECT * FROM invoices WHERE id = ? FOR UPDATE', [$rr['invoice_id']]);
        $pay = db_one('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$rr['payment_id']]);
        [$approved] = payment_refund_cents((int) $pay['id']);
        $cents = to_cents($rr['amount']);
        if (to_cents($pay['amount']) - $approved < $cents || to_cents($inv['amount_paid']) < $cents) {
            return ['ok' => false, 'error' => 'This payment has already been refunded, so the request cannot be approved. Reject it instead.'];
        }
        $amount = number_format($cents / 100, 2, '.', '');
        $refundId = db_insert(
            "INSERT INTO payments (invoice_id, kind, refund_of, amount, method, reference, received_by) VALUES (?, 'Refund', ?, ?, ?, ?, ?)",
            [$rr['invoice_id'], $pay['id'], '-' . $amount, $pay['method'], $rr['refund_reference'], (int) $rr['requested_by']]
        );
        $paid = number_format((to_cents($inv['amount_paid']) - $cents) / 100, 2, '.', '');
        db_exec('UPDATE invoices SET amount_paid = ?, status = ? WHERE id = ?', [$paid, invoice_status_for($inv['total'], $paid), $inv['id']]);
        db_exec(
            "UPDATE refund_requests SET status = 'Approved', decided_by = ?, decided_at = NOW(), decision_note = ?, refund_payment_id = ? WHERE id = ?",
            [$userId, $note !== '' ? $note : null, $refundId, $requestId]
        );
        audit('refund_approve', 'refund', $requestId, $rr['reference'] . ': ' . fmt_money($amount) . ' on ' . $inv['invoice_no'], $userId);
        audit('refund', 'payment', $refundId, fmt_money($amount) . ' refunded by ' . $pay['method'] . ' on ' . $inv['invoice_no'], $userId);
        return ['ok' => true, 'payment_id' => $refundId];
    });
}

/** The cashier withdraws their own request while it is still waiting. Returns an error message, or null on success. */
function cancel_refund_request(int $requestId, int $userId): ?string
{
    return db_tx(function () use ($requestId, $userId): ?string {
        $rr = db_one('SELECT * FROM refund_requests WHERE id = ? FOR UPDATE', [$requestId]);
        if (!$rr) {
            return 'That refund request does not exist.';
        }
        if ((int) $rr['requested_by'] !== $userId) {
            return 'Only the person who asked for the refund can withdraw it.';
        }
        if ($rr['status'] !== 'Pending') {
            return 'This request has already been ' . strtolower($rr['status']) . '.';
        }
        db_exec("UPDATE refund_requests SET status = 'Cancelled', decided_by = ?, decided_at = NOW(), decision_note = 'Withdrawn by the cashier' WHERE id = ?", [$userId, $requestId]);
        audit('refund_cancel', 'refund', $requestId, $rr['reference'] . ' withdrawn', $userId);
        return null;
    });
}

/** One request with the payment, invoice and people around it. */
function get_refund_request(int $id): ?array
{
    return db_one(
        'SELECT rr.*, i.invoice_no, i.total AS invoice_total, i.amount_paid AS invoice_paid, i.status AS invoice_status, i.visit_id,
                v.patient_id, p.op_number, p.first_name, p.middle_name, p.last_name,
                pay.amount AS payment_amount, pay.method AS payment_method, pay.reference AS payment_reference, pay.paid_at AS payment_paid_at,
                rq.full_name AS requested_by_name, dc.full_name AS decided_by_name
           FROM refund_requests rr JOIN invoices i ON i.id = rr.invoice_id JOIN visits v ON v.id = i.visit_id JOIN patients p ON p.id = v.patient_id
           JOIN payments pay ON pay.id = rr.payment_id JOIN users rq ON rq.id = rr.requested_by LEFT JOIN users dc ON dc.id = rr.decided_by
          WHERE rr.id = ?',
        [$id]
    );
}

/**
 * Refund requests, newest first. Filters: status, q (reference or invoice number). The rows carry the invoice number and
 * outpatient number only, never a name, because the manager who decides does not need to know who the patient is.
 */
function list_refund_requests(array $f = [], int $limit = 100): array
{
    $where = ' WHERE 1 = 1';
    $params = [];
    if (!empty($f['status']) && in_array($f['status'], REFUND_STATUSES, true)) {
        $where .= ' AND rr.status = ?';
        $params[] = $f['status'];
    }
    if (!empty($f['q'])) {
        $where .= ' AND (rr.reference LIKE ? OR i.invoice_no LIKE ?)';
        $like = like_escape(strtoupper(trim((string) $f['q']))) . '%';
        array_push($params, $like, $like);
    }
    if (!empty($f['requested_by'])) {
        $where .= ' AND rr.requested_by = ?';
        $params[] = (int) $f['requested_by'];
    }
    return db_all(
        'SELECT rr.id, rr.reference, rr.amount, rr.reason, rr.status, rr.requested_at, rr.decided_at, rr.invoice_id, i.invoice_no,
                pay.method AS payment_method, rq.full_name AS requested_by_name, dc.full_name AS decided_by_name
           FROM refund_requests rr JOIN invoices i ON i.id = rr.invoice_id JOIN payments pay ON pay.id = rr.payment_id
           JOIN users rq ON rq.id = rr.requested_by LEFT JOIN users dc ON dc.id = rr.decided_by' . $where
        . ' ORDER BY (rr.status = \'Pending\') DESC, rr.id DESC LIMIT ' . (int) $limit,
        $params
    );
}

/** The refund requests on one invoice, oldest first (for the invoice page). */
function invoice_refund_requests(int $invoiceId): array
{
    return db_all(
        'SELECT rr.*, rq.full_name AS requested_by_name, dc.full_name AS decided_by_name
           FROM refund_requests rr JOIN users rq ON rq.id = rr.requested_by LEFT JOIN users dc ON dc.id = rr.decided_by
          WHERE rr.invoice_id = ? ORDER BY rr.id',
        [$invoiceId]
    );
}
