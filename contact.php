<?php
/**
 * Contact page: details of the hospital and a message form. A message is stored for the System Administrator;
 * nothing is sent by email. Public; the form is protected by the CSRF token, a hidden trap field and an hourly limit.
 */
require __DIR__ . '/includes/bootstrap.php';

$c = site_contact();

if (is_post()) {
    require_post();
    $ip = client_ip();
    if (post_str('website', 100) !== '') {
        // A robot filled the trap field. Pretend it worked and store nothing.
        remember_public_done('contact', 'MSG-' . app_now()->format('Y') . '-000000');
        redirect('contact.php');
    }
    if (public_request_limit_reached('contact', $ip)) {
        remember_form(['full_name' => post_str('full_name', 120), 'email' => post_str('email', 160), 'topic' => post_str('topic', 40), 'message' => clean_multiline($_POST['message'] ?? '', 2000)],
            ['message' => 'Too many messages were sent from this computer in the last hour. Please try again later, or telephone us.']);
        redirect('contact.php');
    }
    [$clean, $errors] = validate_contact_message($_POST);
    if ($errors) {
        remember_form($clean, $errors);
        redirect('contact.php');
    }
    [, $reference] = create_public_request('contact', $clean, $ip);
    remember_public_done('contact', $reference);
    redirect('contact.php');
}

$form = take_form();
$done = take_public_done('contact');
$values = $form['values'] ?: ['topic' => in_array(get_str('topic', 40), contact_topics(), true) ? get_str('topic', 40) : ''];
$f = ['values' => $values, 'errors' => $form['errors']];

site_start('Contact', 'contact', ['description' => 'Contact the UB-HMS team at Uhai Bora Hospital: telephone, email, opening hours and a message form.']);
echo site_banner('contact', 'Contact', 'We are glad to help', 'Ask a question, report a problem or send feedback. A real person reads every message.', [['Home', 'welcome.php'], ['Contact', null]]);
?>
<section class="sec">
    <div class="wrap contact-grid">
        <div class="info-stack">
            <div class="info-card glass-light reveal"><span class="hd-ic"><?= icon('map-pin') ?></span><div><h2>Visit</h2><p><?= e($c['address']) ?></p></div></div>
            <div class="info-card glass-light reveal"><span class="hd-ic"><?= icon('phone') ?></span><div><h2>Telephone</h2><p><a href="tel:<?= e($c['tel']) ?>"><?= e($c['phone']) ?></a></p></div></div>
            <div class="info-card glass-light reveal"><span class="hd-ic"><?= icon('mail') ?></span><div><h2>Email</h2><p><a href="mailto:<?= e($c['email']) ?>"><?= e($c['email']) ?></a></p></div></div>
            <div class="info-card glass-light reveal"><span class="hd-ic"><?= icon('clock') ?></span><div><h2>Opening hours</h2><p><?= e($c['hours']) ?></p></div></div>
            <div class="info-card info-warn reveal"><span class="hd-ic"><?= icon('alert') ?></span><div><h2>In an emergency</h2><p>Do not use this page. Go to the nearest emergency department or call <strong>999</strong> or <strong>112</strong>.</p></div></div>
            <figure class="place reveal">
                <img src="<?= e(asset('img/site/nairobi-kicc.webp')) ?>" width="1920" height="760" loading="lazy" alt="A view over the Nairobi skyline, with skyscrapers and green spaces">
                <figcaption class="glass"><?= icon('map-pin') ?><?= e(config('location')) ?></figcaption>
            </figure>
            <?php if ($c['placeholder']): ?><p class="placeholder-note"><?= icon('info', 'icon note-icon') ?> The contact details on this site are placeholders for the demonstration. The hospital sets its own in <code>config/config.local.php</code>.</p><?php endif; ?>
        </div>

        <div class="fx-card fx-card-form reveal" id="form">
            <?php if ($done): ?>
                <div class="reg-done" role="status">
                    <span class="fx-ic"><?= icon('send') ?></span>
                    <h2>Thank you, your message is on its way</h2>
                    <p class="sub">The System Administrator reads new messages and will reply by email or telephone. Please keep this reference if you telephone us.</p>
                    <span class="reg-ref"><?= e($done['reference']) ?></span>
                    <p class="fx-small">Please do not send patient information through this form.</p>
                    <p class="mt"><a class="btn btn-dark btn-xl" href="<?= e(url('welcome.php')) ?>">Back to the home page<?= icon('arrow-right') ?></a></p>
                </div>
            <?php else: ?>
                <h2>Send us a message</h2>
                <p class="sub">We reply by email or telephone. Fields marked <span class="req">*</span> are required.</p>
                <?= fx_error_summary($f['errors']) ?>
                <form method="post" action="<?= e(url('contact.php')) ?>" class="fx-form" novalidate>
                    <?= csrf_field() ?><?= fx_trap() ?>
                    <div class="fx-row">
                        <?= fx_field($f, 'full_name', 'Your name', ['icon' => 'user', 'required' => true, 'maxlength' => 120, 'autocomplete' => 'name']) ?>
                        <?= fx_field($f, 'email', 'Email address', ['type' => 'email', 'icon' => 'mail', 'required' => true, 'maxlength' => 160, 'autocomplete' => 'email', 'placeholder' => 'name@example.com']) ?>
                    </div>
                    <div class="fx-row">
                        <?= fx_field($f, 'phone', 'Mobile phone', ['type' => 'tel', 'icon' => 'phone', 'maxlength' => 20, 'autocomplete' => 'tel', 'inputmode' => 'tel', 'placeholder' => '0712 345 678']) ?>
                        <?= fx_field($f, 'topic', 'What is it about?', ['type' => 'select', 'icon' => 'info', 'required' => true, 'options' => array_combine(contact_topics(), contact_topics()), 'empty' => 'Choose a topic']) ?>
                    </div>
                    <?= fx_field($f, 'message', 'Your message', ['type' => 'textarea', 'icon' => 'file-text', 'required' => true, 'maxlength' => 2000, 'count' => 2000, 'rows' => 6, 'hint' => 'Please do not include patient names, results or other health information.']) ?>
                    <?= fx_check($f, 'consent', 'I confirm my message contains no patient information, and I have read the <a href="' . e(url('privacy.php')) . '">privacy policy</a>.') ?>
                    <div class="fx-submit">
                        <button class="btn btn-accent btn-xl" type="submit">Send message<?= icon('send') ?></button>
                        <p class="fx-small">Your details are used only to reply to you.</p>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>
<?= site_cta('Already have an account?', 'Sign in to open your dashboard.', ['Staff sign in', 'login.php'], ['Request access', 'register.php']) ?>
<?php site_end();
