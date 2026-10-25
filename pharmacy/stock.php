<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('pharmacy.stock');

$search = get_str('q', 60);
$rows = medicine_stock($search, false);
$alerts = stock_alerts();

page_start('Medicine stock', 'stock');
?>
<div class="grid">
    <?= dashboard_tile('Out of stock', count($alerts['out']), '', count($alerts['out']) ? 'danger' : '') ?>
    <?= dashboard_tile('Low stock', count($alerts['low']), '', count($alerts['low']) ? 'warn' : '') ?>
    <?= dashboard_tile('Batches expiring soon', count($alerts['expiring']), 'pharmacy/batches.php', count($alerts['expiring']) ? 'warn' : '') ?>
    <?= dashboard_tile('Expired batches with stock', count($alerts['expired']), 'pharmacy/batches.php', count($alerts['expired']) ? 'danger' : '') ?>
</div>
<form method="get" class="filters">
    <div class="field grow"><label for="q">Search medicines</label><input id="q" name="q" type="search" value="<?= e($search) ?>"></div>
    <button class="btn btn-secondary" type="submit">Search</button>
    <?php if (user_can('pharmacy.manage')): ?><a class="btn" href="<?= e(url('pharmacy/receive.php')) ?>">Receive stock</a><?php endif; ?>
</form>
<div class="table-wrap" tabindex="0" role="group" aria-label="Table: scroll sideways if it does not fit">
<table class="data">
    <thead><tr><th>Medicine</th><th class="num">Usable stock</th><th class="num">Reorder level</th><th>Status</th><th>Nearest expiry</th><th class="num">Expired (remove)</th><th class="num">Price</th><th><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $m): ?>
        <tr>
            <td><?= e(medicine_label($m)) ?><?= !(int) $m['is_active'] ? ' <span class="badge badge-inactive">Not in use</span>' : '' ?></td>
            <td class="num"><?= (int) $m['on_hand'] ?> <span class="muted small"><?= e($m['unit']) ?></span></td>
            <td class="num"><?= (int) $m['reorder_level'] ?></td>
            <td><?= badge($m['status']) ?></td>
            <td><?= $m['next_expiry'] ? e(fmt_date($m['next_expiry'])) : '' ?></td>
            <td class="num"><?= $m['expired_qty'] > 0 ? '<span class="badge badge-expired">' . (int) $m['expired_qty'] . '</span>' : '0' ?></td>
            <td class="num"><?= e(fmt_money($m['unit_price'])) ?></td>
            <td><a class="btn btn-small btn-secondary" href="<?= e(url('pharmacy/batches.php', ['medicine_id' => $m['id']])) ?>">Batches</a></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="empty">No medicines found.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?php page_end();
