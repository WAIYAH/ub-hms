<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('billing.view');

$pay = get_payment((int) get_int('id'));
if (!$pay) {
    abort(404, 'That receipt does not exist.');
}
audit('view', 'payment', (int) $pay['id'], 'Opened receipt');
$balance = invoice_balance($pay);

page_start('Receipt', 'billing', ['no_title' => true]);
?>
<div class="invoice">
    <div class="invoice-head">
        <div><h2><?= e(config('hospital')) ?></h2><div class="muted"><?= e(config('location')) ?></div></div>
        <div class="right"><h2>Receipt <?= e(receipt_number($pay)) ?></h2><div><?= e(fmt_datetime($pay['paid_at'])) ?></div></div>
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
