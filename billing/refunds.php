<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('refunds.view');

$status = get_str('status', 10);
$q = get_str('q', 30);
$rows = list_refund_requests(['status' => $status, 'q' => $q], 200);

$pending = (int) db_value("SELECT COUNT(*) FROM refund_requests WHERE status = 'Pending'");
$monthStart = app_now()->format('Y-m-01');
$refundedMonth = -to_cents(db_value("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE kind = 'Refund' AND paid_at >= ?", [$monthStart . ' 00:00:00']));

page_start('Refunds', 'refunds');
?>
<p class="muted">A cashier asks for a refund and gives the reason; the Hospital Manager approves or rejects it. An approved refund is recorded as a new line on the invoice&#39;s payments, so the money record is never edited.</p>

<div class="grid">
    <?= dashboard_tile('Waiting for a decision', $pending, '', $pending > 0 ? 'warn' : '', 'undo', 'amber') ?>
    <?= dashboard_tile('Refunded this month', fmt_money(number_format($refundedMonth / 100, 2, '.', '')), '', '', 'banknote', 'violet') ?>
</div>

<form method="get" class="filters">
    <div class="field grow"><label for="q">Refund reference or invoice number</label><input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="RF-2026- or INV-"></div>
    <div class="field"><label for="status">Status</label>
        <select id="status" name="status"><option value="">Any</option>
            <?php foreach (REFUND_STATUSES as $s): ?><option<?= $status === $s ? ' selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
        </select></div>
    <button class="btn btn-secondary" type="submit">Show</button>
</form>

<div class="table-wrap" tabindex="0" role="group" aria-label="Table: scroll sideways if it does not fit">
<table class="data">
    <thead><tr><th>Reference</th><th>Asked</th><th>Invoice</th><th class="num">Amount</th><th>Method</th><th>Reason</th><th>Asked by</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td class="nowrap"><?= e($r['reference']) ?></td>
            <td class="nowrap"><?= e(fmt_datetime($r['requested_at'])) ?></td>
            <td class="nowrap"><?= e($r['invoice_no']) ?></td>
            <td class="num"><?= e(fmt_money($r['amount'])) ?></td>
            <td><?= e($r['payment_method']) ?></td>
            <td><?= e($r['reason']) ?></td>
            <td><?= e($r['requested_by_name']) ?></td>
            <td><?= badge($r['status']) ?><?= $r['decided_by_name'] ? '<br><span class="muted small">' . e($r['decided_by_name']) . '</span>' : '' ?></td>
            <td class="nowrap"><a class="btn btn-small<?= $r['status'] === 'Pending' && user_can('refunds.approve') ? '' : ' btn-secondary' ?>" href="<?= e(url('billing/refund.php', ['id' => $r['id']])) ?>"><?= $r['status'] === 'Pending' && user_can('refunds.approve') ? 'Decide' : 'Open' ?></a></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="9" class="empty">No refund requests<?= $status !== '' || $q !== '' ? ' match this search' : ' yet' ?>.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?php page_end();
