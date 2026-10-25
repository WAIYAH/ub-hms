<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('appointments.update');

$rows = schedule_conflicts();

page_start('Appointments to move', 'conflicts');
?>
<p class="muted">These booked appointments fall on a day the doctor is away, or outside the hours the doctor now works. Nothing has been changed for you: contact the patient, then cancel the appointment and book a new time. Appointments disappear from this list once they are cancelled or moved.</p>

<div class="table-wrap" tabindex="0" role="group" aria-label="Table: scroll sideways if it does not fit">
<table class="data">
    <thead><tr><th>When</th><th>Patient</th><th>Doctor</th><th>Problem</th><th><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $a): ?>
        <tr>
            <td class="nowrap"><?= e(fmt_date($a['appointment_date'])) ?><br><?= e(fmt_time($a['start_time'])) ?>&ndash;<?= e(fmt_time($a['end_time'])) ?></td>
            <td><span class="op-number"><?= e($a['op_number']) ?></span>
                <?php if (user_can('patients.view')): ?><a href="<?= e(url('patients/view.php', ['id' => $a['patient_id']])) ?>"><?= e(patient_full_name($a)) ?></a><?php else: ?><?= e(patient_full_name($a)) ?><?php endif; ?>
                <?= $a['phone'] ? '<br><span class="muted small">' . e($a['phone']) . '</span>' : '' ?></td>
            <td><?= e($a['doctor_name']) ?></td>
            <td><?= $a['why'] === 'leave' ? badge('Doctor away') : badge('Outside hours') ?></td>
            <td class="nowrap">
                <?= form_open('appointments/action.php', 'inline') ?>
                    <input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="status" value="Cancelled">
                    <input type="hidden" name="reason" value="Doctor not available at that time"><input type="hidden" name="return" value="conflicts">
                    <button class="btn btn-small btn-danger" type="submit" data-confirm="Cancel this appointment? Tell the patient first.">Cancel</button>
                </form>
                <?php if (user_can('appointments.book')): ?><a class="btn btn-small" href="<?= e(url('appointments/book.php', ['patient_id' => $a['patient_id'], 'doctor_id' => $a['doctor_id']])) ?>">Book new time</a><?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="empty">Every booked appointment fits the doctors' hours and leave.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?php page_end();
