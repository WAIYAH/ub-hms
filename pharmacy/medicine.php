<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('pharmacy.manage');

$id = get_int('id') ?? post_int('id');
$med = $id !== null ? get_medicine($id) : null;
if ($id !== null && !$med) {
    abort(404, 'That medicine does not exist.');
}
$values = $med ?: ['unit' => 'tablet', 'is_active' => 1, 'reorder_level' => '20'];
$errors = [];

if (is_post()) {
    require_post();
    $values = $_POST;
    [$clean, $errors] = validate_medicine_input($_POST, $med ? (int) $med['id'] : null);
    if (!$errors) {
        save_medicine($med ? (int) $med['id'] : null, $clean, (int) $user['id']);
        flash('success', 'Medicine saved.');
        redirect('pharmacy/medicines.php');
    }
}
$f = ['values' => $values, 'errors' => $errors];
page_start($med ? 'Edit medicine' : 'Add medicine', 'medicines');
?>
<?= error_summary($errors) ?>
<form method="post" novalidate>
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($med['id'] ?? 0) ?>">
    <div class="form-grid">
        <?= field($f, 'name', 'Name', ['required' => true, 'maxlength' => 100]) ?>
        <?= field($f, 'strength', 'Strength', ['maxlength' => 30, 'placeholder' => '500 mg']) ?>
        <?= field($f, 'form', 'Form', ['maxlength' => 30, 'placeholder' => 'Tablet, capsule, syrup...']) ?>
        <?= field($f, 'unit', 'Unit counted', ['maxlength' => 20, 'placeholder' => 'tablet, bottle, sachet...']) ?>
        <?= field($f, 'unit_price', 'Price per unit (KSh)', ['required' => true, 'inputmode' => 'decimal', 'maxlength' => 12]) ?>
        <?= field($f, 'reorder_level', 'Reorder when stock falls to', ['required' => true, 'type' => 'number', 'min' => 0]) ?>
    </div>
    <label class="check"><input type="checkbox" name="is_active" value="1"<?= !empty($values['is_active']) ? ' checked' : '' ?>> In use (can be prescribed)</label>
    <div class="actions"><button class="btn" type="submit">Save</button><a class="btn btn-secondary" href="<?= e(url('pharmacy/medicines.php')) ?>">Cancel</a></div>
</form>
<?php page_end();
