<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('consult.record');

$visitId = get_int('visit') ?? post_int('visit');
$v = $visitId !== null ? get_visit($visitId) : null;
if (!$v) {
    abort(404, 'That visit does not exist.');
}
$self = (int) $user['id'];
$consultation = get_consultation((int) $v['id']);
$errors = [];
$values = $consultation ?: ['service_id' => consultation_services()[0]['id'] ?? ''];
$open = $v['status'] === 'Open';
$back = ['visit' => $v['id']];

if (is_post()) {
    require_post();
    $action = post_str('action', 20) ?: 'consultation';

    if ($action === 'consultation') {
        $values = $_POST;
        $result = save_consultation((int) $v['id'], $_POST, $self);
        if ($result['ok']) {
            flash('success', 'Consultation saved. You can now request tests, prescribe or close the visit.');
            redirect('visits/consult.php', $back);
        }
        $errors = $result['errors'];
    } elseif ($action === 'service') {
        $err = record_visit_service((int) $v['id'], (int) post_int('service_id'), post_int('quantity') ?? 1, $self);
        flash($err ? 'error' : 'success', $err ?? 'Service recorded. It will appear on the invoice.');
        redirect('visits/consult.php', $back);
    } elseif ($action === 'close') {
        $err = close_visit((int) $v['id'], $self);
        if ($err) {
            flash('error', $err);
            redirect('visits/consult.php', $back);
        }
        flash('success', 'Visit closed.');
        redirect('visits/queue.php', ['stage' => 'consult']);
    } elseif (function_exists('handle_consult_action') && handle_consult_action($action, $v, $user)) {
        redirect('visits/consult.php', $back);
    }
}

audit('view', 'visit', (int) $v['id'], 'Opened consultation page');
$triage = get_triage((int) $v['id']);
$history = patient_history((int) $v['patient_id'], (int) $v['id'], 10);
$locked = $consultation && (int) $consultation['doctor_id'] !== $self;
$icd = db_all('SELECT code, description FROM icd10_codes WHERE is_active = 1 ORDER BY code');
$f = ['values' => $values, 'errors' => $errors];
$consultTypes = array_column(consultation_services(), 'name', 'id');
$services = chargeable_services();

page_start('Consultation', 'consult');
?>
<?= render_patient_banner($v) ?>
<?php if ($v['reason']): ?><p><strong>Reason for visit:</strong> <?= e($v['reason']) ?></p><?php endif; ?>

<div class="card">
    <h2>Triage</h2>
    <?php if ($triage): ?>
        <dl class="kv">
            <?php foreach (triage_limits() as $name => [$label]): ?>
                <?php if ($triage[$name] !== null): ?><dt><?= e($label) ?></dt><dd><?= e($triage[$name]) ?></dd><?php endif; ?>
            <?php endforeach; ?>
            <?php if ($triage['notes']): ?><dt>Nurse's notes</dt><dd><?= e($triage['notes']) ?></dd><?php endif; ?>
            <dt>Recorded</dt><dd><?= e(fmt_datetime($triage['recorded_at'])) ?> by <?= e($triage['nurse_name']) ?></dd>
        </dl>
    <?php else: ?>
        <p class="muted">No triage was recorded for this visit.</p>
    <?php endif; ?>
</div>

<details class="card">
    <summary><strong>Earlier visits (<?= count($history) ?>)</strong></summary>
    <?php if (!$history): ?><p class="muted">This is the patient's first recorded visit.</p><?php endif; ?>
    <ul class="timeline mt">
    <?php foreach ($history as $h): ?>
        <li><strong><?= e(fmt_date($h['visit_date'])) ?></strong> &middot; <?= e($h['doctor_name'] ?? '') ?>
            <br>Diagnosis: <?= e($h['diagnosis'] ?? 'not recorded') ?><?= $h['icd10_code'] ? ' [' . e($h['icd10_code']) . ']' : '' ?>
            <?php if ($h['plan']): ?><br>Plan: <?= e($h['plan']) ?><?php endif; ?>
            <?php if ($h['tests']): ?><br>Tests: <?= e(implode(', ', array_map(static fn ($t) => $t['test_name'] . ($t['result'] !== null ? ': ' . $t['result'] : ' (' . strtolower($t['status']) . ')'), $h['tests']))) ?><?php endif; ?>
            <?php if ($h['medicines']): ?><br>Medicines: <?= e(implode(', ', array_map(static fn ($m) => $m['medicine_name'] . ' ' . $m['quantity'], $h['medicines']))) ?><?php endif; ?>
        </li>
    <?php endforeach; ?>
    </ul>
