<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('lab.result');

$id = get_int('id') ?? post_int('id');
$r = $id !== null ? get_lab_request($id) : null;
if (!$r) {
    abort(404, 'That test request does not exist.');
}
$errors = [];
$values = ['result' => $r['result'] ?? '', 'is_abnormal' => $r['is_abnormal'] === null ? '' : (string) $r['is_abnormal']];

if (is_post()) {
    require_post();
    $values = ['result' => $_POST['result'] ?? '', 'is_abnormal' => $_POST['is_abnormal'] ?? ''];
    $flag = $values['is_abnormal'] === '1' ? true : ($values['is_abnormal'] === '0' ? false : null);
    $err = record_lab_result((int) $r['id'], (string) $values['result'], $flag, (int) $user['id']);
    if (!$err) {
        flash('success', 'Result saved and returned to ' . $r['requested_by_name'] . '.');
        redirect('lab/index.php');
    }
    $errors['result'] = $err;
}

$f = ['values' => $values, 'errors' => $errors];
page_start('Enter laboratory result', 'lab');
?>
<div class="card">
    <h2><?= e($r['test_name']) ?></h2>
    <dl class="kv">
        <dt>Patient</dt><dd><span class="op-number"><?= e($r['op_number']) ?></span> <?= e(patient_full_name($r)) ?> (<?= e($r['sex']) ?>, <?= e(age_label($r['date_of_birth'])) ?>)</dd>
        <dt>Requested by</dt><dd><?= e($r['requested_by_name']) ?> on <?= e(fmt_datetime($r['requested_at'])) ?></dd>
        <?php if ($r['clinical_notes']): ?><dt>Reason</dt><dd><?= e($r['clinical_notes']) ?></dd><?php endif; ?>
        <?php if ($r['reference_range']): ?><dt>Reference</dt><dd><?= e($r['reference_range']) ?></dd><?php endif; ?>
        <dt>Status</dt><dd><?= lab_status_text($r) ?></dd>
    </dl>
</div>
<?php if ($r['status'] === 'Cancelled'): ?>
    <div class="flash flash-warning">The doctor cancelled this request. A result cannot be entered.</div>
<?php else: ?>
<?= error_summary($errors) ?>
<form method="post" novalidate>
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
    <?= field($f, 'result', 'Result', ['type' => 'textarea', 'required' => true, 'rows' => 5, 'maxlength' => 2000, 'hint' => 'Write the value with its unit, for example: Hb 11.2 g/dL. Free text is fine for tests such as microscopy.']) ?>
    <?= select_field($f, 'is_abnormal', 'Flag', ['' => 'Not stated', '0' => 'Within the usual range', '1' => 'Outside the usual range']) ?>
    <div class="actions">
        <button class="btn" type="submit"><?= $r['status'] === 'Completed' ? 'Save correction' : 'Save result' ?></button>
        <a class="btn btn-secondary" href="<?= e(url('lab/index.php')) ?>">Back to the worklist</a>
    </div>
</form>
<?php endif; ?>
<?php page_end();
