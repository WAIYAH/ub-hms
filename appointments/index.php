<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('appointments.view');

// Appointments from earlier days that nobody attended are marked missed when the diary is opened.
mark_overdue_appointments_missed();

$date = get_str('date', 10);
if (!is_valid_date($date)) {
    $date = app_today();
}
$doctorId = array_key_exists('doctor_id', $_GET) ? get_int('doctor_id') : ($user['role'] === 'doctor' ? (int) $user['id'] : null);
$status = get_str('status', 12);
[$rows] = list_appointments(['date' => $date, 'doctor_id' => $doctorId, 'status' => $status], 200, 0);
$doctors = list_doctors();

$prev = (new DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
$next = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
$q = static fn (array $extra): array => array_filter($extra + ['doctor_id' => $doctorId, 'status' => $status], static fn ($v) => $v !== null && $v !== '');

page_start('Appointment diary', 'appointments');
?>
<form method="get" class="filters">
    <div class="field"><label for="date">Date</label><input id="date" name="date" type="date" value="<?= e($date) ?>"></div>
    <div class="field"><label for="doctor_id">Doctor</label>
        <select id="doctor_id" name="doctor_id"><option value="">All doctors</option>
            <?php foreach ($doctors as $d): ?><option value="<?= (int) $d['id'] ?>"<?= $doctorId === (int) $d['id'] ? ' selected' : '' ?>><?= e($d['full_name']) ?></option><?php endforeach; ?>
        </select></div>
    <div class="field"><label for="status">Status</label>
        <select id="status" name="status"><option value="">Any</option>
            <?php foreach (APPOINTMENT_STATUSES as $s): ?><option<?= $status === $s ? ' selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
        </select></div>
    <button class="btn btn-secondary" type="submit">Show</button>
    <?php if (user_can('appointments.book')): ?><a class="btn" href="<?= e(url('appointments/book.php')) ?>">Book appointment</a><?php endif; ?>
</form>

<p>
    <a href="<?= e(url('appointments/index.php', $q(['date' => $prev]))) ?>">&larr; Previous day</a> &nbsp;
    <strong><?= e((new DateTimeImmutable($date))->format('l, d F Y')) ?></strong> &nbsp;
    <a href="<?= e(url('appointments/index.php', $q(['date' => $next]))) ?>">Next day &rarr;</a>
    <?php if ($date !== app_today()): ?> &nbsp; <a href="<?= e(url('appointments/index.php', $q(['date' => app_today()]))) ?>">Today</a><?php endif; ?>
</p>

<div class="table-wrap">
<table class="data">
    <thead><tr><th>Time</th><th>Patient</th><th>Doctor</th><th>Reason</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $a): ?>
        <tr>
            <td class="nowrap"><?= e(fmt_time($a['start_time'])) ?>&ndash;<?= e(fmt_time($a['end_time'])) ?></td>
            <td><span class="op-number"><?= e($a['op_number']) ?></span>
                <?php if (user_can('patients.view')): ?><a href="<?= e(url('patients/view.php', ['id' => $a['patient_id']])) ?>"><?= e(patient_full_name($a)) ?></a>
                <?php else: ?><?= e(patient_full_name($a)) ?><?php endif; ?></td>
            <td><?= e($a['doctor_name']) ?></td>
            <td><?= e($a['reason']) ?></td>
            <td><?= badge($a['status']) ?><?= $a['cancel_reason'] ? '<br><span class="muted small">' . e($a['cancel_reason']) . '</span>' : '' ?></td>
            <td class="nowrap">
                <?php if ($a['status'] === 'Booked' && user_can('visits.checkin') && $a['appointment_date'] === app_today()): ?>
                    <a class="btn btn-small" href="<?= e(url('visits/checkin.php', ['patient_id' => $a['patient_id'], 'appointment_id' => $a['id']])) ?>">Check in</a>
                <?php endif; ?>
                <?php if ($a['status'] === 'Booked' && user_can('appointments.update')): ?>
                    <?php if (appointment_has_ended($a)): ?>
                        <?= form_open('appointments/action.php', 'inline') ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="status" value="Missed"><input type="hidden" name="back" value="<?= e($date) ?>"><button class="btn btn-small btn-secondary" type="submit">Mark missed</button></form>
                    <?php else: ?>
                        <?= form_open('appointments/action.php', 'inline') ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="status" value="Cancelled"><input type="hidden" name="back" value="<?= e($date) ?>"><button class="btn btn-small btn-danger" type="submit" data-confirm="Cancel this appointment?">Cancel</button></form>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($a['visit_id'] && user_can('visits.view')): ?>
                    <a class="btn btn-small btn-secondary" href="<?= e(url('visits/view.php', ['id' => $a['visit_id']])) ?>">Visit</a>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="empty">No appointments for this selection.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?php page_end();
