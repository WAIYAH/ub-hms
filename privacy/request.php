<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('privacy.view');

$id = get_int('id') ?? post_int('id');
$dr = $id !== null ? get_data_request($id) : null;
if (!$dr) {
    abort(404, 'That request does not exist.');
}
$id = (int) $dr['id'];

if (is_post()) {
    require_post();
    $action = post_str('action', 10);
    if ($action === 'withdraw' && user_can('patients.erase.request')) {
        $error = cancel_data_request($id, (int) $user['id']);
        flash($error ? 'error' : 'success', $error ?? 'The request was withdrawn.');
    } elseif (in_array($action, ['approve', 'refuse'], true) && user_can('patients.erase.approve')) {
        $r = decide_erasure($id, $action, post_str('note', 255), (int) $user['id']);
        flash($r['ok'] ? 'success' : 'error', $r['ok'] ? ($action === 'approve' ? 'The record has been erased. Nothing in it identifies the person any more.' : 'The request was refused.') : $r['error']);
    } else {
        audit('access_denied', 'data_request', $id, 'Refused a data request action', (int) $user['id']);
        flash('error', 'You are not allowed to do that.');
    }
    redirect('privacy/request.php', ['id' => $id]);
}

$isErasure = $dr['kind'] === 'Erasure';
$erased = $dr['erased_at'] !== null;
// Who asked, and how they were identified, is for Records. The manager decides without it.
$seeRequester = user_can('patients.export');
$seeNames = user_can('patients.view') && !$erased;
$mine = (int) $dr['created_by'] === (int) $user['id'];
$open = $dr['status'] === 'Open';
$blockers = $isErasure && $open ? erasure_blockers((int) $dr['patient_id']) : [];
$impact = $isErasure && $open ? erasure_impact((int) $dr['patient_id']) : [];
$canDecide = $isErasure && $open && user_can('patients.erase.approve') && !$mine;
$canWithdraw = $isErasure && $open && user_can('patients.erase.request') && $mine;
audit('view', 'data_request', $id, 'Opened ' . $dr['reference']);

page_start('Request ' . $dr['reference'], 'privacy');
?>
<p><a href="<?= e(url('privacy/index.php')) ?>">&larr; All requests</a></p>

<div class="cols">
    <div class="card">
        <h2><?= $isErasure ? 'Erasure request' : 'Copy of the record' ?> <?= badge($dr['status']) ?></h2>
        <dl class="kv">
            <dt>Reference</dt><dd><?= e($dr['reference']) ?></dd>
            <dt>Patient</dt><dd><span class="op-number"><?= e($dr['op_number']) ?></span><?= $seeNames ? ' ' . e(patient_full_name($dr)) : '' ?><?= $erased ? ' ' . badge('Erased') : '' ?></dd>
            <dt>Asked by</dt><dd><?= e($dr['requester_relation']) ?><?= $seeRequester ? ': ' . e($dr['requester_name']) : '' ?></dd>
            <dt>Identity checked</dt><dd>Yes<?= $seeRequester && $dr['identity_note'] ? ': ' . e($dr['identity_note']) : '' ?></dd>
            <?php if ($dr['reason']): ?><dt>Reason given</dt><dd><?= e($dr['reason']) ?></dd><?php endif; ?>
            <dt>Recorded by</dt><dd><?= e($dr['created_by_name']) ?> on <?= e(fmt_datetime($dr['created_at'])) ?></dd>
            <?php if ($dr['status'] !== 'Open' && $dr['decided_by_name']): ?>
                <dt><?= $dr['status'] === 'Cancelled' ? 'Withdrawn by' : 'Decided by' ?></dt><dd><?= e($dr['decided_by_name']) ?> on <?= e(fmt_datetime($dr['decided_at'])) ?></dd>
            <?php endif; ?>
            <?php if ($dr['decision_note']): ?><dt>Note</dt><dd><?= e($dr['decision_note']) ?></dd><?php endif; ?>
        </dl>
        <?php if (!$isErasure && user_can('patients.export') && !$erased): ?>
            <p><a class="btn" href="<?= e(url('patients/export.php', ['request' => $id])) ?>">Open the copy</a></p>
        <?php endif; ?>
    </div>

    <?php if ($isErasure && $open): ?>
    <div class="card">
        <h2>Can this record be erased?</h2>
        <?php if ($blockers): ?>
            <div class="flash flash-warning"><strong>Not yet.</strong>
                <ul><?php foreach ($blockers as $b): ?><li><?= e($b) ?></li><?php endforeach; ?></ul></div>
        <?php else: ?>
            <div class="flash flash-success">Nothing stops it.</div>
        <?php endif; ?>
        <h3>What is kept and what is removed</h3>
        <p class="small">Kept, with nothing that identifies the person: <?= e(plural($impact['visits'], 'visit')) ?>, <?= e(plural($impact['appointments'], 'appointment')) ?>, <?= e(plural($impact['consultations'], 'consultation')) ?>,
            <?= e(plural($impact['lab_requests'], 'laboratory request')) ?>, <?= e(plural($impact['invoices'], 'invoice')) ?> and <?= e(plural($impact['payments'], 'payment or refund line', 'payment and refund lines')) ?>.</p>
        <p class="small">Removed: name, ID number, phone, address, next of kin, allergies and the exact date of birth (only the year stays). The free text of visits, appointments, consultations, nurse notes and laboratory notes and results is replaced by &quot;<?= e(ERASED_TEXT) ?>&quot;.
            The outpatient number is replaced by a reference that points to nobody.</p>
        <p class="small muted">Earlier entries in the audit trail still show the old outpatient number (never a name). Paper files and old backups are not reached by this and must be dealt with under the hospital&#39;s own procedures.</p>
    </div>
    <?php endif; ?>
</div>

<?php if ($canDecide): ?>
<div class="card mt">
    <h2>Your decision</h2>
    <p class="muted">Approving erases the record at once and <strong>cannot be undone</strong>. Refusing needs a reason that Records can tell the patient. Do not write the patient&#39;s name or details in the note: it is kept.</p>
    <?= form_open() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="field"><label for="f_note">Note (needed to refuse)</label><input id="f_note" name="note" maxlength="255" autocomplete="off"></div>
        <div class="actions">
            <?php if (!$blockers): ?><button class="btn btn-danger" type="submit" name="action" value="approve" data-confirm="Erase this record? This cannot be undone.">Approve and erase the record</button><?php endif; ?>
            <button class="btn btn-secondary" type="submit" name="action" value="refuse">Refuse the request</button>
        </div>
    </form>
</div>
<?php elseif ($isErasure && $open && user_can('patients.erase.approve') && $mine): ?>
    <div class="flash flash-warning">You recorded this request yourself, so another person must decide it.</div>
<?php endif; ?>

<?php if ($canWithdraw): ?>
<div class="card mt">
    <h2>Withdraw this request</h2>
    <?= form_open() ?>
        <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="withdraw">
        <button class="btn btn-secondary" type="submit" data-confirm="Withdraw this erasure request?">Withdraw request</button>
    </form>
</div>
<?php endif; ?>
<?php page_end();
