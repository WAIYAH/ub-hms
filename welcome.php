<?php
/**
 * Home page: the public front door of UB-HMS. It explains what the system does and leads to staff sign-in.
 * It shows no patient data and makes no database queries.
 */
require __DIR__ . '/includes/bootstrap.php';

// Staff who are already signed in go straight to their dashboard.
if (current_user()) {
    redirect('index.php');
}

site_start('Home', 'home', ['description' => 'UB-HMS is the hospital management system of Uhai Bora Hospital, Nairobi: one secure record for reception, clinic, laboratory, pharmacy and billing.']);

$signin = url('login.php');
$modules = [
    'registration' => ['Register a patient once and book without clashes.', ['One outpatient number per patient', 'Duplicate check before saving', 'Diary with attended, missed and cancelled']],
    'consultation' => ['Everything the doctor needs, in one view.', ['Nurse triage with vital signs', 'Earlier visits and allergies in view', 'Diagnosis in words, with an ICD-10 code']],
    'laboratory'   => ['Requests go out and results come back.', ['Priced test requests from the doctor', 'A worklist for the technologist', 'Result returns to the requesting doctor']],
    'pharmacy'     => ['Dispense safely, and never be surprised by stock.', ['Batch stock, first expiry first', 'Low-stock and expiry alerts', 'Receive stock and manage the medicine list']],
    'billing'      => ['Invoices are built from what was recorded.', ['Itemised invoices, part or full payment', 'Cash, M-Pesa, card or insurance', 'Attendance, revenue, stock and laboratory reports']],
    'security'     => ['The right access for every person.', ['Eight roles, personal accounts', 'Lockout and automatic sign-out', 'Audit trail of every sign-in and change']],
];
$steps = [
    ['Reception', 'Register and book', 'One outpatient number, duplicate check, clash-free diary.'],
    ['Nurse', 'Triage', 'Vital signs are recorded before the doctor sees the patient.'],
    ['Doctor', 'Consult', 'History in view; diagnosis, test requests and prescriptions.'],
    ['Lab and pharmacy', 'Test and dispense', 'Results return to the doctor; stock leaves by batch.'],
    ['Cashier', 'Invoice and pay', 'Invoice built from recorded services; receipt for every payment.'],
    ['Manager', 'Report', 'Attendance, revenue, stock and laboratory reports.'],
];
$roles = [
    ['settings', 'System Administrator', 'Manages accounts, prices and system health'],
    ['chart', 'Hospital Manager', 'Reads reports, stock alerts and the audit trail'],
    ['user-plus', 'Receptionist / Records', 'Registers patients, books and checks in'],
    ['heart', 'Nurse', 'Records triage and vital signs'],
    ['stethoscope', 'Doctor / Clinical Officer', 'Consults, diagnoses, requests tests, prescribes'],
    ['flask', 'Laboratory Technologist', 'Works the test list and enters results'],
    ['pill', 'Pharmacist', 'Dispenses prescriptions and controls stock'],
    ['receipt', 'Cashier / Billing Clerk', 'Builds invoices and takes payments'],
];
$safeguards = [
    'Personal accounts that are never shared',
    'Role-based access, checked on every page',
    'Passwords stored as bcrypt hashes',
    'Lockout after five wrong passwords; sign-out after 30 idle minutes',
    'Audit trail of sign-ins, changes, views and payments',
    'Forms protected against forgery; input and output protected against injection',
    'Backup and restore scripts with a checked restore',
];
$faq = [
    ['Who can use UB-HMS?', 'Only staff of the hospital, with a personal account created by the System Administrator. Patients do not use the system themselves.'],
    ['Does it need the internet?', 'No. UB-HMS runs on a server inside the hospital and staff reach it from a browser on the hospital network. It loads no outside fonts, scripts or trackers.'],
    ['How do I get an account?', 'Use <strong>Request access</strong> and give your department and supervisor. The administrator checks the request and creates the account; you receive a one-time password in person and choose your own at first sign-in.'],
    ['Is patient information safe?', 'The system was built with the Data Protection Act, 2019 in mind: personal accounts, role-based access, an audit trail and tested protections. The hospital must still complete its own data protection registration and impact assessment before live use.'],
    ['What if I forget my password?', 'Ask the System Administrator, who can give you a new one-time password. Passwords are never sent by email.'],
];
?>
<section class="hx">
    <span class="hx-orb hx-orb-1" aria-hidden="true"></span><span class="hx-orb hx-orb-2" aria-hidden="true"></span>
    <div class="wrap hx-grid">
        <div class="hx-copy">
            <p class="eyebrow hx-eyebrow"><?= e(config('hospital')) ?> &middot; <?= e(config('location')) ?></p>
            <h1>A hospital system built around one secure record</h1>
            <p class="hx-lead"><?= e(config('short_name')) ?> connects reception, clinic, laboratory, pharmacy and billing, so every member of staff sees what they need, and nothing more.</p>
            <div class="hx-cta">
                <a class="btn btn-accent btn-xl" href="<?= e($signin) ?>">Staff sign in<?= icon('arrow-right') ?></a>
                <a class="btn btn-ghost btn-xl" href="<?= e(url('register.php')) ?>">Request access</a>
            </div>
            <ul class="hx-chips">
                <li><?= icon('shield') ?>Role-based access</li>
                <li><?= icon('clipboard') ?>Full audit trail</li>
                <li><?= icon('globe') ?>Works in any modern browser</li>
            </ul>
        </div>
        <div class="hx-art">
            <img class="art-laptop" src="<?= e(asset('img/welcome-laptop-dashboard.webp')) ?>" width="1200" height="810" fetchpriority="high" alt="The hospital manager's dashboard of UB-HMS on a laptop, showing stock alerts and payments collected today">
            <img class="art-phone" src="<?= e(asset('img/welcome-phone.webp')) ?>" width="420" height="872" alt="The receptionist's dashboard of UB-HMS on a phone">
            <div class="hx-float hx-float-1 glass"><?= icon('flask') ?>Results go straight to the doctor</div>
            <div class="hx-float hx-float-2 glass"><?= icon('clipboard') ?>Every action is recorded</div>
        </div>
    </div>
    <?= ecg_svg() ?>
