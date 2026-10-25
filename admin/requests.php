<?php
/**
 * Staff access requests and contact messages sent from the public pages.
 * The administrator reads each one, records what was done, and creates accounts by hand.
 */
require __DIR__ . '/../includes/bootstrap.php';
$admin = require_permission('requests.manage');

$statuses = public_request_statuses();

// Status changes are POST-only and token-protected.
if (is_post()) {
    require_post();
    $id = post_int('id');
    $error = $id === null
        ? 'That request does not exist.'
        : set_public_request_status($id, post_str('status', 20), post_str('note', 255), (int) $admin['id']);
    flash($error ? 'error' : 'success', $error ?? 'Saved.');
    redirect('admin/requests.php', array_filter(['kind' => post_str('f_kind', 10), 'status' => post_str('f_status', 20), 'q' => post_str('f_q', 60)]));
}

$filters = ['kind' => get_str('kind', 10), 'status' => get_str('status', 20), 'q' => get_str('q', 60)];
$rows = list_public_requests($filters);
$newAccess = (int) db_value("SELECT COUNT(*) FROM public_requests WHERE kind = 'access' AND status = 'new'");
$newMessages = (int) db_value("SELECT COUNT(*) FROM public_requests WHERE kind = 'contact' AND status = 'new'");
$statusClass = ['new' => 'badge-booked', 'in_progress' => 'badge-in-progress', 'done' => 'badge-completed', 'declined' => 'badge-cancelled'];

page_start('Access requests and messages', 'requests');
?>
<div class="grid">
    <?= dashboard_tile('New access requests', $newAccess, 'admin/requests.php?kind=access&status=new', $newAccess > 0 ? 'warn' : '') ?>
    <?= dashboard_tile('New contact messages', $newMessages, 'admin/requests.php?kind=contact&status=new', $newMessages > 0 ? 'warn' : '') ?>
</div>

<form method="get" class="filters">
    <div class="field"><label for="q">Search name, email or reference</label><input id="q" name="q" type="search" value="<?= e($filters['q']) ?>"></div>
    <div class="field"><label for="kind">Type</label>
        <select id="kind" name="kind">
            <option value="">All</option>
            <option value="access"<?= $filters['kind'] === 'access' ? ' selected' : '' ?>>Access requests</option>
            <option value="contact"<?= $filters['kind'] === 'contact' ? ' selected' : '' ?>>Contact messages</option>
        </select></div>
    <div class="field"><label for="status">Status</label>
        <select id="status" name="status"><option value="">All</option>
            <?php foreach ($statuses as $code => $label): ?>
                <option value="<?= e($code) ?>"<?= $filters['status'] === $code ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select></div>
    <button class="btn btn-secondary" type="submit">Filter</button>
</form>

<?php foreach ($rows as $r): $isAccess = $r['kind'] === 'access'; ?>
    <div class="card">
        <div class="record-head">
            <h2><?= e($r['reference']) ?></h2>
            <span class="badge badge-info"><?= $isAccess ? 'Access request' : 'Contact message' ?></span>
            <span class="badge <?= e($statusClass[$r['status']] ?? '') ?>"><?= e($statuses[$r['status']] ?? $r['status']) ?></span>
            <span class="muted small"><?= e(fmt_datetime($r['created_at'])) ?></span>
        </div>
        <dl class="kv">
            <dt>Name</dt><dd><?= e($r['full_name']) ?></dd>
            <dt>Email</dt><dd><?= e($r['email']) ?></dd>
            <?php if ($r['phone']): ?><dt>Phone</dt><dd><?= e($r['phone']) ?></dd><?php endif; ?>
            <?php if ($isAccess): ?>
                <dt>Department</dt><dd><?= e((string) $r['department']) ?></dd>
                <dt>Role requested</dt><dd><?= e(role_labels()[$r['requested_role']] ?? (string) $r['requested_role']) ?></dd>
                <?php if ($r['staff_number']): ?><dt>Staff number</dt><dd><?= e($r['staff_number']) ?></dd><?php endif; ?>
                <dt>Supervisor</dt><dd><?= e((string) $r['supervisor']) ?></dd>
            <?php else: ?>
                <dt>Topic</dt><dd><?= e((string) $r['topic']) ?></dd>
            <?php endif; ?>
            <dt>Sent from</dt><dd><?= e((string) $r['ip_address']) ?></dd>
            <?php if ($r['handled_at']): ?><dt>Last handled</dt><dd><?= e(fmt_datetime($r['handled_at'])) ?> by <?= e((string) $r['handler_name']) ?></dd><?php endif; ?>
        </dl>
        <?php if ($r['message']): ?>
            <p class="muted small mt"><?= $isAccess ? 'Reason or notes' : 'Message' ?></p>
            <blockquote class="request-text"><?= nl2br(e($r['message'])) ?></blockquote>
        <?php endif; ?>
        <?= form_open('admin/requests.php', 'filters') ?>
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <input type="hidden" name="f_kind" value="<?= e($filters['kind']) ?>">
            <input type="hidden" name="f_status" value="<?= e($filters['status']) ?>">
            <input type="hidden" name="f_q" value="<?= e($filters['q']) ?>">
            <div class="field"><label for="st<?= (int) $r['id'] ?>">Status</label>
                <select id="st<?= (int) $r['id'] ?>" name="status">
                    <?php foreach ($statuses as $code => $label): ?>
                        <option value="<?= e($code) ?>"<?= $r['status'] === $code ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="field grow"><label for="nt<?= (int) $r['id'] ?>">Note (optional)</label><input id="nt<?= (int) $r['id'] ?>" name="note" maxlength="255" value="<?= e((string) $r['admin_note']) ?>"></div>
            <button class="btn btn-secondary" type="submit">Save</button>
            <?php if ($isAccess && in_array($r['status'], ['new', 'in_progress'], true)): ?>
                <a class="btn" href="<?= e(url('admin/user.php', ['from_request' => $r['id']])) ?>">Create account</a>
            <?php endif; ?>
        </form>
    </div>
<?php endforeach; ?>
<?php if (!$rows): ?><div class="card"><p class="empty">No requests or messages match your search.</p></div><?php endif; ?>
<p class="muted small">Access requests never create an account by themselves. Check the person with their supervisor, then use <strong>Create account</strong>. Messages must not contain patient information; if one does, mark it done and tell the sender.</p>
<?php page_end();
