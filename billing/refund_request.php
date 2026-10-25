<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('refunds.request');

$paymentId = get_int('payment') ?? post_int('payment');
$pay = $paymentId !== null ? get_payment($paymentId) : null;
if (!$pay || $pay['kind'] !== 'Payment') {
    abort(404, 'That payment does not exist, or it is itself a refund.');
}
$paymentId = (int) $pay['id'];

[$approved, $pending] = payment_refund_cents($paymentId);
$refundable = payment_refundable($paymentId);

$errors = [];
$values = ['amount' => $refundable];
if (is_post()) {
    require_post();
    $values = $_POST;
    $r = request_refund($paymentId, $_POST, (int) $user['id']);
    if ($r['ok']) {
        flash('success', 'Refund ' . $r['reference'] . ' has been sent to the Hospital Manager for approval. The patient is not refunded until it is approved.');
        redirect('billing/refund.php', ['id' => $r['id']]);
    }
    $errors = $r['errors'];
}
$f = ['values' => $values, 'errors' => $errors];

page_start('Ask for a refund', 'refunds');
?>
<p><a href="<?= e(url('billing/invoice.php', ['visit' => $pay['visit_id']])) ?>">&larr; Back to invoice <?= e($pay['invoice_no']) ?></a></p>

<div class="card">
    <h2>The payment</h2>
    <dl class="kv">
        <dt>Receipt</dt><dd><?= e(receipt_number($pay)) ?> &middot; <?= e(fmt_datetime($pay['paid_at'])) ?></dd>
        <dt>Patient</dt><dd><?= e(patient_full_name($pay)) ?> (<?= e($pay['op_number']) ?>)</dd>
        <dt>Invoice</dt><dd><?= e($pay['invoice_no']) ?></dd>
        <dt>Paid by</dt><dd><?= e($pay['method']) ?><?= $pay['reference'] ? ' &middot; ref. ' . e($pay['reference']) : '' ?></dd>
        <dt>Amount paid</dt><dd><strong><?= e(fmt_money($pay['amount'])) ?></strong></dd>
        <dt>Already refunded</dt><dd><?= e(fmt_money(number_format($approved / 100, 2, '.', ''))) ?></dd>
        <dt>Waiting for approval</dt><dd><?= e(fmt_money(number_format($pending / 100, 2, '.', ''))) ?></dd>
        <dt>Can still be refunded</dt><dd><strong><?= e(fmt_money($refundable)) ?></strong></dd>
    </dl>
</div>

<?php if (to_cents($refundable) <= 0): ?>
    <div class="flash flash-warning">Nothing is left to refund on this payment.</div>
<?php else: ?>
<div class="card mt">
    <h2>Ask for the refund</h2>
    <p class="muted">The Hospital Manager decides. Nothing is given back, and the invoice does not change, until the request is approved. The manager sees the invoice number, the amount and your reason, not the patient&#39;s name, so write the reason clearly.</p>
    <?= error_summary($errors) ?>
    <?= form_open() ?>
        <input type="hidden" name="payment" value="<?= $paymentId ?>">
        <div class="form-grid">
            <?= field($f, 'amount', 'Amount to refund (KSh)', ['required' => true, 'inputmode' => 'decimal', 'hint' => 'Up to ' . fmt_money($refundable) . '. Less than the full amount is a part-refund.']) ?>
            <?= field($f, 'refund_reference', 'Reversal reference (optional)', ['maxlength' => 40, 'autocomplete' => 'off', 'hint' => 'For example the M-Pesa reversal code or the card refund slip number, if you already have one.']) ?>
            <?= field($f, 'reason', 'Why is the money being refunded?', ['type' => 'textarea', 'rows' => 3, 'maxlength' => 255, 'required' => true, 'class' => 'wide']) ?>
        </div>
        <div class="actions"><button class="btn" type="submit">Send for approval</button><a class="btn btn-secondary" href="<?= e(url('billing/invoice.php', ['visit' => $pay['visit_id']])) ?>">Cancel</a></div>
    </form>
</div>
<?php endif; ?>
<?php page_end();
