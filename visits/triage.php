<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('triage.record');

$visitId = get_int('visit') ?? post_int('visit');
$v = $visitId !== null ? get_visit($visitId) : null;
if (!$v) {
    abort(404, 'That visit does not exist.');
}
$existing = get_triage((int) $v['id']);
$errors = [];
$values = $existing ?: [];

if (is_post()) {
    require_post();
    $action = post_str('action', 10) ?: 'triage';
    if ($action === 'service') {
        $err = record_visit_service((int) $v['id'], (int) post_int('service_id'), post_int('quantity') ?? 1, (int) $user['id']);
        flash($err ? 'error' : 'success', $err ?? 'Service recorded. It will appear on the invoice.');
        redirect('visits/triage.php', ['visit' => $v['id']]);
    }
    $values = $_POST;
    $result = save_triage((int) $v['id'], $_POST, (int) $user['id']);
    if ($result['ok']) {
        flash('success', 'Triage saved. ' . patient_full_name($v) . ' is ready for the doctor.');
        redirect('visits/queue.php', ['stage' => 'triage']);
    }
    $errors = $result['errors'];
}

$f = ['values' => $values, 'errors' => $errors];
$services = chargeable_services();
page_start('Triage', 'triage');
?>
<?= render_patient_banner($v) ?>
<?php if ($v['reason']): ?><p><strong>Reason for visit:</strong> <?= e($v['reason']) ?></p><?php endif; ?>
<?php if ($v['status'] !== 'Open'): ?>
    <div class="flash flash-warning">This visit is closed. Triage can no longer be changed.</div>
<?php else: ?>
<?= error_summary($errors) ?>
<form method="post" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="visit" value="<?= (int) $v['id'] ?>">
    <input type="hidden" name="action" value="triage">
    <fieldset>
        <legend>Vital signs</legend>
        <div class="form-grid">
            <?php foreach (triage_limits() as $name => [$label, $min, $max, $decimals]): ?>
                <?= field($f, $name, $label, ['type' => 'number', 'min' => $min, 'max' => $max, 'step' => $decimals ? '0.1' : '1', 'inputmode' => 'decimal']) ?>
            <?php endforeach; ?>
            <?= field($f, 'notes', 'Notes', ['type' => 'textarea', 'maxlength' => 500, 'class' => 'wide', 'rows' => 3]) ?>
        </div>
    </fieldset>
    <div class="actions">
        <button class="btn" type="submit"><?= $existing ? 'Save corrections' : 'Save triage' ?></button>
        <a class="btn btn-secondary" href="<?= e(url('visits/queue.php', ['stage' => 'triage'])) ?>">Back to the queue</a>
    </div>
</form>

<?php if (user_can('services.record') && $services): ?>
<div class="card mt">
    <h2>Record a procedure</h2>
    <p class="muted">For example a wound dressing or an injection. It is added to the patient's invoice automatically.</p>
    <?php foreach (visit_services_list((int) $v['id']) as $s): ?><p><?= e($s['name']) ?> &times; <?= (int) $s['quantity'] ?> <span class="muted small">(<?= e($s['recorded_by_name']) ?>)</span></p><?php endforeach; ?>
    <form method="post" class="filters">
        <?= csrf_field() ?><input type="hidden" name="visit" value="<?= (int) $v['id'] ?>"><input type="hidden" name="action" value="service">
        <div class="field"><label for="service_id">Service</label>
            <select id="service_id" name="service_id"><?php foreach ($services as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?> (<?= e(fmt_money($s['price'])) ?>)</option><?php endforeach; ?></select></div>
        <div class="field"><label for="quantity">Quantity</label><input id="quantity" name="quantity" type="number" min="1" max="20" value="1"></div>
        <button class="btn btn-secondary" type="submit">Add</button>
    </form>
</div>
<?php endif; ?>
<?php endif; ?>
<?php page_end();
