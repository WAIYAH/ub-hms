<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('patients.view');

$id = get_int('id');
$p = $id !== null ? get_patient($id) : null;
if (!$p) {
    abort(404, 'That patient record does not exist.');
}
// Opening a record is itself recorded (Data Protection Act, 2019: accountability).
audit('view', 'patient', (int) $p['id'], 'Opened ' . $p['op_number']);

page_start('Patient record', 'patients', ['no_title' => true]);
?>
<div class="record-head">
    <h1 class="page-title"><?= e(patient_full_name($p)) ?></h1>
    <span class="op-number"><?= e($p['op_number']) ?></span>
</div>

<?php if ($p['erased_at'] !== null): ?>
    <?php $erasedRef = db_value("SELECT reference FROM data_requests WHERE patient_id = ? AND kind = 'Erasure' AND status = 'Completed' ORDER BY id DESC LIMIT 1", [(int) $p['id']]); ?>
    <div class="flash flash-info" role="note">
        <strong>This record was erased on <?= e(fmt_date(substr((string) $p['erased_at'], 0, 10))) ?></strong><?= $erasedRef ? ' (request ' . e($erasedRef) . ')' : '' ?>.
        Nothing here identifies the person any more. The visits, invoices and payments remain so that the hospital&#39;s accounts and statistics stay correct.
    </div>
<?php endif; ?>

<?php if ($p['allergies']): ?>
    <div class="allergy" role="note"><strong>Allergies:</strong> <?= e($p['allergies']) ?></div>
<?php endif; ?>

<div class="actions no-print">
    <?php if ($p['erased_at'] !== null): ?>
        <?php if (user_can('privacy.view')): ?><a class="btn btn-secondary" href="<?= e(url('privacy/index.php', ['q' => $p['op_number']])) ?>">See the erasure request</a><?php endif; ?>
    <?php else: ?>
    <?php if (user_can('patients.edit')): ?><a class="btn btn-secondary" href="<?= e(url('patients/edit.php', ['id' => $p['id']])) ?>">Correct details</a><?php endif; ?>
    <?php if (user_can('appointments.book')): ?><a class="btn btn-secondary" href="<?= e(url('appointments/book.php', ['patient_id' => $p['id']])) ?>">Book appointment</a><?php endif; ?>
    <?php if (user_can('visits.checkin')): ?><a class="btn" href="<?= e(url('visits/checkin.php', ['patient_id' => $p['id']])) ?>">Check in for a visit</a><?php endif; ?>
    <?php if (user_can('patients.export')): ?><a class="btn btn-info" href="<?= e(url('patients/data.php', ['id' => $p['id']])) ?>">Data requests</a><?php endif; ?>
    <?php endif; ?>
</div>

<div class="cols mt">
    <div class="card">
        <h2>Personal details</h2>
        <dl class="kv">
            <dt>Sex</dt><dd><?= e($p['sex']) ?></dd>
            <dt>Date of birth</dt><dd><?= e(fmt_date($p['date_of_birth'])) ?><?= $p['dob_estimated'] ? ' (estimated)' : '' ?> &middot; <?= e(age_label($p['date_of_birth'])) ?></dd>
            <dt>ID number</dt><dd><?= e($p['national_id']) ?: '<span class="muted">None recorded</span>' ?></dd>
            <dt>Phone</dt><dd><?= e($p['phone']) ?: '<span class="muted">None recorded</span>' ?></dd>
            <dt>Residence</dt><dd><?= e($p['residence']) ?: '<span class="muted">None recorded</span>' ?></dd>
        </dl>
    </div>
    <div class="card">
        <h2>Next of kin</h2>
        <?php if ($p['next_of_kin_name']): ?>
        <dl class="kv">
            <dt>Name</dt><dd><?= e($p['next_of_kin_name']) ?></dd>
            <dt>Relationship</dt><dd><?= e($p['next_of_kin_relation']) ?></dd>
            <dt>Phone</dt><dd><?= e($p['next_of_kin_phone']) ?></dd>
        </dl>
        <?php else: ?><p class="muted">None recorded.</p><?php endif; ?>
        <p class="muted small mt">Registered <?= e(fmt_datetime($p['created_at'])) ?> by <?= e($p['registered_by_name']) ?>.</p>
    </div>
</div>

<?php if (function_exists('render_patient_history')) { echo render_patient_history($p, $user); } ?>
<?php page_end();
