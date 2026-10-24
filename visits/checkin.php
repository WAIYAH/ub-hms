<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('visits.checkin');

$patientId = get_int('patient_id') ?? post_int('patient_id');
$patient = $patientId !== null ? get_patient($patientId) : null;
$doctors = list_doctors();
$error = '';

if (is_post() && $patient) {
    require_post();
    $apptId = post_int('appointment_id');
    $doctorId = post_int('doctor_id');
    $result = check_in_patient((int) $patient['id'], $apptId, $apptId === null ? $doctorId : null, post_str('reason', 255), (int) $user['id']);
    if ($result['ok']) {
        flash('success', patient_full_name($patient) . ' is checked in and waiting for triage.');
        redirect('visits/queue.php', ['stage' => 'triage']);
    }
    $error = $result['error'];
}

$q = get_str('q', 60);
[$found] = (!$patient && $q !== '') ? search_patients($q, 15, 0) : [[]];
$booked = $patient ? todays_appointments_for_patient((int) $patient['id']) : [];
$wanted = get_int('appointment_id') ?? post_int('appointment_id');
$todayList = !$patient ? db_all(
    "SELECT a.id, a.patient_id, a.start_time, p.op_number, p.first_name, p.middle_name, p.last_name, d.full_name AS doctor_name
       FROM appointments a JOIN patients p ON p.id = a.patient_id JOIN users d ON d.id = a.doctor_id
      WHERE a.appointment_date = ? AND a.status = 'Booked' ORDER BY a.start_time LIMIT 60",
    [app_today()]
) : [];

page_start('Check in a patient', 'checkin');
?>
<?php if ($error): ?><div class="flash flash-error" role="alert"><?= e($error) ?></div><?php endif; ?>

<?php if (!$patient): ?>
    <div class="card">
        <h2>Find the patient</h2>
        <form method="get" class="filters">
            <div class="field grow"><label for="q">Outpatient number, name, ID number or phone</label><input id="q" name="q" type="search" value="<?= e($q) ?>" autofocus></div>
            <button class="btn" type="submit">Search</button>
        </form>
        <?php if ($q !== ''): ?>
            <div class="table-wrap"><table class="data"><tbody>
            <?php foreach ($found as $p): ?>
                <tr><td><span class="op-number"><?= e($p['op_number']) ?></span></td><td><?= e(patient_full_name($p)) ?></td><td><?= e(age_label($p['date_of_birth'])) ?></td>
                    <td><a class="btn btn-small" href="<?= e(url('visits/checkin.php', ['patient_id' => $p['id']])) ?>">Choose</a></td></tr>
            <?php endforeach; ?>
            <?php if (!$found): ?><tr><td class="empty">No patient found. <a href="<?= e(url('patients/register.php')) ?>">Register a new patient</a>.</td></tr><?php endif; ?>
            </tbody></table></div>
        <?php endif; ?>
    </div>
    <div class="card">
        <h2>Booked for today, not yet checked in</h2>
        <?php if (!$todayList): ?><p class="muted">No waiting appointments.</p><?php else: ?>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>Time</th><th>Patient</th><th>Doctor</th><th></th></tr></thead><tbody>
            <?php foreach ($todayList as $a): ?>
                <tr><td><?= e(fmt_time($a['start_time'])) ?></td><td><span class="op-number"><?= e($a['op_number']) ?></span> <?= e(patient_full_name($a)) ?></td><td><?= e($a['doctor_name']) ?></td>
                    <td><a class="btn btn-small" href="<?= e(url('visits/checkin.php', ['patient_id' => $a['patient_id'], 'appointment_id' => $a['id']])) ?>">Check in</a></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </div>
<?php else: ?>
    <?= render_patient_banner($patient) ?>
    <?php $open = db_one("SELECT id FROM visits WHERE patient_id = ? AND status = 'Open'", [$patient['id']]); ?>
    <?php if ($open): ?>
        <div class="flash flash-warning">This patient already has an open visit. <a href="<?= e(url('visits/view.php', ['id' => $open['id']])) ?>">Open it</a>.</div>
    <?php else: ?>
    <form method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="patient_id" value="<?= (int) $patient['id'] ?>">
        <fieldset>
            <legend>How is the patient visiting?</legend>
            <?php foreach ($booked as $i => $a): ?>
                <label class="check"><input type="radio" name="appointment_id" value="<?= (int) $a['id'] ?>"<?= ($wanted === (int) $a['id'] || (!$wanted && $i === 0)) ? ' checked' : '' ?>>
                    Booked appointment at <strong><?= e(fmt_time($a['start_time'])) ?></strong> with <?= e($a['doctor_name']) ?><?= $a['reason'] ? ' (' . e($a['reason']) . ')' : '' ?></label>
            <?php endforeach; ?>
            <label class="check"><input type="radio" name="appointment_id" value=""<?= $booked ? '' : ' checked' ?>> Walk-in (no appointment)</label>
            <div class="form-grid mt">
                <?= select_field(['values' => $_POST, 'errors' => []], 'doctor_id', 'Preferred doctor (walk-ins only)', array_column($doctors, 'full_name', 'id'), ['empty' => 'Next available doctor']) ?>
                <?= field(['values' => $_POST, 'errors' => []], 'reason', 'Reason for the visit (optional)', ['maxlength' => 255]) ?>
            </div>
        </fieldset>
        <div class="actions"><button class="btn" type="submit">Check in</button><a class="btn btn-secondary" href="<?= e(url('visits/checkin.php')) ?>">Cancel</a></div>
    </form>
    <?php endif; ?>
<?php endif; ?>
<?php page_end();