</section>

<section class="stats-wrap" aria-label="UB-HMS at a glance">
    <div class="wrap">
        <ul class="stats glass-light reveal">
            <li><strong>6</strong><span>modules</span></li>
            <li><strong>8</strong><span>staff roles</span></li>
            <li><strong>4</strong><span>ways to pay</span></li>
            <li><strong>4</strong><span>management reports</span></li>
        </ul>
    </div>
</section>

<section class="sec" id="features" aria-labelledby="h-features">
    <div class="wrap">
        <div class="sec-head reveal">
            <p class="eyebrow">What it does</p>
            <h2 id="h-features">Six modules, one connected record</h2>
            <p class="sec-sub">Each department works in its own screens, and everything lands in the same patient record.</p>
        </div>
        <div class="cards cards-3">
            <?php foreach (site_modules() as $slug => [$ic, $title]): [$line, $points] = $modules[$slug]; ?>
                <article class="fx-card reveal">
                    <span class="fx-ic"><?= icon($ic) ?></span>
                    <h3><?= e($title) ?></h3>
                    <p><?= e($line) ?></p>
                    <ul class="checks">
                        <?php foreach ($points as $pt): ?><li><?= icon('check') ?><span><?= e($pt) ?></span></li><?php endforeach; ?>
                    </ul>
                </article>
            <?php endforeach; ?>
        </div>
        <p class="mt"><a class="btn btn-dark btn-xl" href="<?= e(url('services.php')) ?>">Explore every service<?= icon('arrow-right') ?></a></p>
    </div>
</section>

<section class="sec sec-tint" aria-labelledby="h-people">
    <div class="wrap people">
        <div class="people-copy reveal">
            <p class="eyebrow">Made for the people who use it</p>
            <h2 id="h-people">Quick for the busy, clear for the careful</h2>
            <p>Doctors, nurses, technologists, pharmacists and cashiers each get a screen made for their own work, with plain words and large buttons, so a new colleague can learn it in a short session.</p>
            <ul class="checks">
                <li><?= icon('check') ?><span>Works on desktops, tablets and phones</span></li>
                <li><?= icon('check') ?><span>No file to carry and no result to chase</span></li>
                <li><?= icon('check') ?><span>Training plan and a safe, phased changeover from paper</span></li>
            </ul>
            <p class="photo-note">Photographs are stock images. They do not show staff of <?= e(config('hospital')) ?>.</p>
        </div>
        <div class="collage reveal">
            <figure><img src="<?= e(asset('img/site/doctor-portrait.webp')) ?>" width="900" height="1200" loading="lazy" alt="A smiling doctor in a white coat with a stethoscope"><figcaption class="glass">Doctors and clinical officers</figcaption></figure>
            <figure><img src="<?= e(asset('img/site/nurse-portrait.webp')) ?>" width="900" height="1200" loading="lazy" alt="A smiling nurse in green scrubs"><figcaption class="glass">Nurses</figcaption></figure>
            <figure><img src="<?= e(asset('img/site/scientist-portrait.webp')) ?>" width="900" height="1200" loading="lazy" alt="A laboratory technologist at a microscope"><figcaption class="glass">Laboratory technologists</figcaption></figure>
        </div>
    </div>
