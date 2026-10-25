<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('schedules.manage');

$rows = schedule_overview();
$withSchedule = count(array_filter($rows, static fn ($r) => $r['has_schedule']));
$awayToday = count(array_filter($rows, static fn ($r) => $r['on_leave_today']));
$toMove = array_sum(array_column($rows, 'conflicts'));

page_start('Doctor schedules', 'schedules');
?>
<p class="muted">Set the days and hours each doctor works and record their leave. Booking follows these hours: a time outside them cannot be booked. A doctor with no personal schedule keeps the clinic opening hours (<?= e((string) config('clinic.open')) ?> to <?= e((string) config('clinic.close')) ?>).</p>

<div class="grid">
    <?= dashboard_tile('Doctors with their own hours', $withSchedule . ' of ' . count($rows), '', '', 'clock', 'teal') ?>
    <?= dashboard_tile('Away today', $awayToday, '', $awayToday > 0 ? 'warn' : '', 'calendar-check', 'amber') ?>
    <?= dashboard_tile('Booked appointments to move', $toMove, '', $toMove > 0 ? 'danger' : '', 'alert', 'red') ?>
</div>

<div class="table-wrap" tabindex="0" role="group" aria-label="Table: scroll sideways if it does not fit">
<table class="data">
    <thead><tr><th>Doctor</th><th>Usual hours</th><th>Today</th><th>Next leave</th><th class="num">To move</th><th><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <?php
        $blocksToday = doctor_blocks_on((int) $r['id'], app_today());
        if ($r['on_leave_today']) {
            $today = badge('Away');
        } elseif ($blocksToday !== null && !$blocksToday) {
            $today = '<span class="muted">Not working</span>';
        } else {
            $today = badge('Working');
        }
        ?>
        <tr>
            <td><strong><?= e($r['full_name']) ?></strong></td>
            <td><?= e($r['summary']) ?></td>
            <td><?= $today ?></td>
            <td><?php if ($r['next_leave']): ?><?= e(fmt_date($r['next_leave']['start_date'])) ?><?= $r['next_leave']['end_date'] !== $r['next_leave']['start_date'] ? ' to ' . e(fmt_date($r['next_leave']['end_date'])) : '' ?><?php else: ?><span class="muted">None recorded</span><?php endif; ?></td>
            <td class="num"><?= $r['conflicts'] > 0 ? '<strong>' . (int) $r['conflicts'] . '</strong>' : '0' ?></td>
            <td class="nowrap"><a class="btn btn-small" href="<?= e(url('admin/schedule.php', ['doctor' => $r['id']])) ?>">Hours and leave</a></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="empty">There are no active doctors yet. The System Administrator creates doctor accounts.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?php page_end();
