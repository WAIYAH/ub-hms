<?php
/** Your profile: who you are in the system, what your role allows, and what you have done recently. */
require __DIR__ . '/includes/bootstrap.php';
$me = require_login();

$u = get_user((int) $me['id']);
$roleName = role_labels()[$me['role']] ?? (string) $me['role'];
$activity = user_recent_activity((int) $me['id'], 10);
$perms = [];
foreach (permission_matrix()[$me['role']] as $p) {
    $perms[] = permission_catalogue()[$p] ?? $p;
}

page_start('Your profile', '', ['no_title' => true]);
?>
<section class="profile-hero">
    <?= shell_avatar($me, 'xl') ?>
    <div class="profile-id">
        <h1><?= e($u['full_name']) ?></h1>
        <p><span class="role-pill"><?= icon('badge-check') ?><?= e($roleName) ?></span> <span class="muted">@<?= e($u['username']) ?></span></p>
    </div>
    <div class="profile-actions">
        <a class="btn btn-info btn-icon" href="<?= e(url('settings.php')) ?>"><?= icon('settings') ?><span>Settings</span></a>
        <a class="btn btn-warning btn-icon" href="<?= e(url('password.php')) ?>"><?= icon('key') ?><span>Change password</span></a>
    </div>
</section>

<div class="dash-grid">
    <section class="panel w-4" aria-labelledby="pf-details">
        <header class="panel-head"><div><h2 id="pf-details">Your details</h2></div></header>
        <div class="panel-body">
            <dl class="kv kv-profile">
                <dt>Full name</dt><dd><?= e($u['full_name']) ?></dd>
                <dt>Username</dt><dd><?= e($u['username']) ?></dd>
                <dt>Role</dt><dd><?= e($roleName) ?></dd>
                <dt>Mobile phone</dt><dd><?= $u['phone'] ? e($u['phone']) : '<span class="muted">Not given</span>' ?></dd>
                <dt>Account created</dt><dd><?= e(fmt_date(substr((string) $u['created_at'], 0, 10))) ?></dd>
                <dt>This sign-in</dt><dd><?= $u['last_login_at'] ? e(fmt_datetime($u['last_login_at'])) : '<span class="muted">Unknown</span>' ?></dd>
                <dt>Status</dt><dd><?= badge((int) $u['is_active'] ? 'Active' : 'Inactive') ?></dd>
            </dl>
            <p class="muted small">Only the System Administrator can change your name, username or role. You can change your phone number in <a href="<?= e(url('settings.php')) ?>">Settings</a>.</p>
        </div>
    </section>

    <section class="panel w-8" aria-labelledby="pf-role">
        <header class="panel-head"><div><h2 id="pf-role">What your role can do</h2><p>Set by the hospital. Each page checks it, so nothing here can be switched on from a screen.</p></div></header>
        <div class="panel-body">
            <ul class="perm-list">
                <?php foreach ($perms as $p): ?><li><?= icon('check') ?><span><?= e($p) ?></span></li><?php endforeach; ?>
            </ul>
        </div>
    </section>

    <section class="panel w-12" aria-labelledby="pf-activity">
        <header class="panel-head"><div><h2 id="pf-activity">Your recent activity</h2><p>The last ten things recorded under your name. If you see something you did not do, tell the administrator.</p></div></header>
        <div class="panel-body">
            <?php
            $rows = [];
            foreach ($activity as $a) {
                $rows[] = [fmt_datetime($a['created_at']), audit_action_label($a['action']) . ($a['entity'] && !in_array($a['action'], ['login', 'logout', 'login_failed'], true) ? ' (' . $a['entity'] . ')' : ''), (string) $a['ip_address']];
            }
            echo dash_table(['When', 'What', 'From computer'], $rows, 'Nothing recorded yet.', [0, 2]);
            ?>
        </div>
    </section>
</div>
<?php page_end();
