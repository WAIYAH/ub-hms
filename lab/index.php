<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('lab.worklist');

// "Start" is a POST action so a link in an email or a bookmark can never change a request.
if (is_post()) {
    require_post();
    $err = start_lab_request((int) post_int('id'), (int) $user['id']);
    flash($err ? 'error' : 'success', $err ?? 'Test started.');
    redirect('lab/index.php', ['view' => get_str('view', 10)]);
}

$view = get_str('view', 10);
$statuses = $view === 'done' ? ['Completed'] : LAB_OPEN_STATUSES;
$rows = lab_worklist($statuses, $view === 'done' ? 100 : 200);

page_start('Laboratory worklist', 'lab');
?>
<p>
    <a class="btn <?= $view === 'done' ? 'btn-secondary' : '' ?>" href="<?= e(url('lab/index.php')) ?>">Waiting and in progress</a>
    <a class="btn <?= $view === 'done' ? '' : 'btn-secondary' ?>" href="<?= e(url('lab/index.php', ['view' => 'done'])) ?>">Completed</a>
</p>
<div class="table-wrap" tabindex="0" role="group" aria-label="Table: scroll sideways if it does not fit">
<table class="data">
    <thead><tr><th>Requested</th><th>Patient</th><th>Test</th><th>Requested by</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td class="nowrap"><?= e(fmt_datetime($r['requested_at'])) ?></td>
            <td><span class="op-number"><?= e($r['op_number']) ?></span> <?= e(patient_full_name($r)) ?>
                <span class="muted small"><?= e($r['sex']) ?>, <?= e(age_label($r['date_of_birth'])) ?></span></td>
            <td><?= e($r['test_name']) ?><?= $r['clinical_notes'] ? '<br><span class="muted small">' . e($r['clinical_notes']) . '</span>' : '' ?></td>
            <td><?= e($r['requested_by_name']) ?></td>
            <td><?= lab_status_text($r) ?></td>
            <td class="nowrap">
                <?php if (user_can('lab.result')): ?>
                    <?php if ($r['status'] === 'Requested'): ?>
                        <?= form_open('lab/index.php?view=' . e($view), 'inline') ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-small btn-secondary" type="submit">Start</button></form>
                    <?php endif; ?>
                    <a class="btn btn-small" href="<?= e(url('lab/result.php', ['id' => $r['id']])) ?>"><?= $r['status'] === 'Completed' ? 'Correct result' : 'Enter result' ?></a>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="empty">No tests in this list.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?php page_end();
