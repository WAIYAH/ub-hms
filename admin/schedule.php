<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('schedules.manage');

$doctorId = get_int('doctor') ?? post_int('doctor');
$doctor = $doctorId !== null ? db_one("SELECT u.id, u.full_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND r.code = 'doctor' AND u.is_active = 1", [$doctorId]) : null;
if (!$doctor) {
    abort(404, 'That doctor does not exist or is no longer active.');
}
$doctorId = (int) $doctor['id'];
$here = ['doctor' => $doctorId];

$hourErrors = [];
$leaveErrors = [];
$leaveValues = [];
$hourValues = null;

if (is_post()) {
    require_post();
    $action = post_str('action', 20);
    if ($action === 'save_hours') {
        $r = save_doctor_schedule($doctorId, $_POST, (int) $user['id']);
        if ($r['ok']) {
            flash('success', 'Working hours saved for ' . $doctor['full_name'] . '.' . ($r['conflicts'] > 0 ? ' ' . plural($r['conflicts'], 'booked appointment') . ' no longer fit these hours and must be moved (the receptionists have been alerted).' : ''));
            redirect('admin/schedule.php', $here);
        }
        $hourErrors = $r['errors'];
        $hourValues = is_array($_POST['sch'] ?? null) ? $_POST['sch'] : [];
    } elseif ($action === 'clear_hours') {
        $removed = clear_doctor_schedule($doctorId, (int) $user['id']);
        flash($removed ? 'success' : 'info', $removed ? 'The personal schedule was removed. The clinic opening hours apply again.' : 'There was no personal schedule to remove.');
        redirect('admin/schedule.php', $here);
    } elseif ($action === 'add_leave') {
        $r = add_doctor_leave($doctorId, $_POST, (int) $user['id']);
        if ($r['ok']) {
            flash('success', 'Leave recorded for ' . $doctor['full_name'] . '.' . ($r['conflicts'] > 0 ? ' ' . plural($r['conflicts'], 'booked appointment') . ' fall inside it and must be moved (the receptionists have been alerted).' : ''));
            redirect('admin/schedule.php', $here);
        }
        $leaveErrors = $r['errors'];
        $leaveValues = $_POST;
    } elseif ($action === 'remove_leave') {
        $error = delete_doctor_leave((int) post_int('leave_id'), (int) $user['id']);
        flash($error ? 'error' : 'success', $error ?? 'Leave removed.');
        redirect('admin/schedule.php', $here);
    } else {
        flash('error', 'That action is not recognised.');
        redirect('admin/schedule.php', $here);
    }
}

$saved = doctor_schedule($doctorId);
$choices = schedule_time_choices();
$days = array_map('intval', (array) config('clinic.days'));
/** What the form shows: the values just submitted (after an error), the saved hours, or the clinic hours to start from. */
$cell = static function (int $wd, int $n, string $which) use ($hourValues, $saved, $choices): string {
    if ($hourValues !== null) {
        return clean_text($hourValues[$wd][$n][$which] ?? '', 5);
    }
    if ($saved) {
        $b = $saved[$wd][$n] ?? null;
        return $b ? $b[$which === 'from' ? 0 : 1] : '';
    }
    return $n === 0 ? ($which === 'from' ? $choices[0] : $choices[count($choices) - 1]) : '';
};
$timeSelect = static function (int $wd, int $n, string $which, string $label) use ($choices, $cell): string {
    $current = $cell($wd, $n, $which);
    $out = '<select name="sch[' . $wd . '][' . $n . '][' . $which . ']" aria-label="' . e($label) . '"><option value="">&ndash;</option>';
    foreach ($choices as $t) {
        $out .= '<option' . ($t === $current ? ' selected' : '') . '>' . e($t) . '</option>';
    }
    return $out . '</select>';
};

$leave = doctor_leave_list($doctorId);
$conflicts = schedule_conflicts($doctorId);

page_start('Hours and leave: ' . $doctor['full_name'], 'schedules');
?>
<p><a href="<?= e(url('admin/schedules.php')) ?>">&larr; All doctors</a></p>

