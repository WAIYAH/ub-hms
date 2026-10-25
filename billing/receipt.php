<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('billing.view');

$pay = get_payment((int) get_int('id'));
if (!$pay) {
    abort(404, 'That receipt does not exist.');
}
audit('view', 'payment', (int) $pay['id'], 'Opened receipt');
$balance = invoice_balance($pay);
$isRefund = $pay['kind'] === 'Refund';
$request = $isRefund ? db_one(
    'SELECT rr.reference, rr.reason, rr.decided_at, dc.full_name AS decided_by_name FROM refund_requests rr LEFT JOIN users dc ON dc.id = rr.decided_by WHERE rr.refund_payment_id = ?',
    [(int) $pay['id']]
) : null;

page_start($isRefund ? 'Refund voucher' : 'Receipt', 'billing', ['no_title' => true]);
?>
<?php if ($isRefund): ?>
<div class="invoice">
    <div class="invoice-head">
        <div><h2><?= e(config('hospital')) ?></h2><div class="muted"><?= e(config('location')) ?></div></div>
        <div class="right"><h1>Refund voucher <?= e(receipt_number($pay)) ?></h1><div><?= e(fmt_datetime($pay['paid_at'])) ?></div></div>
    </div>
    <dl class="kv">
        <dt>Refunded to</dt><dd><?= e(patient_full_name($pay)) ?> (<?= e($pay['op_number']) ?>)</dd>
        <dt>For invoice</dt><dd><?= e($pay['invoice_no']) ?></dd>
        <dt>Refunded by</dt><dd><?= e($pay['method']) ?><?= $pay['reference'] ? ' &middot; ref. ' . e($pay['reference']) : '' ?></dd>
        <dt>Amount refunded</dt><dd><strong><?= e(fmt_money(number_format(-to_cents($pay['amount']) / 100, 2, '.', ''))) ?></strong></dd>
        <?php if ($request): ?>
            <dt>Refund request</dt><dd><?= e($request['reference']) ?></dd>
            <dt>Reason</dt><dd><?= e($request['reason']) ?></dd>
            <dt>Approved by</dt><dd><?= e($request['decided_by_name']) ?> on <?= e(fmt_datetime($request['decided_at'])) ?></dd>
        <?php endif; ?>
        <dt>Paid out by</dt><dd><?= e($pay['received_by_name']) ?></dd>
        <dt>Invoice total</dt><dd><?= e(fmt_money($pay['total'])) ?></dd>
        <dt>Paid after this refund</dt><dd><?= e(fmt_money($pay['amount_paid'])) ?></dd>
        <dt>Balance</dt><dd><?= e(fmt_money($balance)) ?></dd>
    </dl>
    <p class="muted small">This voucher records money given back to the patient. Keep it with the cash-up for the day.</p>
</div>
<div class="actions no-print">
    <button class="btn" type="button" data-print>Print voucher</button>
    <a class="btn btn-secondary" href="<?= e(url('billing/invoice.php', ['visit' => $pay['visit_id']])) ?>">Back to the invoice</a>
</div>
<?php page_end(); return; ?>
<?php endif; ?>
<div class="invoice">
    <div class="invoice-head">
        <div><h2><?= e(config('hospital')) ?></h2><div class="muted"><?= e(config('location')) ?></div></div>
        <div class="right"><h1>Receipt <?= e(receipt_number($pay)) ?></h1><div><?= e(fmt_datetime($pay['paid_at'])) ?></div></div>
    </div>
    <dl class="kv">
        <dt>Received from</dt><dd><?= e(patient_full_name($pay)) ?> (<?= e($pay['op_number']) ?>)</dd>
        <dt>For invoice</dt><dd><?= e($pay['invoice_no']) ?></dd>
        <dt>Payment method</dt><dd><?= e($pay['method']) ?><?= $pay['reference'] ? ' &middot; ref. ' . e($pay['reference']) : '' ?></dd>
        <dt>Amount received</dt><dd><strong><?= e(fmt_money($pay['amount'])) ?></strong></dd>
        <dt>Invoice total</dt><dd><?= e(fmt_money($pay['total'])) ?></dd>
        <dt>Total paid so far</dt><dd><?= e(fmt_money($pay['amount_paid'])) ?></dd>
        <dt>Balance</dt><dd><?= e(fmt_money($balance)) ?></dd>
        <dt>Received by</dt><dd><?= e($pay['received_by_name']) ?></dd>
    </dl>
    <p class="muted small">Thank you. Keep this receipt as proof of payment.</p>
</div>
<div class="actions no-print">
    <button class="btn" type="button" data-print>Print receipt</button>
    <a class="btn btn-secondary" href="<?= e(url('billing/invoice.php', ['visit' => $pay['visit_id']])) ?>">Back to the invoice</a>
</div>
<?php page_end();
