<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('patients.register');

$values = [];
$errors = [];
$duplicates = null;
$needsConfirmation = false;

if (is_post()) {
    require_post();
    $values = $_POST;
    $result = register_patient($_POST, (int) $user['id'], !empty($_POST['confirm_not_duplicate']));
    if ($result['ok']) {
        flash('success', 'Patient registered. Outpatient number: ' . $result['op_number']);
        redirect('patients/view.php', ['id' => $result['id']]);
    }
    $errors = $result['errors'];
    $duplicates = $result['duplicates'];
    $needsConfirmation = $result['needs_confirmation'];
}

$f = ['values' => $values, 'errors' => $errors];
page_start('Register patient', 'register');
?>
<p class="muted">Check whether the patient is already registered before you add a new record. Fields marked <span class="req">*</span> are required.</p>
<?= error_summary($errors) ?>

<?php if ($needsConfirmation && $duplicates): ?>
    <div class="flash flash-warning" role="alert">
        <strong>This patient may already be registered.</strong> Open the record below if it is the same person.
        <ul>
        <?php foreach ($duplicates['similar'] as $d): ?>
            <li><a href="<?= e(url('patients/view.php', ['id' => $d['id']])) ?>"><?= e($d['op_number']) ?> &ndash; <?= e(patient_full_name($d)) ?></a>
                (born <?= e(fmt_date($d['date_of_birth'])) ?><?= $d['phone'] ? ', ' . e($d['phone']) : '' ?>)</li>
        <?php endforeach; ?>
        </ul>
        <label class="check"><input type="checkbox" name="confirm_not_duplicate" value="1" form="register-form"> I have checked these records and this is a different person.</label>
    </div>
<?php endif; ?>

<?php /* The confirmation checkbox above belongs to this form through its form="register-form" attribute. */ ?>
<form method="post" id="register-form" novalidate>
    <?= csrf_field() ?>
    <?= patient_form_fields($f) ?>
    <div class="actions">
        <button class="btn" type="submit">Register patient</button>
        <a class="btn btn-secondary" href="<?= e(url('patients/index.php')) ?>">Cancel</a>
    </div>
</form>
<?php page_end();
