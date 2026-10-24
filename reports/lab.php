<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('reports.lab');

[$from, $to] = report_range($_GET);
$r = report_lab($from, $to);

if (get_str('format', 3) === 'csv') {
    audit('export', 'report', null, "Laboratory report $from to $to");
    $rows = array_map(static fn ($t) => [$t['name'], $t['requested'], $t['completed'], $t['pending'], $t['cancelled'], $t['abnormal'], $t['avg_minutes'] ?? ''], $r['tests']);
    $t = $r['totals'];
    $rows[] = ['Total', $t['requested'], $t['completed'], $t['pending'], $t['cancelled'], $t['abnormal'], $r['avg_minutes'] ?? ''];
    send_csv("laboratory_{$from}_to_{$to}.csv", ['Test', 'Requested', 'Completed', 'Pending', 'Cancelled', 'Outside usual range', 'Average minutes to result'], $rows);
}
audit('view', 'report', null, "Laboratory report $from to $to");

function minutes_text(?int $m): string
{
    if ($m === null) {
        return '';
    }
    return $m >= 60 ? intdiv($m, 60) . ' h ' . ($m % 60) . ' min' : $m . ' min';
}

page_start('Laboratory workload report', 'r-lab');
?>
<?= render_report_filters($from, $to, 'reports/lab.php') ?>
<p class="muted">Tests requested between <?= e(fmt_date($from)) ?> and <?= e(fmt_date($to)) ?>. Time to result is measured from the request to the result being entered.</p>
<div class="grid">
    <?= dashboard_tile('Requested', $r['totals']['requested']) ?>
    <?= dashboard_tile('Completed', $r['totals']['completed']) ?>
    <?= dashboard_tile('Still pending', $r['totals']['pending'], '', $r['totals']['pending'] ? 'warn' : '') ?>
    <?= dashboard_tile('Average time to result', minutes_text($r['avg_minutes']) ?: 'n/a') ?>
</div>
<div class="table-wrap">
<table class="data">
    <thead><tr><th>Test</th><th class="num">Requested</th><th class="num">Completed</th><th class="num">Pending</th><th class="num">Cancelled</th><th class="num">Outside usual range</th><th class="num">Average time</th></tr></thead>
    <tbody>
    <?php foreach ($r['tests'] as $t): ?>
        <tr><td><?= e($t['name']) ?></td><td class="num"><?= $t['requested'] ?></td><td class="num"><?= $t['completed'] ?></td><td class="num"><?= $t['pending'] ?></td><td class="num"><?= $t['cancelled'] ?></td><td class="num"><?= $t['abnormal'] ?></td><td class="num"><?= e(minutes_text($t['avg_minutes'])) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$r['tests']): ?><tr><td colspan="7" class="empty">No tests were requested in this period.</td></tr><?php endif; ?>
    </tbody>
    <tfoot><tr><td>Total</td><td class="num"><?= $r['totals']['requested'] ?></td><td class="num"><?= $r['totals']['completed'] ?></td><td class="num"><?= $r['totals']['pending'] ?></td><td class="num"><?= $r['totals']['cancelled'] ?></td><td class="num"><?= $r['totals']['abnormal'] ?></td><td class="num"><?= e(minutes_text($r['avg_minutes'])) ?></td></tr></tfoot>
</table>
</div>
<h2>Requests by doctor</h2>
<div class="table-wrap">
<table class="data">
    <thead><tr><th>Doctor</th><th class="num">Requested</th><th class="num">Completed</th></tr></thead>
    <tbody>
    <?php foreach ($r['doctors'] as $d): ?><tr><td><?= e($d['full_name']) ?></td><td class="num"><?= (int) $d['requested'] ?></td><td class="num"><?= (int) $d['completed'] ?></td></tr><?php endforeach; ?>
    <?php if (!$r['doctors']): ?><tr><td colspan="3" class="empty">No requests.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?php page_end();
