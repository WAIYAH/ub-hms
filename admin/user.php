<?php
require __DIR__ . '/../includes/bootstrap.php';
$admin = require_permission('users.manage');

$id = get_int('id');
$editing = $id !== null ? get_user($id) : null;
if ($id !== null && !$editing) {
    abort(404, 'That user does not exist.');
}

$form = take_form();
$values = $form['values'] ?: ($editing ? [
    'full_name' => $editing['full_name'], 'username' => $editing['username'],
    'role' => $editing['role'], 'phone' => (string) $editing['phone'],
] : []);
$errors = $form['errors'];

// Coming from an access request: fill the form from it so the administrator only has to check and save.
$fromId = $editing ? null : (get_int('from_request') ?? post_int('from_request'));
$fromRequest = $fromId !== null ? get_public_request($fromId) : null;
if ($fromRequest && $fromRequest['kind'] !== 'access') {
    $fromRequest = null;
}
if (!$values && $fromRequest) {
    $values = [
        'full_name' => $fromRequest['full_name'], 'username' => suggest_username($fromRequest['full_name']),
        'role' => (string) $fromRequest['requested_role'], 'phone' => (string) $fromRequest['phone'],
    ];
}

if (is_post()) {
    require_post();
    $action = post_str('action', 20);

    if ($editing && $action === 'reset') {
        $temp = generate_temp_password();
        reset_user_password((int) $editing['id'], $temp, (int) $admin['id']);
        flash('success', 'New one-time password for ' . $editing['username'] . ': ' . $temp . ' (shown once; the user must change it at the next sign-in).');
        redirect('admin/user.php', ['id' => $editing['id']]);
    }

    [$clean, $errs] = validate_user_input($_POST, $editing ? (int) $editing['id'] : null);
    if ($errs) {
        remember_form($clean, $errs);
        redirect('admin/user.php', $editing ? ['id' => $editing['id']] : ($fromRequest ? ['from_request' => $fromRequest['id']] : []));
    }
    if ($editing) {
        $problem = update_user((int) $editing['id'], $clean, (int) $admin['id']);
        if ($problem) {
            remember_form($clean, ['role' => $problem]);
            redirect('admin/user.php', ['id' => $editing['id']]);
        }
        flash('success', 'Account updated.');
        redirect('admin/users.php');
    }
    $temp = generate_temp_password();
    $newId = create_user($clean, $temp, (int) $admin['id']);
    if ($fromRequest) {
        set_public_request_status((int) $fromRequest['id'], 'done', 'Account created: ' . $clean['username'], (int) $admin['id']);
    }
    flash('success', 'Account created for ' . $clean['username'] . '. One-time password: ' . $temp . ' (shown once; the user must change it at first sign-in).');
    redirect('admin/user.php', ['id' => $newId]);
}

$f = ['values' => $values, 'errors' => $errors];
page_start($editing ? 'Edit user' : 'Add user', 'users');
?>
<?php if ($fromRequest): ?>
<div class="flash flash-info">
    <strong>From access request <?= e($fromRequest['reference']) ?></strong>: <?= e((string) $fromRequest['department']) ?>, supervisor <?= e((string) $fromRequest['supervisor']) ?><?= $fromRequest['staff_number'] ? ', staff number ' . e($fromRequest['staff_number']) : '' ?>, email <?= e($fromRequest['email']) ?>.
    Check the person with their supervisor before you save. Saving marks the request as done.
</div>
<?php endif; ?>
<div class="card">
    <?= error_summary($errors) ?>
    <?= form_open() ?>
        <?php if ($fromRequest): ?><input type="hidden" name="from_request" value="<?= (int) $fromRequest['id'] ?>"><?php endif; ?>
        <div class="form-grid">
            <?= field($f, 'full_name', 'Full name', ['required' => true, 'maxlength' => 120, 'autocomplete' => 'off']) ?>
            <?= field($f, 'username', 'Username', ['required' => true, 'maxlength' => 40, 'autocomplete' => 'off', 'hint' => 'Lower-case letters, numbers, dots, dashes or underscores.']) ?>
            <?= select_field($f, 'role', 'Role', role_labels(), ['required' => true, 'empty' => 'Choose a role']) ?>
            <?= field($f, 'phone', 'Mobile phone (optional)', ['maxlength' => 20, 'inputmode' => 'tel', 'placeholder' => '0712 345 678']) ?>
        </div>
        <?php if (!$editing): ?>
            <p class="muted">A one-time password is generated when you save. Give it to the user in person; they must change it at first sign-in.</p>
        <?php endif; ?>
        <div class="actions">
            <button class="btn" type="submit"><?= $editing ? 'Save changes' : 'Create account' ?></button>
            <a class="btn btn-secondary" href="<?= e(url('admin/users.php')) ?>">Cancel</a>
        </div>
    </form>
</div>

<?php if ($editing): ?>
<div class="card">
    <h2>Password</h2>
    <p class="muted">If the user has forgotten their password, generate a new one-time password. Their old password stops working immediately.</p>
    <?= form_open() ?>
        <input type="hidden" name="action" value="reset">
        <button class="btn btn-danger" type="submit" data-confirm="Reset the password for <?= e($editing['full_name']) ?>?">Reset password</button>
    </form>
</div>
<?php endif; ?>
<?php page_end();