</section>

<section class="sec" id="visit" aria-labelledby="h-visit">
    <div class="wrap">
        <div class="sec-head reveal">
            <p class="eyebrow">How it fits together</p>
            <h2 id="h-visit">One patient visit, from front desk to report</h2>
            <p class="sec-sub">Each person completes their step and the next person sees it at once.</p>
        </div>
        <ol class="tl">
            <?php foreach ($steps as $i => [$who, $step, $what]): ?>
                <li class="reveal"><span class="tl-num" aria-hidden="true"><?= $i + 1 ?></span><p class="tl-who"><?= e($who) ?></p><h3><?= e($step) ?></h3><p><?= e($what) ?></p></li>
            <?php endforeach; ?>
        </ol>
    </div>
</section>

<section class="sec sec-tint" id="screens" aria-labelledby="h-screens">
    <div class="wrap">
        <div class="sec-head reveal">
            <p class="eyebrow">In practice</p>
            <h2 id="h-screens">See it working</h2>
            <p class="sec-sub">Plain, quick screens made for a busy clinic, and they fit a phone as well as a desktop.</p>
        </div>
        <div class="screens">
            <figure class="reveal"><img src="<?= e(asset('img/welcome-laptop-consult.webp')) ?>" width="1100" height="743" loading="lazy" alt="The consultation screen: patient banner, triage vital signs, earlier visits and the consultation form"><figcaption><strong>Consult</strong><span>Vitals, history and diagnosis in one view</span></figcaption></figure>
            <figure class="reveal"><img src="<?= e(asset('img/welcome-laptop-dispense.webp')) ?>" width="1100" height="743" loading="lazy" alt="The pharmacy screen for dispensing a prescription, with quantities prescribed, given and in stock"><figcaption><strong>Dispense</strong><span>Stock leaves the batch that expires first</span></figcaption></figure>
            <figure class="reveal"><img src="<?= e(asset('img/welcome-phone.webp')) ?>" width="420" height="872" loading="lazy" alt="The receptionist's dashboard on a phone-sized screen"><figcaption><strong>On a phone</strong><span>The menu folds away</span></figcaption></figure>
        </div>
        <p class="note">Screens show synthetic demonstration data. No real patient information is used.</p>
    </div>
</section>

<section class="sec" id="roles" aria-labelledby="h-roles">
    <div class="wrap">
        <div class="sec-head reveal">
            <p class="eyebrow">Who uses it</p>
            <h2 id="h-roles">Eight roles, each with only what it needs</h2>
            <p class="sec-sub">The menu and the dashboard are fixed for each role, so everyone in the same role sees the same system.</p>
        </div>
        <ul class="roles">
            <?php foreach ($roles as [$ic, $role, $does]): ?>
                <li class="reveal"><span class="role-ic"><?= icon($ic) ?></span><strong><?= e($role) ?></strong><span><?= e($does) ?></span></li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>

<section class="dark-sec" id="security" aria-labelledby="h-security">
    <div class="wrap dark-grid">
        <div class="reveal">
            <p class="eyebrow">Privacy and security</p>
            <h2 id="h-security">Built to protect patient information</h2>
            <p class="sec-sub">Health information is sensitive. UB-HMS keeps it behind personal accounts, limits each role to what it needs, and records who did what.</p>
            <p class="fine">Designed with the Health Act, 2017, the Data Protection Act, 2019 and the Digital Health Act, 2023 in mind. Before live use the hospital completes its own data protection registration and impact assessment.</p>
            <p class="mt"><a class="btn btn-ghost btn-xl" href="<?= e(url('privacy.php')) ?>">Read the privacy policy<?= icon('arrow-right') ?></a></p>
        </div>
        <ul class="ticks reveal">
            <?php foreach ($safeguards as $s): ?><li><span class="tick"><?= icon('check') ?></span><span><?= e($s) ?></span></li><?php endforeach; ?>
        </ul>
    </div>
</section>

<section class="sec sec-tint" id="faq" aria-labelledby="h-faq">
    <div class="wrap">
        <div class="sec-head reveal">
            <p class="eyebrow">Questions</p>
            <h2 id="h-faq">Quick answers</h2>
        </div>
        <div class="faq">
            <?php foreach ($faq as [$q, $a]): ?>
                <details class="reveal"><summary><?= e($q) ?></summary><div class="faq-a"><p><?= $a /* trusted text written in this file */ ?></p></div></details>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?= site_cta('Ready to start your shift?', 'Sign in with your own account. Every sign-in and action is recorded in the audit trail.', ['Staff sign in', 'login.php'], ['Request access', 'register.php']) ?>
<?php site_end();
