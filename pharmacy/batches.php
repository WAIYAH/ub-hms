<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('pharmacy.stock');

if (is_post()) {
    require_permission('pharmacy.manage');
    require_post();
    $change = (int) post_int('amount') * (post_str('direction', 6) === 'remove' ? -1 : 1);
    $err = adjust_batch((int) post_int('batch_id'), $change, post_str('reason', 40), (int) $user['id']);
    flash($err ? 'error' : 'success', $err ?? 'Stock adjusted and recorded.');
    redirect('pharmacy/batches.php', array_filter(['medicine_id' => post_int('medicine_id'), 'all' => post_str('all', 1)]));
}

$medicineId = get_int('medicine_id');
$showAll = get_str('all', 1) === '1';
$batches = list_batches($medicineId, !$showAll);
$medicine = $medicineId ? get_medicine($medicineId) : null;

page_start($medicine ? 'Batches: ' . medicine_label($medicine) : 'Batches and expiry dates', 'batches');
?>
<p>
    <a class="btn btn-secondary" href="<?= e(url('pharmacy/batches.php', array_filter(['medicine_id' => $medicineId, 'all' => $showAll ? '' : '1']))) ?>"><?= $showAll ? 'Hide empty batches' : 'Show empty batches too' ?></a>
    <?php if (user_can('pharmacy.manage')): ?><a class="btn" href="<?= e(url('pharmacy/receive.php', array_filter(['medicine_id' => $medicineId]))) ?>">Receive stock</a><?php endif; ?>
</p>
<div class="table-wrap">
<table class="data">
    <thead><tr><th>Medicine</th><th>Batch</th><th>Expiry</th><th>State</th><th class="num">On shelf</th><th>Received</th><?php if (user_can('pharmacy.manage')): ?><th>Adjust</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($batches as $b): ?>
        <tr>
            <td><?= e(medicine_label($b)) ?></td>
            <td><?= e($b['batch_no']) ?></td>
            <td class="nowrap"><?= e(fmt_date($b['expiry_date'])) ?></td>
            <td><?= badge($b['state']) ?></td>
            <td class="num"><?= (int) $b['quantity'] ?></td>
            <td class="nowrap"><?= e(fmt_date($b['received_at'])) ?></td>
            <?php if (user_can('pharmacy.manage')): ?>
            <td>
                <form method="post" class="filters">
                    <?= csrf_field() ?>
                    <input type="hidden" name="batch_id" value="<?= (int) $b['id'] ?>"><input type="hidden" name="medicine_id" value="<?= (int) ($medicineId ?? 0) ?>"><input type="hidden" name="all" value="<?= $showAll ? '1' : '' ?>">
                    <select name="direction" aria-label="Add or remove"><option value="remove">Remove</option><option value="add">Add</option></select>
                    <input class="qty-input" name="amount" type="number" min="1" placeholder="Qty" aria-label="Amount">
                    <select name="reason" aria-label="Reason"><?php foreach (ADJUST_REASONS as $r): ?><option><?= e($r) ?></option><?php endforeach; ?></select>
                    <button class="btn btn-small btn-secondary" type="submit">Save</button>
                </form>
            </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    <?php if (!$batches): ?><tr><td colspan="7" class="empty">No batches to show.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?php if ($medicine): ?>
<h2>Stock movements</h2>
<div class="table-wrap">
<table class="data">
    <thead><tr><th>When</th><th>Type</th><th>Batch</th><th class="num">Change</th><th>Reference</th><th>By</th></tr></thead>
    <tbody>
    <?php foreach (stock_movements_for((int) $medicine['id']) as $s): ?>
        <tr><td class="nowrap"><?= e(fmt_datetime($s['created_at'])) ?></td><td><?= e($s['type']) ?></td><td><?= e($s['batch_no']) ?></td><td class="num"><?= ((int) $s['quantity'] > 0 ? '+' : '') . (int) $s['quantity'] ?></td><td><?= e($s['reference']) ?></td><td><?= e($s['full_name']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php page_end();
