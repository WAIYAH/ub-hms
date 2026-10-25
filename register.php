<?php
/**
 * Request access: how a member of staff asks for an account.
 *
 * UB-HMS holds patient information, so nobody can create an account for themselves. This form sends a request
 * to the System Administrator, who checks the person with their supervisor and creates the account by hand.
 * The form never asks for a password. The request is protected by the CSRF token, a hidden trap field and an hourly limit.
 */
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('index.php');
}

if (is_post()) {
    require_post();
    $ip = client_ip();
    if (post_str('website', 100) !== '') {
        // A robot filled the trap field. Pretend it worked and store nothing.
        remember_public_done('access', 'AR-' . app_now()->format('Y') . '-000000');
        redirect('register.php');
    }
    $posted = [];
    foreach (['full_name' => 120, 'email' => 160, 'phone' => 20, 'department' => 80, 'requested_role' => 30, 'staff_number' => 40, 'supervisor' => 120] as $k => $max) {
        $posted[$k] = post_str($k, $max);
    }
    $posted['message'] = clean_multiline($_POST['message'] ?? '', 500);
    $posted['consent'] = ($_POST['consent'] ?? '') === '1' ? '1' : '';
    if (public_request_limit_reached('access', $ip)) {
        remember_form($posted, ['full_name' => 'Too many requests were sent from this computer in the last hour. Please try again later, or ask your supervisor to contact the administrator.']);
        redirect('register.php');
    }
    [$clean, $errors] = validate_access_request($_POST);
    if ($errors) {
        remember_form($clean, $errors);
        redirect('register.php');
    }
    [, $reference] = create_public_request('access', $clean, $ip);
    remember_public_done('access', $reference);
    redirect('register.php');
}

$form = take_form();
$done = take_public_done('access');
$f = ['values' => $form['values'], 'errors' => $form['errors']];

site_start('Request access', 'register', ['description' => 'Ask for a UB-HMS staff account. The System Administrator checks every request and creates the account.']);
echo site_banner('register', 'Request access', 'Ask for your staff account', 'Tell us who you are and what you need. The System Administrator checks every request, so the patient records stay safe.', [['Home', 'welcome.php'], ['Request access', null]]);
?>
<section class="sec">
    <div class="wrap reg">
        <aside class="reg-side reveal" aria-label="How it works">
            <div class="reg-photo" role="img" aria-label="A smiling nurse in green scrubs"></div>
            <div class="reg-body">
                <h2>How it works</h2>
                <ol class="reg-steps">
                    <li><strong>You send this request</strong><span>It takes about two minutes. No password is needed.</span></li>
                    <li><strong>The administrator checks it</strong><span>They confirm your role with your supervisor.</span></li>
                    <li><strong>You get your account</strong><span>You receive a one-time password in person and choose your own at first sign-in.</span></li>
                </ol>
                <ul class="reg-assure">
                    <li><?= icon('shield') ?><span>Accounts are personal and are never shared.</span></li>
                    <li><?= icon('lock') ?><span>You will only see the screens your role needs.</span></li>
                    <li><?= icon('clipboard') ?><span>Every sign-in and action is recorded.</span></li>
                </ul>
            </div>
        </aside>

        <div class="fx-card fx-card-form reveal" id="form">
            <?php if ($done): ?>
                <div class="reg-done" role="status">
                    <span class="fx-ic"><?= icon('badge-check') ?></span>
                    <h2>Your request has been sent</h2>
                    <p class="sub">Thank you. The System Administrator will check it with your supervisor. Please keep this reference.</p>
                    <span class="reg-ref"><?= e($done['reference']) ?></span>
                    <ul class="checks">
                        <li><?= icon('check') ?><span>You do not need to send the request again.</span></li>
                        <li><?= icon('check') ?><span>We never send a password by email. The administrator gives you a one-time password in person.</span></li>
                        <li><?= icon('check') ?><span>If nothing happens after a few working days, ask your supervisor to contact the administrator, and quote your reference.</span></li>
                    </ul>
                    <p class="mt"><a class="btn btn-dark btn-xl" href="<?= e(url('welcome.php')) ?>">Back to the home page<?= icon('arrow-right') ?></a></p>
                </div>
            <?php else: ?>
                <h2>Staff access request</h2>
                <p class="sub">For staff of <?= e(config('hospital')) ?>. Fields marked <span class="req">*</span> are required.</p>
                <?= fx_error_summary($f['errors']) ?>
                <form method="post" action="<?= e(url('register.php')) ?>" class="fx-form" novalidate>
                    <?= csrf_field() ?><?= fx_trap() ?>
                    <p class="fx-section">About you</p>
                    <?= fx_field($f, 'full_name', 'Full name', ['icon' => 'user', 'required' => true, 'maxlength' => 120, 'autocomplete' => 'name', 'hint' => 'As it appears on your staff identity card.']) ?>
                    <div class="fx-row">
                        <?= fx_field($f, 'email', 'Email address', ['type' => 'email', 'icon' => 'mail', 'required' => true, 'maxlength' => 160, 'autocomplete' => 'email', 'placeholder' => 'name@example.com']) ?>
                        <?= fx_field($f, 'phone', 'Mobile phone', ['type' => 'tel', 'icon' => 'phone', 'maxlength' => 20, 'autocomplete' => 'tel', 'inputmode' => 'tel', 'placeholder' => '0712 345 678']) ?>
                    </div>
                    <p class="fx-section">Your work</p>
                    <div class="fx-row">
                        <?= fx_field($f, 'department', 'Department', ['type' => 'select', 'icon' => 'building', 'required' => true, 'options' => array_combine(request_departments(), request_departments()), 'empty' => 'Choose your department']) ?>
                        <?= fx_field($f, 'requested_role', 'Role you need', ['type' => 'select', 'icon' => 'badge-check', 'required' => true, 'options' => requestable_roles(), 'empty' => 'Choose your role']) ?>
                    </div>
                    <div class="fx-row">
                        <?= fx_field($f, 'staff_number', 'Staff number', ['icon' => 'file-text', 'maxlength' => 40, 'hint' => 'If you have one.']) ?>
                        <?= fx_field($f, 'supervisor', 'Supervisor or head of department', ['icon' => 'users', 'required' => true, 'maxlength' => 120]) ?>
                    </div>
                    <?= fx_field($f, 'message', 'Anything else the administrator should know', ['type' => 'textarea', 'icon' => 'info', 'maxlength' => 500, 'count' => 500, 'rows' => 4, 'hint' => 'For example, the date you start. Please do not include patient information.']) ?>
                    <?= fx_check($f, 'consent', 'I have read the <a href="' . e(url('privacy.php')) . '">privacy policy</a> and the <a href="' . e(url('terms.php')) . '">terms of use</a>, and I will keep my account private.') ?>
                    <div class="fx-submit">
                        <button class="btn btn-accent btn-xl" type="submit">Send request<?= icon('send') ?></button>
                        <p class="fx-small">Already have an account? <a href="<?= e(url('login.php')) ?>">Sign in</a></p>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php site_end();
