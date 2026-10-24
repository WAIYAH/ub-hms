<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('audit.view');

$filters = [
    'user_id' => get_int('user_id'),
    'action'  => get_str('action', 40),
    'entity'  => get_str('entity', 40),
    'from'    => get_str('from', 10),
    'to'      => get_str('to', 10),
];
$page = max(1, get_int('page') ?? 1);
$perPage = 50;
[$rows, $total] = search_audit($filters, $perPage, ($page - 1) * $perPage);
if ($page === 1) {
    audit('view', 'audit_log', null, 'Opened the audit trail');
}

$actions = array_column(db_all('SELECT DISTINCT action FROM audit_log ORDER BY action'), 'action');
$entities = array_column(db_all('SELECT DISTINCT entity FROM audit_log WHERE entity IS NOT NULL ORDER BY entity'), 'entity');
$staff = db_all('SELECT id, full_name, username FROM users ORDER BY full_name');

page_start('Audit trail', 'audit');
?>
<form method="get" class="filters">
    <div class="field"><label for="user_id">User</label>
        <select id="user_id" name="user_id"><option value="">Everyone</option>
            <?php foreach ($staff as $s): ?><option value="<?= (int) $s['id'] ?>"<?= $filters['user_id'] === (int) $s['id'] ? ' selected' : '' ?>><?= e($s['full_name']) ?> (<?= e($s['username']) ?>)</option><?php endforeach; ?>
        </select></div>
    <div class="field"><label for="action">Action</label>
        <select id="action" name="action"><option value="">All actions</option>
            <?php foreach ($actions as $a): ?><option<?= $filters['action'] === $a ? ' selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?>
        </select></div>
    <div class="field"><label for="entity">Record type</label>
        <select id="entity" name="entity"><option value="">All records</option>
            <?php foreach ($entities as $en): ?><option<?= $filters['entity'] === $en ? ' selected' : '' ?>><?= e($en) ?></option><?php endforeach; ?>
        </select></div>
    <div class="field"><label for="from">From</label><input id="from" name="from" type="date" value="<?= e($filters['from']) ?>"></div>
    <div class="field"><label for="to">To</label><input id="to" name="to" type="date" value="<?= e($filters['to']) ?>"></div>
    <button class="btn btn-secondary" type="submit">Filter</button>
</form>

<p class="muted">The audit trail records who did what, from which computer and when. It holds identifiers and short descriptions only, never clinical details.</p>
<div class="table-wrap">
<table class="data">
    <thead><tr><th>Date and time</th><th>User</th><th>Action</th><th>Record</th><th>Details</th><th>Computer (IP)</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td class="nowrap"><?= e(fmt_datetime($r['created_at'])) ?></td>
            <td><?= $r['full_name'] ? e($r['full_name']) . ' <span class="muted small">(' . e($r['username']) . ')</span>' : '<span class="muted">Not signed in</span>' ?></td>
            <td><?= e($r['action']) ?></td>
            <td><?= $r['entity'] ? e($r['entity']) . ($r['entity_id'] ? ' #' . (int) $r['entity_id'] : '') : '' ?></td>
            <td><?= e($r['details']) ?></td>
            <td class="nowrap"><?= e($r['ip_address']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="empty">No audit entries match.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?= pager($total, $page, $perPage, array_filter($filters, static fn ($v) => $v !== null && $v !== '')) ?>
<?php page_end();
