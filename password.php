<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$forced = !empty($user['must_change_password']);
$errors = [];

if (is_post()) {
    require_post();
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    $hash = (string) db_value('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
    if (!password_verify($current, $hash)) {
        $errors['current_password'] = 'Your current password is not correct.';
    }
    $problems = password_problems($new, $user['username']);
    if ($problems) {
        $errors['new_password'] = implode(' ', $problems);
    } elseif ($new === $current) {
        $errors['new_password'] = 'Choose a password different from the current one.';
    }
    if ($new !== $confirm) {
        $errors['confirm_password'] = 'The two new passwords do not match.';
    }

    if (!$errors) {
        set_user_password((int) $user['id'], $new, false);
        $_SESSION['user']['must_change_password'] = 0;
        session_regenerate_id(true);
        audit('password_change', 'user', (int) $user['id'], 'Password changed by the user');
        flash('success', 'Your password has been changed.');
        redirect('index.php');
    }
    audit('password_change_failed', 'user', (int) $user['id'], 'Password change refused');
}

$f = ['values' => [], 'errors' => $errors];
page_start('Change password', '');
?>
<?php if ($forced): ?>
    <div class="flash flash-warning">Your password was set by the administrator. Choose your own password to continue.</div>
<?php endif; ?>
<div class="card" id="pw-card">
    <?= error_summary($errors) ?>
    <?= form_open() ?>
        <?= field($f, 'current_password', 'Current password', ['type' => 'password', 'required' => true, 'autocomplete' => 'current-password']) ?>
        <?= field($f, 'new_password', 'New password', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password',
            'hint' => 'At least ' . (int) config('security.min_password_length') . ' characters with an upper-case letter, a lower-case letter and a number.']) ?>
        <?= field($f, 'confirm_password', 'Confirm new password', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password']) ?>
        <div class="actions"><button class="btn" type="submit">Change password</button></div>
    </form>
</div>
<?php page_end();
