<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('privacy.view');

$kind = get_str('kind', 7);
$status = get_str('status', 9);
$q = get_str('q', 30);
$rows = list_data_requests(['kind' => $kind, 'status' => $status, 'q' => $q]);

$open = (int) db_value("SELECT COUNT(*) FROM data_requests WHERE kind = 'Erasure' AND status = 'Open'");
$monthStart = app_now()->format('Y-m-01 00:00:00');
$copies = (int) db_value("SELECT COUNT(*) FROM data_requests WHERE kind = 'Export' AND created_at >= ?", [$monthStart]);
$erased = (int) db_value("SELECT COUNT(*) FROM data_requests WHERE kind = 'Erasure' AND status = 'Completed' AND decided_at >= ?", [$monthStart]);
$seeNames = user_can('patients.view');

page_start('Patient data requests', 'privacy');
?>
<p class="muted">A person can ask for a copy of what the hospital holds about them, or for it to be erased. Records staff check who is asking and record the request; the Hospital Manager decides erasures. Erasing a record removes everything that identifies the person but keeps the invoices, payments and counts.</p>

<div class="grid">
    <?= dashboard_tile('Erasures waiting for a decision', $open, '', $open > 0 ? 'warn' : '', 'shield', 'amber') ?>
    <?= dashboard_tile('Copies given this month', $copies, '', '', 'download', 'blue') ?>
    <?= dashboard_tile('Records erased this month', $erased, '', '', 'trash', 'violet') ?>
</div>

<form method="get" class="filters">
    <div class="field grow"><label for="q">Reference or outpatient number</label><input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="DR-2026- or OP26-"></div>
    <div class="field"><label for="kind">Kind</label>
        <select id="kind" name="kind"><option value="">Any</option><?php foreach (['Export' => 'Copy', 'Erasure' => 'Erasure'] as $v => $l): ?><option value="<?= e($v) ?>"<?= $kind === $v ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="status">Status</label>
        <select id="status" name="status"><option value="">Any</option><?php foreach (DATA_REQUEST_STATUSES as $s): ?><option<?= $status === $s ? ' selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-secondary" type="submit">Show</button>
</form>

<div class="table-wrap" tabindex="0" role="group" aria-label="Table: scroll sideways if it does not fit">
<table class="data">
    <thead><tr><th>Reference</th><th>Recorded</th><th>Kind</th><th>Patient</th><th>Asked by</th><th>Status</th><th>Recorded by</th><th>Decided by</th><th><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td class="nowrap"><?= e($r['reference']) ?></td>
            <td class="nowrap"><?= e(fmt_datetime($r['created_at'])) ?></td>
            <td><?= $r['kind'] === 'Export' ? 'Copy' : 'Erasure' ?></td>
            <td><span class="op-number"><?= e($r['op_number']) ?></span><?= $seeNames && $r['op_number'] !== '' && strpos($r['op_number'], 'ERASED-') !== 0 ? ' ' . e(patient_full_name($r)) : '' ?></td>
            <td><?= e($r['requester_relation']) ?></td>
            <td><?= badge($r['status']) ?></td>
            <td><?= e($r['created_by_name']) ?></td>
            <td><?= e($r['decided_by_name']) ?></td>
            <td class="nowrap"><a class="btn btn-small<?= $r['status'] === 'Open' && user_can('patients.erase.approve') ? '' : ' btn-secondary' ?>" href="<?= e(url('privacy/request.php', ['id' => $r['id']])) ?>"><?= $r['status'] === 'Open' && user_can('patients.erase.approve') ? 'Decide' : 'Open' ?></a></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="9" class="empty">No requests<?= $kind !== '' || $status !== '' || $q !== '' ? ' match this search' : ' yet' ?>.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?php page_end();
