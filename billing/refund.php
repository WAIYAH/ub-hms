<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('refunds.view');

$id = get_int('id') ?? post_int('id');
$rr = $id !== null ? get_refund_request($id) : null;
if (!$rr) {
    abort(404, 'That refund request does not exist.');
}
$id = (int) $rr['id'];

if (is_post()) {
    require_post();
    $action = post_str('action', 10);
    if ($action === 'withdraw' && user_can('refunds.request')) {
        $error = cancel_refund_request($id, (int) $user['id']);
        flash($error ? 'error' : 'success', $error ?? 'The request was withdrawn.');
    } elseif (in_array($action, ['approve', 'reject'], true) && user_can('refunds.approve')) {
        $r = decide_refund($id, $action, post_str('note', 255), (int) $user['id']);
        flash($r['ok'] ? 'success' : 'error', $r['ok'] ? ($action === 'approve' ? 'Refund approved and recorded. The cashier can now give the money back.' : 'Refund rejected.') : $r['error']);
    } else {
        audit('access_denied', 'refund', $id, 'Refused a refund action', (int) $user['id']);
        flash('error', 'You are not allowed to do that.');
    }
    redirect('billing/refund.php', ['id' => $id]);
}

// Only people who may open invoices see who the patient is. The manager decides without it.
$showPatient = user_can('billing.view');
$mine = (int) $rr['requested_by'] === (int) $user['id'];
$canDecide = $rr['status'] === 'Pending' && user_can('refunds.approve') && !$mine;
$canWithdraw = $rr['status'] === 'Pending' && user_can('refunds.request') && $mine;
audit('view', 'refund', $id, 'Opened ' . $rr['reference']);

page_start('Refund ' . $rr['reference'], 'refunds');
?>
<p><a href="<?= e(url('billing/refunds.php')) ?>">&larr; All refunds</a></p>

<div class="cols">
    <div class="card">
        <h2>The request <?= badge($rr['status']) ?></h2>
        <dl class="kv">
            <dt>Reference</dt><dd><?= e($rr['reference']) ?></dd>
            <dt>Amount to refund</dt><dd><strong><?= e(fmt_money($rr['amount'])) ?></strong></dd>
            <dt>Reason given</dt><dd><?= e($rr['reason']) ?></dd>
            <dt>Asked by</dt><dd><?= e($rr['requested_by_name']) ?> on <?= e(fmt_datetime($rr['requested_at'])) ?></dd>
            <?php if ($rr['refund_reference']): ?><dt>Reversal reference</dt><dd><?= e($rr['refund_reference']) ?></dd><?php endif; ?>
            <?php if ($rr['status'] !== 'Pending'): ?>
                <dt><?= $rr['status'] === 'Cancelled' ? 'Withdrawn' : 'Decided by' ?></dt><dd><?= e($rr['decided_by_name']) ?> on <?= e(fmt_datetime($rr['decided_at'])) ?></dd>
                <?php if ($rr['decision_note']): ?><dt>Note</dt><dd><?= e($rr['decision_note']) ?></dd><?php endif; ?>
            <?php endif; ?>
        </dl>
    </div>
    <div class="card">
        <h2>The payment and invoice</h2>
        <dl class="kv">
            <dt>Invoice</dt><dd><?= $showPatient ? '<a href="' . e(url('billing/invoice.php', ['visit' => $rr['visit_id']])) . '">' . e($rr['invoice_no']) . '</a>' : e($rr['invoice_no']) ?> &middot; <?= badge($rr['invoice_status']) ?></dd>
            <?php if ($showPatient): ?><dt>Patient</dt><dd><?= e(patient_full_name($rr)) ?> (<?= e($rr['op_number']) ?>)</dd><?php endif; ?>
            <dt>Invoice total</dt><dd><?= e(fmt_money($rr['invoice_total'])) ?></dd>
            <dt>Paid so far</dt><dd><?= e(fmt_money($rr['invoice_paid'])) ?></dd>
            <dt>Payment being refunded</dt><dd><?= e(fmt_money($rr['payment_amount'])) ?> by <?= e($rr['payment_method']) ?> on <?= e(fmt_datetime($rr['payment_paid_at'])) ?></dd>
            <?php if ($rr['payment_reference']): ?><dt>Payment reference</dt><dd><?= e($rr['payment_reference']) ?></dd><?php endif; ?>
            <?php if ($rr['refund_payment_id'] && $showPatient): ?><dt>Refund voucher</dt><dd><a href="<?= e(url('billing/receipt.php', ['id' => $rr['refund_payment_id']])) ?>">Open the voucher</a></dd><?php endif; ?>
        </dl>
    </div>
</div>

<?php if ($canDecide): ?>
<div class="card mt">
    <h2>Your decision</h2>
    <p class="muted">Approving writes a refund line against the payment and lowers the amount paid on the invoice by <?= e(fmt_money($rr['amount'])) ?>. That cannot be undone from the screen. A rejection needs a note, so the cashier can explain it.</p>
    <?= form_open() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="field"><label for="f_note">Note (needed to reject)</label><input id="f_note" name="note" maxlength="255" autocomplete="off"></div>
        <div class="actions">
            <button class="btn" type="submit" name="action" value="approve" data-confirm="Approve this refund of <?= e(fmt_money($rr['amount'])) ?>?">Approve refund</button>
            <button class="btn btn-danger" type="submit" name="action" value="reject">Reject</button>
        </div>
    </form>
</div>
<?php elseif ($rr['status'] === 'Pending' && user_can('refunds.approve') && $mine): ?>
    <div class="flash flash-warning">You asked for this refund yourself, so another person must decide it.</div>
<?php endif; ?>

<?php if ($canWithdraw): ?>
<div class="card mt">
    <h2>Withdraw this request</h2>
    <?= form_open() ?>
        <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="withdraw">
        <button class="btn btn-secondary" type="submit" data-confirm="Withdraw this refund request?">Withdraw request</button>
    </form>
</div>
<?php endif; ?>
<?php page_end();
