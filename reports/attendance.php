<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('reports.attendance');

mark_overdue_appointments_missed();
[$from, $to] = report_range($_GET);
$r = report_attendance($from, $to);

if (get_str('format', 3) === 'csv') {
    audit('export', 'report', null, "Attendance report $from to $to");
    $rows = array_map(static fn ($d) => [$d['date'], $d['booked'], $d['attended'], $d['missed'], $d['cancelled'], $d['walkins'], $d['visits'], $d['new_patients']], $r['days']);
    $t = $r['totals'];
    $rows[] = ['Total', $t['booked'], $t['attended'], $t['missed'], $t['cancelled'], $t['walkins'], $t['visits'], $t['new_patients']];
    send_csv("attendance_{$from}_to_{$to}.csv", ['Date', 'Still booked', 'Attended', 'Missed', 'Cancelled', 'Walk-in visits', 'Total visits', 'New patients'], $rows);
}
audit('view', 'report', null, "Attendance report $from to $to");

page_start('Attendance report', 'r-attendance');
?>
<?= render_report_filters($from, $to, 'reports/attendance.php') ?>
<p class="muted">Period: <?= e(fmt_date($from)) ?> to <?= e(fmt_date($to)) ?>. "Attendance rate" is attended appointments divided by attended plus missed.</p>
<div class="grid">
    <?= dashboard_tile('Appointments attended', $r['totals']['attended']) ?>
    <?= dashboard_tile('Missed', $r['totals']['missed'], '', $r['totals']['missed'] ? 'warn' : '') ?>
    <?= dashboard_tile('Cancelled', $r['totals']['cancelled']) ?>
    <?= dashboard_tile('Attendance rate', $r['attendance_rate'] === null ? 'n/a' : $r['attendance_rate'] . '%') ?>
    <?= dashboard_tile('Total visits', $r['totals']['visits']) ?>
    <?= dashboard_tile('New patients', $r['totals']['new_patients']) ?>
</div>
<div class="table-wrap" tabindex="0" role="group" aria-label="Table: scroll sideways if it does not fit">
<table class="data">
    <thead><tr><th>Date</th><th class="num">Still booked</th><th class="num">Attended</th><th class="num">Missed</th><th class="num">Cancelled</th><th class="num">Walk-ins</th><th class="num">Total visits</th><th class="num">New patients</th></tr></thead>
    <tbody>
    <?php foreach ($r['days'] as $d): ?>
        <?php if ($d['booked'] + $d['attended'] + $d['missed'] + $d['cancelled'] + $d['visits'] + $d['new_patients'] === 0) { continue; } ?>
        <tr><td class="nowrap"><?= e(fmt_date($d['date'])) ?></td><td class="num"><?= $d['booked'] ?></td><td class="num"><?= $d['attended'] ?></td><td class="num"><?= $d['missed'] ?></td><td class="num"><?= $d['cancelled'] ?></td><td class="num"><?= $d['walkins'] ?></td><td class="num"><?= $d['visits'] ?></td><td class="num"><?= $d['new_patients'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td>Total</td><td class="num"><?= $r['totals']['booked'] ?></td><td class="num"><?= $r['totals']['attended'] ?></td><td class="num"><?= $r['totals']['missed'] ?></td><td class="num"><?= $r['totals']['cancelled'] ?></td><td class="num"><?= $r['totals']['walkins'] ?></td><td class="num"><?= $r['totals']['visits'] ?></td><td class="num"><?= $r['totals']['new_patients'] ?></td></tr></tfoot>
</table>
</div>
<h2>Patients seen by each doctor</h2>
<div class="table-wrap" tabindex="0" role="group" aria-label="Table: scroll sideways if it does not fit">
<table class="data">
    <thead><tr><th>Doctor</th><th class="num">Visits</th><th class="num">From appointments</th></tr></thead>
    <tbody>
    <?php foreach ($r['doctors'] as $d): ?><tr><td><?= e($d['full_name']) ?></td><td class="num"><?= (int) $d['visits'] ?></td><td class="num"><?= (int) $d['appointments'] ?></td></tr><?php endforeach; ?>
    <?php if (!$r['doctors']): ?><tr><td colspan="3" class="empty">No visits in this period.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?php page_end();
