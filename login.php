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

page_start('Sign in', '', ['no_title' => true, 'no_flashes' => true, 'body_class' => 'landing', 'main_class' => 'landing-main', 'css' => ['css/site.css']]);

// The six modules shown on the welcome panel: [icon, name, one line about it].
$modules = [
    ['user-plus',   'Registration', 'One outpatient number per patient'],
    ['stethoscope', 'Consultation', 'Triage, diagnosis, prescriptions'],
    ['flask',       'Laboratory',   'Results return to the doctor'],
    ['pill',        'Pharmacy',     'Batch stock and expiry alerts'],
    ['receipt',     'Billing',      'Itemised invoices and reports'],
    ['shield',      'Security',     'Eight roles, full audit trail'],
];
?>
<div class="landing-grid">
    <section class="landing-hero" aria-label="About <?= e(config('short_name')) ?>">
        <a class="hero-brand" href="<?= e(url('welcome.php')) ?>" aria-label="<?= e(config('short_name')) ?> welcome page">
            <span class="brand-mark" aria-hidden="true">+</span>
            <span class="hero-wordmark"><?= e(config('short_name')) ?></span>
        </a>
        <div class="hero-copy">
            <p class="hero-eyebrow"><?= e(config('location')) ?></p>
            <p class="hero-title"><?= e(config('hospital')) ?></p>
            <p class="hero-lead">One secure record for reception, clinic, laboratory, pharmacy and billing.</p>
            <ul class="hero-modules">
                <?php foreach ($modules as [$ic, $name, $line]): ?>
                    <li><span class="hero-icon"><?= icon($ic) ?></span><span><strong><?= e($name) ?></strong><small><?= e($line) ?></small></span></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="hero-foot">
            <?= ecg_svg() ?>
            <p class="hero-note">Designed with Kenya's Data Protection Act, 2019 in mind.</p>
        </div>
    </section>

    <section class="landing-panel" aria-label="Sign in">
        <div class="login-card">
            <h1>Sign in</h1>
            <p class="card-sub">Use your own account. Accounts are personal and are never shared.</p>
            <?= render_flashes() ?>
            <?php if ($error): ?><div class="flash flash-error" role="alert"><?= icon('alert') ?><span><?= e($error) ?></span></div><?php endif; ?>
            <form method="post" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="next" value="<?= e(get_str('next', 300)) ?>">
                <div class="field">
                    <label for="username">Username</label>
                    <div class="input-wrap">
                        <?= icon('user', 'icon input-icon') ?>
                        <input id="username" name="username" type="text" value="<?= e($username) ?>" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" maxlength="40" required autofocus>
                    </div>
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <?= icon('lock', 'icon input-icon') ?>
                        <input id="password" name="password" type="password" autocomplete="current-password" required>
                        <button type="button" class="pw-toggle" data-toggle-password aria-controls="password" aria-pressed="false" aria-label="Show password" hidden>
                            <?= icon('eye', 'icon icon-show') ?><?= icon('eye-off', 'icon icon-hide') ?>
                        </button>
                    </div>
                    <small class="caps-warning" id="caps-warning" role="status" hidden>Caps Lock is on</small>
                </div>
                <button class="btn btn-block btn-lg" type="submit"><span>Sign in</span><?= icon('arrow-right') ?></button>
            </form>
            <p class="card-help">Forgot your password? Ask the system administrator to reset it.</p>
        </div>
        <p class="card-back"><a href="<?= e(url('welcome.php')) ?>">About <?= e(config('short_name')) ?> and what it does</a></p>
        <p class="notice">For authorised staff only. Every sign-in and action is recorded in the audit trail.</p>
        <p class="panel-foot"><?= e(config('hospital')) ?> &middot; <?= e(config('short_name')) ?> &middot; Confidential: for authorised staff only. All activity is recorded.</p>
    </section>
</div>
<?php page_end(false);
