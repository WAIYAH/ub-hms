<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('system.health');

audit('view', 'system_health', null, 'Opened system health');
$checks = system_health();
$counts = table_counts();
$worst = 'ok';
foreach ($checks as $c) {
    if ($c['status'] === 'fail') {
        $worst = 'fail';
    } elseif ($c['status'] === 'warn' && $worst !== 'fail') {
        $worst = 'warn';
    }
}
$labels = ['ok' => 'OK', 'warn' => 'Attention', 'fail' => 'Problem'];
$badge = ['ok' => 'Ok', 'warn' => 'Low', 'fail' => 'Out'];

page_start('System health', 'health');
?>
<div class="flash flash-<?= $worst === 'ok' ? 'success' : ($worst === 'warn' ? 'warning' : 'error') ?>" role="status">
    Overall: <strong><?= e($labels[$worst]) ?></strong>
</div>
<div class="table-wrap" tabindex="0" role="group" aria-label="Table: scroll sideways if it does not fit">
<table class="data">
    <thead><tr><th>Check</th><th>Result</th><th>Detail</th></tr></thead>
    <tbody>
    <?php foreach ($checks as $c): ?>
        <tr><td><?= e($c['name']) ?></td><td><span class="badge badge-<?= $c['status'] === 'ok' ? 'ok' : ($c['status'] === 'warn' ? 'low' : 'out') ?>"><?= e($labels[$c['status']]) ?></span></td><td><?= e($c['detail']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<h2>Records held</h2>
<div class="table-wrap" tabindex="0" role="group" aria-label="Table: scroll sideways if it does not fit">
<table class="data">
    <thead><tr><th>Table</th><th class="num">Rows</th></tr></thead>
    <tbody><?php foreach ($counts as $t => $n): ?><tr><td><?= e($t) ?></td><td class="num"><?= number_format($n) ?></td></tr><?php endforeach; ?></tbody>
</table>
</div>
<p class="muted">Backups are made by <code>scripts/backup.ps1</code> and should run every day (see the user manual, section 8.5).</p>
<?php page_end();
