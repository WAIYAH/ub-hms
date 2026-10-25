<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('reports.revenue');

[$from, $to] = report_range($_GET);
$r = report_revenue($from, $to);

if (get_str('format', 3) === 'csv') {
    audit('export', 'report', null, "Revenue report $from to $to");
    $rows = array_map(static fn ($d) => [$d['date'], $d['billed'], $d['collected'], $d['payments']], $r['days']);
    $rows[] = ['Total', $r['billed'], $r['collected'], array_sum(array_column($r['methods'], 'count'))];
    foreach ($r['methods'] as $m => $v) {
        $rows[] = ["Collected by $m", '', $v['amount'], $v['count']];
    }
    foreach ($r['categories'] as $c => $amount) {
        $rows[] = ["Billed for $c", $amount, '', ''];
    }
    send_csv("revenue_{$from}_to_{$to}.csv", ['Date', 'Billed (KSh)', 'Collected (KSh)', 'Payments'], $rows);
}
audit('view', 'report', null, "Revenue report $from to $to");

$maxCat = max(1, ...array_map('floatval', array_values($r['categories'])));
page_start('Revenue report', 'r-revenue');
?>
<?= render_report_filters($from, $to, 'reports/revenue.php') ?>
<p class="muted">Period: <?= e(fmt_date($from)) ?> to <?= e(fmt_date($to)) ?>. "Billed" counts invoices created in the period; "collected" counts payments received in the period.</p>
<div class="grid">
    <?= dashboard_tile('Billed', fmt_money($r['billed'])) ?>
    <?= dashboard_tile('Collected', fmt_money($r['collected'])) ?>
    <?= dashboard_tile('Unpaid on these invoices', fmt_money($r['outstanding']), '', to_cents($r['outstanding']) > 0 ? 'warn' : '') ?>
    <?= dashboard_tile('Invoices', $r['invoices']) ?>
</div>

<div class="cols">
    <div class="card">
        <h2>Collected by payment method</h2>
        <div class="table-wrap" tabindex="0" role="group" aria-label="Table: scroll sideways if it does not fit"><table class="data"><thead><tr><th>Method</th><th class="num">Payments</th><th class="num">Amount</th></tr></thead><tbody>
        <?php foreach ($r['methods'] as $method => $v): ?><tr><td><?= e($method) ?></td><td class="num"><?= (int) $v['count'] ?></td><td class="num"><?= e(fmt_money($v['amount'])) ?></td></tr><?php endforeach; ?>
        </tbody><tfoot><tr><td>Total</td><td class="num"><?= array_sum(array_column($r['methods'], 'count')) ?></td><td class="num"><?= e(fmt_money($r['collected'])) ?></td></tr></tfoot></table></div>
    </div>
    <div class="card">
        <h2>Billed by type of service</h2>
        <?php foreach ($r['categories'] as $cat => $amount): ?>
            <div class="bar-row"><span><?= e($cat) ?></span><meter min="0" max="<?= e($maxCat) ?>" value="<?= e((float) $amount) ?>" aria-label="<?= e($cat) ?>"></meter><span class="right"><?= e(fmt_money($amount)) ?></span></div>
        <?php endforeach; ?>
    </div>
</div>

<h2>Day by day</h2>
<div class="table-wrap" tabindex="0" role="group" aria-label="Table: scroll sideways if it does not fit">
<table class="data">
    <thead><tr><th>Date</th><th class="num">Billed</th><th class="num">Collected</th><th class="num">Payments</th></tr></thead>
    <tbody>
    <?php foreach ($r['days'] as $d): ?>
        <?php if (to_cents($d['billed']) === 0 && to_cents($d['collected']) === 0) { continue; } ?>
        <tr><td class="nowrap"><?= e(fmt_date($d['date'])) ?></td><td class="num"><?= e(fmt_money($d['billed'])) ?></td><td class="num"><?= e(fmt_money($d['collected'])) ?></td><td class="num"><?= (int) $d['payments'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php page_end();
