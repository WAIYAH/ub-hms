<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('users.manage');

// Row actions (activate, deactivate, unlock) are POST-only and token-protected.
if (is_post()) {
    require_post();
    $id = post_int('id');
    $action = post_str('action', 20);
    $error = null;
    if ($id === null || !get_user($id)) {
        $error = 'User not found.';
    } elseif ($action === 'activate' || $action === 'deactivate') {
        $error = set_user_active($id, $action === 'activate', (int) $user['id']);
        if (!$error) {
            flash('success', 'Account ' . ($action === 'activate' ? 'activated.' : 'deactivated.'));
        }
    } elseif ($action === 'unlock') {
        unlock_user($id, (int) $user['id']);
        flash('success', 'Account unlocked.');
    } else {
        $error = 'Unknown action.';
    }
    if ($error) {
        flash('error', $error);
    }
    redirect('admin/users.php');
}

$search = get_str('q', 60);
$role = get_str('role', 30);
$users = list_users($search, array_key_exists($role, role_labels()) ? $role : '');

page_start('User accounts', 'users');
?>
<form method="get" class="filters">
    <div class="field"><label for="q">Search name or username</label><input id="q" name="q" type="search" value="<?= e($search) ?>"></div>
    <div class="field"><label for="role">Role</label>
        <select id="role" name="role"><option value="">All roles</option>
            <?php foreach (role_labels() as $code => $label): ?>
                <option value="<?= e($code) ?>"<?= $role === $code ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select></div>
    <button class="btn btn-secondary" type="submit">Filter</button>
    <a class="btn" href="<?= e(url('admin/user.php')) ?>">Add user</a>
</form>

<div class="table-wrap">
<table class="data">
    <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Last sign-in</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
        <tr>
            <td><?= e($u['full_name']) ?></td>
            <td><?= e($u['username']) ?></td>
            <td><?= e($u['role_name']) ?></td>
            <td><?= (int) $u['is_active'] ? badge($u['is_locked'] ? 'Locked' : 'Active') : badge('Inactive') ?></td>
            <td><?= $u['last_login_at'] ? e(fmt_datetime($u['last_login_at'])) : '<span class="muted">Never</span>' ?></td>
            <td>
                <a class="btn btn-small btn-secondary" href="<?= e(url('admin/user.php', ['id' => $u['id']])) ?>">Edit</a>
                <?php if ($u['is_locked']): ?>
                    <?= form_open('admin/users.php', 'inline') ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><input type="hidden" name="action" value="unlock"><button class="btn btn-small" type="submit">Unlock</button></form>
                <?php endif; ?>
                <?php if ((int) $u['id'] !== (int) $user['id']): ?>
                    <?= form_open('admin/users.php', 'inline') ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                    <?php if ((int) $u['is_active']): ?>
                        <input type="hidden" name="action" value="deactivate"><button class="btn btn-small btn-danger" type="submit" data-confirm="Deactivate <?= e($u['full_name']) ?>? They will not be able to sign in.">Deactivate</button>
                    <?php else: ?>
                        <input type="hidden" name="action" value="activate"><button class="btn btn-small" type="submit">Activate</button>
                    <?php endif; ?></form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$users): ?><tr><td colspan="6" class="empty">No users match your search.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?php page_end();
