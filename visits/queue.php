<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('visits.view');

$stage = get_str('stage', 10);
if (!in_array($stage, ['triage', 'consult'], true)) {
    $stage = '';
}
$rows = visit_queue($stage, $user['role'] === 'doctor' ? (int) $user['id'] : null);
$titles = ['triage' => 'Triage queue', 'consult' => 'Consultation queue', '' => 'All open visits'];
$active = ['triage' => 'triage', 'consult' => 'consult', '' => 'visits'][$stage];

page_start($titles[$stage], $active);
?>
<p class="muted">Patients are listed in the order they arrived. <?= $stage === 'consult' && $user['role'] === 'doctor' ? 'You see patients assigned to you and patients not yet assigned.' : '' ?></p>
<div class="table-wrap">
<table class="data">
    <thead><tr><th>Arrived</th><th>Waiting</th><th>Patient</th><th>Doctor</th><th>Reason</th><th>Stage</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $v): ?>
        <?php $mins = max(0, (int) floor((app_now()->getTimestamp() - strtotime($v['visit_date'])) / 60)); ?>
        <tr>
            <td class="nowrap"><?= e(fmt_datetime($v['visit_date'])) ?></td>
            <td class="nowrap"><?= $mins >= 60 ? e(intdiv($mins, 60) . ' h ' . ($mins % 60) . ' min') : e($mins . ' min') ?></td>
            <td><span class="op-number"><?= e($v['op_number']) ?></span> <?= e(patient_full_name($v)) ?>
                <span class="muted small"><?= e($v['sex']) ?>, <?= e(age_label($v['date_of_birth'])) ?></span>
                <?php if ($v['allergies'] && ($user['role'] === 'doctor' || $user['role'] === 'nurse')): ?><span class="badge badge-abnormal" title="<?= e($v['allergies']) ?>">Allergy</span><?php endif; ?></td>
            <td><?= e($v['doctor_name'] ?? 'Next available') ?></td>
            <td><?= e($v['reason']) ?></td>
            <td><?= badge($v['stage']) ?></td>
            <td class="nowrap">
                <?php if (user_can('triage.record') && $v['stage'] !== 'With doctor'): ?><a class="btn btn-small" href="<?= e(url('visits/triage.php', ['visit' => $v['id']])) ?>"><?= (int) $v['has_triage'] ? 'Edit triage' : 'Triage' ?></a><?php endif; ?>
                <?php if (user_can('consult.record') && $v['stage'] !== 'Waiting for triage'): ?><a class="btn btn-small" href="<?= e(url('visits/consult.php', ['visit' => $v['id']])) ?>"><?= $v['stage'] === 'With doctor' ? 'Continue' : 'Consult' ?></a><?php endif; ?>
                <a class="btn btn-small btn-secondary" href="<?= e(url('visits/view.php', ['id' => $v['id']])) ?>">View</a>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="empty">Nobody is waiting.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?php page_end();
