<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('appointments.book');

$patientId = get_int('patient_id') ?? post_int('patient_id');
$patient = $patientId !== null ? get_patient($patientId) : null;
$doctors = list_doctors();
$errors = [];

if (is_post() && $patient) {
    require_post();
    $result = book_appointment($_POST, (int) $user['id']);
    if ($result['ok']) {
        $a = get_appointment($result['id']);
        flash('success', 'Appointment booked for ' . patient_full_name($a) . ' with ' . $a['doctor_name'] . ' on ' . fmt_date($a['appointment_date']) . ' at ' . fmt_time($a['start_time']) . '.');
        redirect('appointments/index.php', ['date' => $a['appointment_date'], 'doctor_id' => $a['doctor_id']]);
    }
    $errors = $result['errors'];
}

$doctorId = get_int('doctor_id') ?? post_int('doctor_id');
$date = get_str('date', 10) ?: clean_text($_POST['date'] ?? '', 10);
if (!is_valid_date($date)) {
    $date = app_now()->modify('+1 day')->format('Y-m-d');
}
$slots = ($patient && $doctorId) ? doctor_day_slots($doctorId, $date) : [];
$q = get_str('q', 60);
[$found] = (!$patient && $q !== '') ? search_patients($q, 15, 0) : [[]];

page_start('Book an appointment', 'book');
?>
<?php if (!$patient): ?>
    <div class="card">
        <h2>Step 1: find the patient</h2>
        <form method="get" class="filters">
            <div class="field grow"><label for="q">Outpatient number, name, ID number or phone</label><input id="q" name="q" type="search" value="<?= e($q) ?>" autofocus></div>
            <button class="btn" type="submit">Search</button>
        </form>
        <?php if ($q !== ''): ?>
            <div class="table-wrap"><table class="data"><tbody>
            <?php foreach ($found as $p): ?>
                <tr><td><span class="op-number"><?= e($p['op_number']) ?></span></td><td><?= e(patient_full_name($p)) ?></td><td><?= e(age_label($p['date_of_birth'])) ?></td>
                    <td><a class="btn btn-small" href="<?= e(url('appointments/book.php', ['patient_id' => $p['id']])) ?>">Choose</a></td></tr>
            <?php endforeach; ?>
            <?php if (!$found): ?><tr><td class="empty">No patient found. <?php if (user_can('patients.register')): ?><a href="<?= e(url('patients/register.php')) ?>">Register a new patient</a>.<?php endif; ?></td></tr><?php endif; ?>
            </tbody></table></div>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="card">
        <strong><?= e(patient_full_name($patient)) ?></strong> <span class="op-number"><?= e($patient['op_number']) ?></span>
        &middot; <?= e(age_label($patient['date_of_birth'])) ?> &middot; <a href="<?= e(url('appointments/book.php')) ?>">Change patient</a>
    </div>

    <?= error_summary($errors) ?>
    <form method="get" class="filters">
        <input type="hidden" name="patient_id" value="<?= (int) $patient['id'] ?>">
        <div class="field"><label for="doctor_id">Step 2: doctor</label>
            <select id="doctor_id" name="doctor_id"><option value="">Choose a doctor</option>
                <?php foreach ($doctors as $d): ?><option value="<?= (int) $d['id'] ?>"<?= $doctorId === (int) $d['id'] ? ' selected' : '' ?>><?= e($d['full_name']) ?></option><?php endforeach; ?>
            </select></div>
        <div class="field"><label for="date">Date</label><input id="date" name="date" type="date" value="<?= e($date) ?>" min="<?= e(app_today()) ?>"></div>
        <button class="btn btn-secondary" type="submit">Show free times</button>
    </form>

    <?php if ($doctorId): ?>
        <?php if (!$slots): ?>
            <div class="flash flash-warning">The clinic is closed on <?= e(fmt_date($date)) ?>. Choose another date.</div>
        <?php else: ?>
            <form method="post" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="patient_id" value="<?= (int) $patient['id'] ?>">
                <input type="hidden" name="doctor_id" value="<?= (int) $doctorId ?>">
                <input type="hidden" name="date" value="<?= e($date) ?>">
                <fieldset>
                    <legend>Step 3: choose a time on <?= e((new DateTimeImmutable($date))->format('l, d F Y')) ?></legend>
                    <div class="slots">
                    <?php foreach ($slots as $start => $s): ?>
                        <div class="slot<?= $s['state'] !== 'free' ? ' taken' : '' ?>">
                            <input type="radio" name="start_time" id="slot_<?= e(str_replace(':', '', $start)) ?>" value="<?= e($start) ?>"<?= $s['state'] !== 'free' ? ' disabled' : '' ?>>
                            <label for="slot_<?= e(str_replace(':', '', $start)) ?>"><?= e($start) ?></label>
                        </div>
                    <?php endforeach; ?>
                    </div>
                    <p class="muted small mt">Struck-through times are already booked or have passed. Each appointment is <?= (int) config('clinic.slot_minutes') ?> minutes.</p>
                    <?php if (isset($errors['start_time'])): ?><p class="field has-error"><span class="error"><?= e($errors['start_time']) ?></span></p><?php endif; ?>
                </fieldset>
                <?= field(['values' => $_POST, 'errors' => $errors], 'reason', 'Reason for the visit (optional)', ['maxlength' => 255]) ?>
                <div class="actions"><button class="btn" type="submit">Book appointment</button></div>
            </form>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
<?php page_end();
