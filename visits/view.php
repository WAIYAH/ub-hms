<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('visits.view');

$id = get_int('id');
$v = $id !== null ? get_visit($id) : null;
if (!$v) {
    abort(404, 'That visit does not exist.');
}
audit('view', 'visit', (int) $v['id'], 'Opened visit summary');
$clinical = role_can($user['role'], 'consult.record');
$triage = get_triage((int) $v['id']);
$consultation = $clinical ? get_consultation((int) $v['id']) : null;

page_start('Visit summary', 'visits');
?>
<?= render_patient_banner($v) ?>
<div class="card">
    <h2>Visit</h2>
    <dl class="kv">
        <dt>Arrived</dt><dd><?= e(fmt_datetime($v['visit_date'])) ?></dd>
        <dt>Stage</dt><dd><?= badge(visit_stage($v)) ?></dd>
        <dt>Doctor</dt><dd><?= e($v['doctor_name'] ?? 'Not yet assigned') ?></dd>
        <dt>Reason</dt><dd><?= e($v['reason']) ?></dd>
        <?php if ($v['closed_at']): ?><dt>Closed</dt><dd><?= e(fmt_datetime($v['closed_at'])) ?></dd><?php endif; ?>
    </dl>
    <div class="actions no-print">
        <?php if (user_can('triage.record') && $v['status'] === 'Open'): ?><a class="btn btn-secondary" href="<?= e(url('visits/triage.php', ['visit' => $v['id']])) ?>">Triage</a><?php endif; ?>
        <?php if ($clinical): ?><a class="btn" href="<?= e(url('visits/consult.php', ['visit' => $v['id']])) ?>">Open consultation</a><?php endif; ?>
        <?php if (user_can('billing.view')): ?><a class="btn btn-secondary" href="<?= e(url('billing/invoice.php', ['visit' => $v['id']])) ?>">Invoice</a><?php endif; ?>
    </div>
</div>

<?php if (user_can('triage.record') || $clinical): ?>
<div class="card">
    <h2>Triage</h2>
    <?php if ($triage): ?>
        <dl class="kv">
            <?php foreach (triage_limits() as $name => [$label]): ?>
                <?php if ($triage[$name] !== null): ?><dt><?= e($label) ?></dt><dd><?= e($triage[$name]) ?></dd><?php endif; ?>
            <?php endforeach; ?>
            <?php if ($triage['notes']): ?><dt>Notes</dt><dd><?= e($triage['notes']) ?></dd><?php endif; ?>
        </dl>
    <?php else: ?><p class="muted">Not recorded.</p><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($consultation): ?>
<div class="card">
    <h2>Consultation</h2>
    <dl class="kv">
        <dt>Doctor</dt><dd><?= e($consultation['doctor_name']) ?></dd>
        <dt>Complaint</dt><dd><?= nl2br(e($consultation['complaint'])) ?></dd>
        <dt>Examination</dt><dd><?= nl2br(e($consultation['examination'])) ?></dd>
        <dt>Diagnosis</dt><dd><?= e($consultation['diagnosis']) ?><?= $consultation['icd10_code'] ? ' [' . e($consultation['icd10_code']) . ']' : '' ?></dd>
        <dt>Plan</dt><dd><?= nl2br(e($consultation['plan'])) ?></dd>
    </dl>
</div>
<?php endif; ?>
<?php if (function_exists('render_visit_orders')) { echo render_visit_orders($v, $user); } ?>
<?php page_end();