<?php if ($conflicts): ?>
<div class="flash flash-warning" role="alert">
    <strong><?= e(plural(count($conflicts), 'booked appointment')) ?> no longer fit<?= count($conflicts) === 1 ? 's' : '' ?> these hours or leave.</strong>
    The receptionists have been alerted to move or cancel them; nothing is changed automatically.
    <ul>
    <?php foreach (array_slice($conflicts, 0, 8) as $c): ?>
        <li><?= e(fmt_date($c['appointment_date'])) ?> at <?= e(fmt_time($c['start_time'])) ?> (<?= e($c['op_number']) ?>): <?= $c['why'] === 'leave' ? 'inside recorded leave' : 'outside the working hours' ?></li>
    <?php endforeach; ?>
    </ul>
    <?php if (count($conflicts) > 8): ?><p class="small">and <?= count($conflicts) - 8 ?> more.</p><?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <h2>Usual weekly hours</h2>
    <p class="muted">Up to two blocks a day, for example a morning and an afternoon. Leave both times empty on a day the doctor does not work. <?= $saved ? '' : 'No personal schedule is saved yet, so the form starts from the clinic opening hours.' ?></p>
    <?= error_summary($hourErrors) ?>
    <?= form_open() ?>
        <input type="hidden" name="doctor" value="<?= $doctorId ?>">
        <input type="hidden" name="action" value="save_hours">
        <div class="table-wrap" tabindex="0" role="group" aria-label="Table: scroll sideways if it does not fit">
        <table class="data">
            <thead><tr><th>Day</th><th>First block: from</th><th>to</th><th>Second block: from</th><th>to</th></tr></thead>
            <tbody>
            <?php foreach ($days as $wd): $name = WEEKDAY_NAMES[$wd]; ?>
                <tr<?= isset($hourErrors["d$wd"]) ? ' class="row-error"' : '' ?>>
                    <th scope="row"><?= e($name) ?><?= isset($hourErrors["d$wd"]) ? '<br><small class="error" id="f_d' . $wd . '">' . e($hourErrors["d$wd"]) . '</small>' : '' ?></th>
                    <td><?= $timeSelect($wd, 0, 'from', "$name, first block, from") ?></td>
                    <td><?= $timeSelect($wd, 0, 'to', "$name, first block, to") ?></td>
                    <td><?= $timeSelect($wd, 1, 'from', "$name, second block, from") ?></td>
                    <td><?= $timeSelect($wd, 1, 'to', "$name, second block, to") ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <div class="actions"><button class="btn" type="submit">Save hours</button></div>
    </form>
    <?php if ($saved): ?>
        <?= form_open('', 'inline mt') ?>
            <input type="hidden" name="doctor" value="<?= $doctorId ?>">
            <input type="hidden" name="action" value="clear_hours">
            <button class="btn btn-secondary btn-small" type="submit" data-confirm="Remove the personal schedule? The doctor will follow the clinic opening hours again.">Remove personal schedule</button>
        </form>
    <?php endif; ?>
</div>

<div class="cols mt">
    <div class="card">
        <h2>Leave</h2>
        <?php if (!$leave): ?><p class="muted">No leave recorded, now or to come.</p><?php else: ?>
        <div class="table-wrap" tabindex="0" role="group" aria-label="Table: scroll sideways if it does not fit"><table class="data">
            <thead><tr><th>Dates</th><th>Type</th><th>Note</th><th><span class="sr-only">Actions</span></th></tr></thead><tbody>
            <?php foreach ($leave as $l): ?>
                <tr>
                    <td class="nowrap"><?= e(fmt_date($l['start_date'])) ?><?= $l['end_date'] !== $l['start_date'] ? ' to ' . e(fmt_date($l['end_date'])) : '' ?></td>
                    <td><?= e($l['type']) ?></td>
                    <td><?= e($l['reason']) ?></td>
                    <td class="nowrap"><?= form_open('', 'inline') ?><input type="hidden" name="doctor" value="<?= $doctorId ?>"><input type="hidden" name="action" value="remove_leave"><input type="hidden" name="leave_id" value="<?= (int) $l['id'] ?>"><button class="btn btn-small btn-danger" type="submit" data-confirm="Remove this leave?">Remove</button></form></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </div>
    <div class="card">
        <h2>Record leave</h2>
        <?= error_summary($leaveErrors) ?>
        <?= form_open() ?>
            <input type="hidden" name="doctor" value="<?= $doctorId ?>">
            <input type="hidden" name="action" value="add_leave">
            <div class="form-grid">
                <?= field(['values' => $leaveValues, 'errors' => $leaveErrors], 'start_date', 'First day', ['type' => 'date', 'min' => app_today(), 'required' => true]) ?>
                <?= field(['values' => $leaveValues, 'errors' => $leaveErrors], 'end_date', 'Last day', ['type' => 'date', 'min' => app_today(), 'required' => true]) ?>
                <?= select_field(['values' => $leaveValues, 'errors' => $leaveErrors], 'type', 'Type', array_combine(LEAVE_TYPES, LEAVE_TYPES), ['required' => true]) ?>
                <?= field(['values' => $leaveValues, 'errors' => $leaveErrors], 'reason', 'Note (optional)', ['maxlength' => 255, 'hint' => 'Shown only here. Receptionists are told the doctor is away, not why.']) ?>
            </div>
            <div class="actions"><button class="btn" type="submit">Record leave</button></div>
        </form>
    </div>
</div>
<?php page_end();
