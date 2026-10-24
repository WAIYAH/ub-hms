<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('billing.view');

$inv = null;
if (get_int('id') !== null) {
    $inv = get_invoice(get_int('id'));
} elseif (get_int('visit') !== null) {
    $inv = get_invoice_for_visit(get_int('visit'));
}
if (!$inv) {
    abort(404, 'There is no invoice for that visit yet. An invoice appears as soon as a consultation, test, procedure or medicine is recorded.');
}
audit('view', 'invoice', (int) $inv['id'], 'Opened ' . $inv['invoice_no']);

$form = take_form();
$items = invoice_items((int) $inv['id']);
$payments = invoice_payments((int) $inv['id']);
$balance = invoice_balance($inv);
$f = ['values' => $form['values'] ?: ['method' => 'Cash', 'amount' => $balance], 'errors' => $form['errors']];
$canPay = user_can('billing.pay') && to_cents($balance) > 0;

page_start('Invoice ' . $inv['invoice_no'], 'billing', ['no_title' => true]);
?>
<div class="invoice">
    <div class="invoice-head">
        <div>
            <h2><?= e(config('hospital')) ?></h2>
            <div class="muted"><?= e(config('location')) ?></div>
        </div>
        <div class="right">
            <h2>Invoice <?= e($inv['invoice_no']) ?></h2>
            <div><?= e(fmt_date($inv['created_at'])) ?></div>
            <div><?= badge($inv['status']) ?></div>
        </div>
    </div>
    <p><strong><?= e(patient_full_name($inv)) ?></strong> &middot; <span class="op-number"><?= e($inv['op_number']) ?></span><br>
       <span class="muted">Visit of <?= e(fmt_date($inv['visit_date'])) ?></span></p>

    <table class="data">
        <thead><tr><th>Description</th><th>Type</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
            <tr><td><?= e($it['description']) ?></td><td><?= e($it['source']) ?></td><td class="num"><?= (int) $it['quantity'] ?></td><td class="num"><?= e(fmt_money($it['unit_price'])) ?></td><td class="num"><?= e(fmt_money($it['line_total'])) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$items): ?><tr><td colspan="5" class="empty">No charges yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <div class="totals mt">
        <div><span>Total</span><span><?= e(fmt_money($inv['total'])) ?></span></div>
        <div><span>Paid</span><span><?= e(fmt_money($inv['amount_paid'])) ?></span></div>
        <div class="grand"><span>Balance due</span><span><?= e(fmt_money($balance)) ?></span></div>
    </div>

    <?php if ($payments): ?>
    <h3>Payments received</h3>
    <table class="data">
        <thead><tr><th>Receipt</th><th>Date</th><th>Method</th><th>Reference</th><th class="num">Amount</th><th>Received by</th><th class="no-print"></th></tr></thead>
        <tbody>
        <?php foreach ($payments as $p): ?>
            <tr><td><?= e(receipt_number($p)) ?></td><td class="nowrap"><?= e(fmt_datetime($p['paid_at'])) ?></td><td><?= e($p['method']) ?></td><td><?= e($p['reference']) ?></td>
                <td class="num"><?= e(fmt_money($p['amount'])) ?></td><td><?= e($p['received_by_name']) ?></td>
                <td class="no-print"><a class="btn btn-small btn-secondary" href="<?= e(url('billing/receipt.php', ['id' => $p['id']])) ?>">Receipt</a></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<div class="actions no-print">
    <button class="btn btn-secondary" type="button" data-print>Print invoice</button>
    <a class="btn btn-secondary" href="<?= e(url('billing/index.php')) ?>">Back to invoices</a>
</div>

<?php if ($canPay): ?>
<div class="card mt no-print" id="pay">
    <h2>Take a payment</h2>
    <?= error_summary($f['errors']) ?>
    <form method="post" action="<?= e(url('billing/pay.php')) ?>" novalidate>
        <?= csrf_field() ?><input type="hidden" name="invoice_id" value="<?= (int) $inv['id'] ?>">
        <div class="form-grid">
            <?= select_field($f, 'method', 'Payment method', array_combine(PAYMENT_METHODS, PAYMENT_METHODS), ['required' => true]) ?>
            <?= field($f, 'amount', 'Amount (KSh)', ['required' => true, 'inputmode' => 'decimal', 'hint' => 'Balance due: ' . fmt_money($balance) . '. A smaller amount is recorded as a part-payment.']) ?>
            <div id="reference-box" class="wide">
                <?= field($f, 'reference', 'Reference', ['maxlength' => 40, 'autocomplete' => 'off', 'hint' => 'M-Pesa: the 10-character confirmation code. Card: the approval code on the slip. Insurance: the claim or authorisation number. Not needed for cash.']) ?>
            </div>
        </div>
        <div class="actions"><button class="btn" type="submit">Record payment</button></div>
    </form>
</div>
<?php elseif ($inv['status'] === 'Paid'): ?>
    <div class="flash flash-success no-print">This invoice is fully paid.</div>
<?php endif; ?>
<?php page_end();
