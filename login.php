<?php
require __DIR__ . '/includes/bootstrap.php';

/** Accept only a local path inside this application as the place to return to after signing in. */
function safe_next(string $next): string
{
    $base = base_path();
    if ($next === '' || $next[0] !== '/' || strpos($next, '//') !== false || strpos($next, '\\') !== false
        || !preg_match('#^/[A-Za-z0-9_./\-?=&%]*$#', $next)) {
        return '';
    }
    if ($base !== '') {
        if (strncmp($next, $base . '/', strlen($base) + 1) !== 0) {
            return '';
        }
        $next = substr($next, strlen($base));
    }
    return ltrim($next, '/');
}

if (current_user()) {
    redirect('index.php');
}

$error = '';
$username = '';
if (is_post()) {
    require_post();
    $username = post_str('username', 40);
    $result = attempt_login($username, (string) ($_POST['password'] ?? ''), client_ip());
    if ($result['ok']) {
        start_user_session($result['user']);
        audit('login', 'user', (int) $result['user']['id'], null, (int) $result['user']['id']);
        $next = safe_next(post_str('next', 300));
        redirect($result['user']['must_change_password'] ? 'password.php' : ($next !== '' ? $next : 'index.php'));
    }
    $error = $result['error'];
}

page_start('Sign in', '', ['no_title' => true]);
?>
<div class="login-brand">
    <span class="brand-mark" aria-hidden="true">+</span>
    <h1><?= e(config('hospital')) ?></h1>
    <p class="muted"><?= e(config('app_name')) ?></p>
</div>
<div class="login-card">
    <h1>Sign in</h1>
    <?php if ($error): ?><div class="flash flash-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="next" value="<?= e(get_str('next', 300)) ?>">
        <div class="field">
            <label for="username">Username</label>
            <input id="username" name="username" type="text" value="<?= e($username) ?>" autocomplete="username" maxlength="40" required autofocus>
        </div>
        <div class="field">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
        </div>
        <button class="btn btn-block" type="submit">Sign in</button>
    </form>
    <p class="notice">For authorised staff only. Every sign-in and action is recorded in the audit trail.</p>
</div>
<?php page_end();