</details>

<?php if ($locked): ?>
    <div class="flash flash-info">This consultation was recorded by <?= e($consultation['doctor_name']) ?>. You can read it but only that doctor can change it.</div>
<?php endif; ?>

<div class="card">
    <h2>Consultation</h2>
    <?= error_summary($errors) ?>
    <?php if ($open && !$locked): ?>
    <form method="post" novalidate>
        <?= csrf_field() ?><input type="hidden" name="visit" value="<?= (int) $v['id'] ?>"><input type="hidden" name="action" value="consultation">
        <?= select_field($f, 'service_id', 'Consultation type', $consultTypes, ['required' => true]) ?>
        <?= field($f, 'complaint', "Patient's complaint and history", ['type' => 'textarea', 'required' => true, 'rows' => 4, 'maxlength' => 2000]) ?>
        <?= field($f, 'examination', 'Examination findings', ['type' => 'textarea', 'rows' => 3, 'maxlength' => 2000]) ?>
        <div class="form-grid">
            <?= field($f, 'diagnosis', 'Diagnosis', ['required' => true, 'maxlength' => 255, 'hint' => 'Write the diagnosis in words; this is always kept.']) ?>
            <?= field($f, 'icd10_code', 'ICD-10 code (optional)', ['maxlength' => 10, 'list' => 'icd-list', 'autocomplete' => 'off', 'hint' => 'Start typing a code or a name.']) ?>
        </div>
        <datalist id="icd-list"><?php foreach ($icd as $c): ?><option value="<?= e($c['code']) ?>" label="<?= e($c['description']) ?>"></option><?php endforeach; ?></datalist>
        <?= field($f, 'plan', 'Treatment plan and advice', ['type' => 'textarea', 'rows' => 3, 'maxlength' => 2000]) ?>
        <div class="actions"><button class="btn" type="submit"><?= $consultation ? 'Save corrections' : 'Save consultation' ?></button></div>
    </form>
    <?php elseif ($consultation): ?>
        <dl class="kv">
            <dt>Type</dt><dd><?= e($consultation['service_name']) ?></dd>
            <dt>Complaint</dt><dd><?= nl2br(e($consultation['complaint'])) ?></dd>
            <dt>Examination</dt><dd><?= nl2br(e($consultation['examination'])) ?></dd>
            <dt>Diagnosis</dt><dd><?= e($consultation['diagnosis']) ?><?= $consultation['icd10_code'] ? ' [' . e($consultation['icd10_code']) . ' ' . e($consultation['icd10_description']) . ']' : '' ?></dd>
            <dt>Plan</dt><dd><?= nl2br(e($consultation['plan'])) ?></dd>
        </dl>
    <?php else: ?>
        <p class="muted">This visit is closed.</p>
    <?php endif; ?>
</div>

<?php if (function_exists('render_consult_orders')) { echo render_consult_orders($v, $user, $open && $consultation !== null); } ?>

<?php if ($open && $consultation): ?>
<div class="card">
    <h2>Procedures and other services</h2>
    <?php foreach (visit_services_list((int) $v['id']) as $s): ?><p><?= e($s['name']) ?> &times; <?= (int) $s['quantity'] ?> <span class="muted small">(<?= e($s['recorded_by_name']) ?>)</span></p><?php endforeach; ?>
    <?php if ($services): ?>
    <form method="post" class="filters">
        <?= csrf_field() ?><input type="hidden" name="visit" value="<?= (int) $v['id'] ?>"><input type="hidden" name="action" value="service">
        <div class="field"><label for="service_id_proc">Service</label>
            <select id="service_id_proc" name="service_id"><?php foreach ($services as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="quantity">Quantity</label><input id="quantity" name="quantity" type="number" min="1" max="20" value="1"></div>
        <button class="btn btn-secondary" type="submit">Add</button>
    </form>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Finish</h2>
    <p class="muted">Closing the visit tells the clinic the consultation is complete. Laboratory results, dispensing and payment can still continue.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="visit" value="<?= (int) $v['id'] ?>"><input type="hidden" name="action" value="close">
        <button class="btn" type="submit" data-confirm="Close this visit?">Close visit</button></form>
</div>
<?php endif; ?>
<?php page_end();
