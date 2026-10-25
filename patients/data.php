<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('patients.export');

$id = get_int('id') ?? post_int('id');
$p = $id !== null ? get_patient($id) : null;
if (!$p) {
    abort(404, 'That patient record does not exist.');
}
$id = (int) $p['id'];
$erased = $p['erased_at'] !== null;
$canErase = user_can('patients.erase.request');

/** The "who is asking" fields. $px keeps the two forms on this page from sharing field names or ids. */
function data_request_fields(string $px, array $values, array $errors, string $reasonLabel): string
{
    $f = ['values' => $values, 'errors' => $errors];
    $h = '<div class="form-grid">'
        . field($f, $px . 'requester_name', 'Name of the person asking', ['required' => true, 'maxlength' => 120, 'autocomplete' => 'off'])
        . select_field($f, $px . 'requester_relation', 'Who is asking', array_combine(DATA_RELATIONS, DATA_RELATIONS), ['required' => true])
        . field($f, $px . 'identity_note', 'How did you check who is asking?', ['required' => true, 'maxlength' => 255, 'autocomplete' => 'off', 'hint' => 'For example "National ID card seen". Do not write the ID number.', 'class' => 'wide'])
        . field($f, $px . 'reason', $reasonLabel, ['maxlength' => 255, 'autocomplete' => 'off', 'class' => 'wide', 'hint' => 'Do not write the patient name or clinical details here.'])
        . '</div>';
    $err = $errors[$px . 'identity_checked'] ?? '';
    $h .= '<div class="field' . ($err ? ' has-error' : '') . '"><label class="check" for="f_' . e($px) . 'identity_checked"><input type="checkbox" id="f_' . e($px) . 'identity_checked" name="' . e($px)
        . 'identity_checked" value="1"' . (($values[$px . 'identity_checked'] ?? '') === '1' ? ' checked' : '') . ($err ? ' aria-invalid="true"' : '')
        . '> I have checked who is asking</label>' . ($err ? '<small class="error">' . e($err) . '</small>' : '') . '</div>';
    return $h;
}

/** Take the fields of one form (prefixed) back to the plain names the rules use. */
function data_request_input(string $px): array
{
    $in = [];
    foreach (['requester_name', 'requester_relation', 'identity_note', 'reason', 'identity_checked'] as $k) {
        $in[$k] = $_POST[$px . $k] ?? '';
    }
    return $in;
}

$errors = ['ex_' => [], 'er_' => []];
$values = ['ex_' => ['ex_requester_name' => patient_full_name($p), 'ex_requester_relation' => 'The patient'], 'er_' => ['er_requester_name' => patient_full_name($p), 'er_requester_relation' => 'The patient']];

if (is_post() && !$erased) {
    require_post();
    $action = post_str('action', 10);
    $px = $action === 'export' ? 'ex_' : 'er_';
    if ($action === 'export' || ($action === 'erase' && $canErase)) {
        $r = create_data_request($id, $action === 'export' ? 'Export' : 'Erasure', data_request_input($px), (int) $user['id']);
        if ($r['ok']) {
            if ($action === 'export') {
                flash('success', 'Request ' . $r['reference'] . ' recorded. The copy is ready.');
                redirect('patients/export.php', ['request' => $r['id']]);
            }
            flash('success', 'Request ' . $r['reference'] . ' recorded. The Hospital Manager will decide it; you will see the result in the list of requests.');
            redirect('privacy/request.php', ['id' => $r['id']]);
        }
        $values[$px] = $_POST;
        foreach ($r['errors'] as $k => $msg) {
            $errors[$px][$px . $k] = $msg;
        }
    } else {
        audit('access_denied', 'patient', $id, 'Refused a data request action', (int) $user['id']);
        flash('error', 'You are not allowed to do that.');
        redirect('patients/data.php', ['id' => $id]);
    }
}

$history = patient_data_requests($id);
$blockers = $erased ? [] : erasure_blockers($id);

page_start('Data requests', 'privacy', ['no_title' => true]);
?>
<div class="record-head">
    <h1 class="page-title">Data requests</h1>
    <span class="op-number"><?= e($p['op_number']) ?></span>
</div>
<p><strong><?= e(patient_full_name($p)) ?></strong> &middot; <a href="<?= e(url('patients/view.php', ['id' => $id])) ?>">Back to the record</a></p>
<p class="muted">Under the Data Protection Act, 2019 a person can ask for a copy of the information the hospital holds about them, or ask for it to be erased. Check who is asking first. Everything you do here is written to the audit trail.</p>

<?php if ($erased): ?>
    <div class="flash flash-info">This record has been erased (anonymised). Nothing identifies the person any more, so no further copy or erasure is possible.</div>
<?php else: ?>
<div class="cols">
    <div class="card">
        <h2><?= icon('download') ?> Give a copy of the record</h2>
        <p class="muted">Prints or downloads everything held about this patient: details, visits, tests, medicines, invoices and payments.</p>
        <?= error_summary($errors['ex_']) ?>
        <?= form_open() ?>
            <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="export">
            <?= data_request_fields('ex_', $values['ex_'], $errors['ex_'], 'Reason or purpose (optional)') ?>
            <div class="actions"><button class="btn" type="submit">Record the request and show the copy</button></div>
        </form>
    </div>
    <?php if ($canErase): ?>
    <div class="card">
        <h2><?= icon('trash') ?> Ask for the record to be erased</h2>
        <p class="muted">The Hospital Manager decides. If approved, the name, numbers, phone, address, next of kin and notes are removed from the record. Invoices, payments and statistics are kept, with nothing that identifies the person.</p>
        <?php if ($blockers): ?>
            <div class="flash flash-warning"><strong>These must be sorted out before the manager can approve:</strong>
                <ul><?php foreach ($blockers as $b): ?><li><?= e($b) ?></li><?php endforeach; ?></ul>
                You can still record the request now, so it is on file.</div>
        <?php else: ?>
            <div class="flash flash-success">Nothing stops this record being erased.</div>
        <?php endif; ?>
        <?= error_summary($errors['er_']) ?>
        <?= form_open() ?>
            <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="erase">
            <?= data_request_fields('er_', $values['er_'], $errors['er_'], 'Reason given (optional)') ?>
            <div class="actions"><button class="btn btn-danger" type="submit">Record the erasure request</button></div>
        </form>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card mt">
    <h2>Earlier requests for this patient</h2>
    <?php if (!$history): ?><p class="muted">None.</p><?php else: ?>
    <div class="table-wrap" tabindex="0" role="group" aria-label="Table: scroll sideways if it does not fit"><table class="data">
        <thead><tr><th>Reference</th><th>Recorded</th><th>Kind</th><th>Status</th><th>Recorded by</th><th><span class="sr-only">Actions</span></th></tr></thead><tbody>
        <?php foreach ($history as $h): ?>
            <tr><td class="nowrap"><?= e($h['reference']) ?></td><td class="nowrap"><?= e(fmt_datetime($h['created_at'])) ?></td><td><?= e($h['kind']) ?></td><td><?= badge($h['status']) ?></td><td><?= e($h['created_by_name']) ?></td>
                <td><a class="btn btn-small btn-secondary" href="<?= e(url('privacy/request.php', ['id' => $h['id']])) ?>">Open</a></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</div>
<?php page_end();
