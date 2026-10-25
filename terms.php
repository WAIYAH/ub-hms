<?php
/** Terms of use. Public. */
require __DIR__ . '/includes/bootstrap.php';

$c = site_contact();
$toc = [
    ['about', 'What these terms cover'], ['accounts', 'Your account'], ['use', 'How you may use UB-HMS'], ['never', 'What you must not do'],
    ['watch', 'Monitoring and the audit trail'], ['public', 'This website'], ['available', 'Availability'], ['ownership', 'Ownership and pictures'], ['law', 'Law and changes'],
];
legal_start('terms', 'The rules for using UB-HMS and this website. By signing in, you agree to them.', $toc);
?>
<div class="callout"><?= icon('info') ?><p><strong>Please note.</strong> These terms were written for a diploma project and have not been reviewed by a lawyer. <?= e(config('hospital')) ?> should review them before live use.</p></div>

<h2 id="about">1. What these terms cover</h2>
<p>These terms apply to anyone who uses <?= e(config('app_name')) ?> (&ldquo;UB-HMS&rdquo;) or its public website. UB-HMS is for authorised staff of <?= e(config('hospital')) ?>. Patients do not use it.</p>

<h2 id="accounts">2. Your account</h2>
<ul>
    <li>Your account is <strong>personal</strong>. Never share your username or password, and never sign in as someone else.</li>
    <li>Accounts are created by the System Administrator after your supervisor confirms your role. You cannot create an account for yourself, and the request form does not create one.</li>
    <li>You receive a one-time password and must choose your own at first sign-in. Use a long password that you use nowhere else.</li>
    <li>Sign out when you leave a computer. The system also signs you out after 30 idle minutes.</li>
    <li>Tell the administrator at once if you think someone else knows your password or has used your account.</li>
</ul>

<h2 id="use">3. How you may use UB-HMS</h2>
<ul>
    <li>Use UB-HMS only for your work at the hospital, and only for the patients you are caring for or serving.</li>
    <li>Your role decides what you can see and do. If you need more access for your work, ask your supervisor to request it. Do not try to get around it.</li>
    <li>Keep what you see confidential, on screen and in print. Do not copy patient information to personal devices, messages or social media.</li>
    <li>Enter information carefully and truthfully. Correct mistakes through the screens provided.</li>
</ul>

<h2 id="never">4. What you must not do</h2>
<ul>
    <li>Look up a patient, relative or colleague out of curiosity or for a reason not connected with your work.</li>
    <li>Try to break in, test the system's security, or use anyone else's account.</li>
    <li>Upload or enter harmful code, or use the system to harass anyone.</li>
    <li>Use real patient information in the public contact or request forms.</li>
</ul>
<p>Breaking these rules may lead to your account being switched off and to action under the hospital's rules and the law, including the Data Protection Act, 2019.</p>

<h2 id="watch">5. Monitoring and the audit trail</h2>
<p>UB-HMS records every sign-in and failed sign-in, and every change, view, payment and export, with the person, the time and the computer's address. The hospital manager and the administrator can read this record. It is how the hospital protects patients and also protects honest staff.</p>

<h2 id="public">6. This website</h2>
<ul>
    <li>The public pages give general information about the system. They are not medical advice. <strong>In an emergency, go to the nearest emergency department or call 999 or 112.</strong></li>
    <li>Messages sent through the contact form are read by the administrator. We try to answer within a few working days, but this is not guaranteed.</li>
    <li>We try to keep the information accurate. The contact details shown are placeholders until the hospital sets its own.</li>
</ul>

<h2 id="available">7. Availability</h2>
<p>UB-HMS runs on a server inside the hospital. We back up the data, but the system is provided as it is, and the hospital should keep a paper procedure for times when it is not available. We are not liable for loss caused by the system being unavailable.</p>

<h2 id="ownership">8. Ownership and pictures</h2>
<p>UB-HMS was developed by Susan Wanjiku Waweru as a KCA University diploma project, supervised by David Oloo Ouma. The records held in it belong to the hospital and its patients. The photographs on this website are free stock images used under the Pexels licence (see <a href="<?= e(url('about.php')) ?>#credits">image credits</a>) and do not show our staff or patients.</p>

<h2 id="law">9. Law and changes</h2>
<p>These terms are governed by the laws of Kenya. We may change them, and will show the date at the top of the page. If you keep using UB-HMS after a change, you accept the new terms. Questions? <a href="<?= e(url('contact.php')) ?>">Contact us</a> or write to <a href="mailto:<?= e($c['email']) ?>"><?= e($c['email']) ?></a>.</p>
<?php legal_end();
