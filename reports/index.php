<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();

$reports = [
    ['Attendance', 'reports/attendance.php', 'reports.attendance', 'Appointments booked, attended, missed and cancelled; walk-ins; new patients; patients seen by each doctor.'],
    ['Revenue', 'reports/revenue.php', 'reports.revenue', 'Amount billed and collected, by day, payment method and type of service; unpaid balances.'],
    ['Stock', 'reports/stock.php', 'reports.stock', 'Medicine stock, value, low stock, expiry alerts and movements.'],
    ['Laboratory workload', 'reports/lab.php', 'reports.lab', 'Tests requested, completed and pending; time taken; requests by doctor.'],
];
$allowed = array_filter($reports, static fn ($r) => user_can($r[2]));
if (!$allowed) {
    abort(403, 'Your account does not have permission to open reports.');
}
page_start('Reports', '');
?>
<div class="grid">
<?php foreach ($allowed as [$title, $path, , $about]): ?>
    <div class="card"><h2><a href="<?= e(url($path)) ?>"><?= e($title) ?></a></h2><p class="muted"><?= e($about) ?></p></div>
<?php endforeach; ?>
</div>
<?php page_end();
