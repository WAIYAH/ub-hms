<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$tiles = [];
if (user_can('patients.view')) {
    $tiles[] = dashboard_tile('Patients registered', db_value('SELECT COUNT(*) FROM patients'), 'patients/index.php');
}
if (user_can('patients.register')) {
    $tiles[] = dashboard_tile('Registered today', db_value('SELECT COUNT(*) FROM patients WHERE DATE(created_at) = ?', [app_today()]), 'patients/register.php');
}
if (user_can('users.manage')) {
    $tiles[] = dashboard_tile('Active user accounts', db_value('SELECT COUNT(*) FROM users WHERE is_active = 1'), 'admin/users.php');
    $tiles[] = dashboard_tile('Locked accounts', db_value('SELECT COUNT(*) FROM users WHERE locked_until > NOW()'), 'admin/users.php', 'warn');
}
if (user_can('audit.view')) {
    $tiles[] = dashboard_tile('Audit entries today', db_value('SELECT COUNT(*) FROM audit_log WHERE DATE(created_at) = ?', [app_today()]), 'admin/audit.php');
}
$tiles = array_merge($tiles, dashboard_tiles($user));

page_start('Dashboard', 'home');
?>
<p>Welcome, <strong><?= e($user['full_name']) ?></strong>. You are signed in as <?= e(role_labels()[$user['role']]) ?>.</p>
<?php if ($tiles): ?>
    <div class="grid"><?= implode('', $tiles) ?></div>
<?php endif; ?>
<?= dashboard_alerts($user) ?>
<div class="card">
    <h2>What you can do</h2>
    <ul>
        <?php foreach (permission_matrix()[$user['role']] as $perm): ?>
            <li><?= e(permission_catalogue()[$perm] ?? $perm) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php page_end();
