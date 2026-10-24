<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('pharmacy.manage');

$errors = [];
$values = ['medicine_id' => get_int('medicine_id') ?? ''];
if (is_post()) {
    require_post();
    $values = $_POST;
    $r = receive_batch($_POST, (int) $user['id']);
    if ($r['ok']) {
        flash('success', 'Stock received and added to the shelf.');
        redirect('pharmacy/batches.php', ['medicine_id' => (int) $_POST['medicine_id']]);
    }
    $errors = $r['errors'];
}
$meds = [];
foreach (medicine_stock('', true) as $m) {
    $meds[$m['id']] = medicine_label($m);
}
$f = ['values' => $values, 'errors' => $errors];
page_start('Receive stock', 'receive');
?>
<?= error_summary($errors) ?>
<form method="post" novalidate>
    <?= csrf_field() ?>
    <div class="form-grid">
        <?= select_field($f, 'medicine_id', 'Medicine', $meds, ['required' => true, 'empty' => 'Choose a medicine']) ?>
        <?= field($f, 'batch_no', 'Batch number', ['required' => true, 'maxlength' => 40, 'autocomplete' => 'off', 'hint' => 'As printed on the pack.']) ?>
        <?= field($f, 'expiry_date', 'Expiry date', ['type' => 'date', 'required' => true, 'min' => app_now()->modify('+1 day')->format('Y-m-d')]) ?>
        <?= field($f, 'quantity', 'Quantity received', ['type' => 'number', 'required' => true, 'min' => 1, 'inputmode' => 'numeric']) ?>
    </div>
    <p class="muted">The medicine must already be on the <a href="<?= e(url('pharmacy/medicines.php')) ?>">medicine list</a>. Receiving more of an existing batch adds to it.</p>
    <div class="actions"><button class="btn" type="submit">Receive stock</button><a class="btn btn-secondary" href="<?= e(url('pharmacy/stock.php')) ?>">Cancel</a></div>
</form>
<?php page_end();
