<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('reports.stock');

[$from, $to] = report_range($_GET);
$r = report_stock($from, $to);

if (get_str('format', 3) === 'csv') {
    audit('export', 'report', null, "Stock report $from to $to");
    $rows = array_map(static fn ($m) => [medicine_label($m), $m['unit'], $m['on_hand'], $m['reorder_level'], $m['status'], $m['next_expiry'] ?? '', $m['expired_qty'], $m['received'], $m['dispensed'], $m['adjusted'], $m['unit_price'], $m['value']], $r['rows']);
    send_csv("stock_{$from}_to_{$to}.csv", ['Medicine', 'Unit', 'Usable stock', 'Reorder level', 'Status', 'Nearest expiry', 'Expired (to remove)', "Received $from to $to", 'Dispensed', 'Adjusted', 'Unit price', 'Stock value'], $rows);
}
audit('view', 'report', null, "Stock report $from to $to");

$a = $r['alerts'];
page_start('Stock report', 'r-stock');
?>
<?= render_report_filters($from, $to, 'reports/stock.php') ?>
<p class="muted">Stock levels are as of now. Received, dispensed and adjusted units are for <?= e(fmt_date($from)) ?> to <?= e(fmt_date($to)) ?>. Stock value is usable stock times the selling price.</p>
<div class="grid">
    <?= dashboard_tile('Medicines out of stock', count($a['out']), '', count($a['out']) ? 'danger' : '') ?>
    <?= dashboard_tile('Low stock', count($a['low']), '', count($a['low']) ? 'warn' : '') ?>
    <?= dashboard_tile('Batches expiring soon', count($a['expiring']), '', count($a['expiring']) ? 'warn' : '') ?>
    <?= dashboard_tile('Expired batches with stock', count($a['expired']), '', count($a['expired']) ? 'danger' : '') ?>
    <?= dashboard_tile('Value of usable stock', fmt_money($r['value'])) ?>
</div>
<div class="table-wrap">
<table class="data">
    <thead><tr><th>Medicine</th><th class="num">Usable</th><th class="num">Reorder at</th><th>Status</th><th>Nearest expiry</th><th class="num">Expired</th><th class="num">Received</th><th class="num">Dispensed</th><th class="num">Adjusted</th><th class="num">Value</th></tr></thead>
    <tbody>
    <?php foreach ($r['rows'] as $m): ?>
        <tr><td><?= e(medicine_label($m)) ?></td><td class="num"><?= $m['on_hand'] ?></td><td class="num"><?= (int) $m['reorder_level'] ?></td><td><?= badge($m['status']) ?></td>
            <td class="nowrap"><?= $m['next_expiry'] ? e(fmt_date($m['next_expiry'])) : '' ?></td><td class="num"><?= $m['expired_qty'] ?></td>
            <td class="num"><?= $m['received'] ?></td><td class="num"><?= $m['dispensed'] ?></td><td class="num"><?= ($m['adjusted'] > 0 ? '+' : '') . $m['adjusted'] ?></td><td class="num"><?= e(fmt_money($m['value'])) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$r['rows']): ?><tr><td colspan="10" class="empty">No medicines on the list.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?php page_end();
