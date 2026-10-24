<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('patients.edit');

$id = get_int('id');
$p = $id !== null ? get_patient($id) : null;
if (!$p) {
    abort(404, 'That patient record does not exist.');
}

$values = $p;
$errors = [];
if (is_post()) {
    require_post();
    $values = $_POST;
    $result = update_patient((int) $p['id'], $_POST, (int) $user['id']);
    if ($result['ok']) {
        flash('success', 'Patient details saved.');
        redirect('patients/view.php', ['id' => $p['id']]);
    }
    $errors = $result['errors'];
}

$f = ['values' => $values, 'errors' => $errors];
page_start('Correct patient details', 'patients');
?>
<p>Outpatient number <span class="op-number"><?= e($p['op_number']) ?></span> cannot be changed.</p>
<?= error_summary($errors) ?>
<form method="post" novalidate>
    <?= csrf_field() ?>
    <?= patient_form_fields($f) ?>
    <div class="actions">
        <button class="btn" type="submit">Save changes</button>
        <a class="btn btn-secondary" href="<?= e(url('patients/view.php', ['id' => $p['id']])) ?>">Cancel</a>
    </div>
</form>
<?php page_end();
