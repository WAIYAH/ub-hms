<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('lab.request');

$status = get_str('status', 12);
$rows = doctor_lab_requests((int) $user['id'], $status, 100);

page_start('My test results', 'labresults');
?>
<p class="muted">Results come back here to you because you requested the test.</p>
<form method="get" class="filters">
    <div class="field"><label for="status">Show</label>
        <select id="status" name="status">
            <option value="">All my requests</option>
            <?php foreach (['Completed' => 'Results ready', 'In progress' => 'In progress', 'Requested' => 'Waiting for the laboratory'] as $k => $label): ?>
                <option value="<?= e($k) ?>"<?= $status === $k ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select></div>
    <button class="btn btn-secondary" type="submit">Show</button>
</form>
<div class="table-wrap" tabindex="0" role="group" aria-label="Table: scroll sideways if it does not fit">
<table class="data">
    <thead><tr><th>Patient</th><th>Test</th><th>Status</th><th>Result</th><th>Entered</th><th><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><span class="op-number"><?= e($r['op_number']) ?></span> <?= e(patient_full_name($r)) ?></td>
            <td><?= e($r['test_name']) ?></td>
            <td><?= lab_status_text($r) ?></td>
            <td><?= $r['result'] !== null ? nl2br(e($r['result'])) : '<span class="muted">Not yet</span>' ?></td>
            <td class="nowrap"><?= $r['result_at'] ? e(fmt_datetime($r['result_at'])) . '<br><span class="muted small">' . e($r['result_by_name']) . '</span>' : '' ?></td>
            <td><?php if ($r['visit_status'] === 'Open'): ?><a class="btn btn-small" href="<?= e(url('visits/consult.php', ['visit' => $r['visit_id']])) ?>">Open visit</a><?php else: ?><a class="btn btn-small btn-secondary" href="<?= e(url('visits/view.php', ['id' => $r['visit_id']])) ?>">View</a><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="empty">No requests to show.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?php page_end();
