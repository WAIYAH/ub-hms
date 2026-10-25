<?php
/** Services page: the six modules in detail. Public; no patient data and no database queries. */
require __DIR__ . '/includes/bootstrap.php';

site_start('Services', 'services', ['description' => 'The six modules of UB-HMS: registration and appointments, clinical consultation, laboratory, pharmacy and stock, billing and reports, and users and security.']);
echo site_banner('services', 'Services', 'Everything a hospital day needs, in one system', 'Six modules that share one patient record, from the first appointment to the last report.', [['Home', 'welcome.php'], ['Services', null]]);

// slug => [lead, points, image, image alt, kind (photo|mock), used by]
$detail = [
    'registration' => [
        'A patient is registered once, with a unique outpatient number, and every later visit, test, medicine and payment is attached to that number.',
        ['Duplicate check by name, date of birth and phone number before saving', 'Search by name, number or phone, and correct records with a recorded reason', 'Import of old registers from a CSV file for the move from paper', 'Appointment diary with clash-free slots that follow each doctor\'s hours and leave, and attended, missed and cancelled marks'],
        'img/site/laptop-register.webp', 'The patient registration form of UB-HMS on a laptop', 'mock', ['Reception', 'Records'],
    ],
    'consultation' => [
        'The nurse records triage, then the doctor sees the vital signs, earlier visits and allergies on one screen and records the visit.',
        ['Nurse triage with temperature, pulse, blood pressure, oxygen and weight', 'Earlier visits and allergies in view during the consultation', 'Diagnosis in words, with an ICD-10 code if you want one', 'Test requests and prescriptions sent from the same screen'],
        'img/site/nurse-tablet.webp', 'A nurse in green scrubs and a mask reads a tablet', 'photo', ['Nurses', 'Doctors', 'Clinical officers'],
    ],
    'laboratory' => [
        'Test requests go to the laboratory worklist, and each result returns to the doctor who asked for it, so nothing is carried on paper.',
        ['A priced list of tests the doctor can tick', 'A worklist for the technologist, newest requests first', 'Results with a normal or abnormal flag, shown beside the consultation', 'A report of laboratory workload over time'],
        'img/site/lab-scientist.webp', 'A laboratory technologist in a white coat and gloves works with a flask', 'photo', ['Laboratory', 'Doctors'],
    ],
    'pharmacy' => [
        'Prescriptions appear on the pharmacist\'s screen, and stock leaves the batch that expires first, never an expired one.',
        ['Batch stock with expiry dates and a stock ledger', 'Dispensing that deducts stock automatically', 'Low-stock and expiring-soon alerts on the dashboard', 'Receive new batches, adjust stock and manage the medicine list'],
        'img/site/pharmacy.webp', 'Two people choosing medicine at a pharmacy shelf', 'photo', ['Pharmacy', 'Doctors', 'Managers'],
    ],
    'billing' => [
        'The invoice is built from the services, tests and medicines already recorded, so no charge is missed and no total is added by hand.',
        ['Itemised invoices with a balance that updates itself', 'Full and part payments by cash, M-Pesa, card or insurance', 'A printable receipt for every payment, and refunds that a second person must approve', 'Attendance, revenue, stock and laboratory reports, with CSV download'],
        'img/site/laptop-billing.webp', 'An itemised UB-HMS invoice with a part payment on a laptop', 'mock', ['Cashiers', 'Managers'],
    ],
    'security' => [
        'Every person has a personal account and sees only the screens their role needs. Every sign-in and change is recorded.',
        ['Eight roles with permissions defined in one place', 'Lockout after five wrong passwords and automatic sign-out when idle', 'Passwords stored as bcrypt hashes; forms protected against forgery', 'An audit trail the manager and administrator can search and read, and a recorded process for a patient\'s copy or erasure request'],
        'img/site/laptop-audit.webp', 'The UB-HMS audit trail listing sign-ins and changes on a laptop', 'mock', ['Administrators', 'Managers'],
    ],
];
$float = [
    'registration' => ['badge-check', 'Duplicate check'],
    'consultation' => ['clipboard', 'Vitals in view'],
    'laboratory'   => ['flask', 'Results to the doctor'],
    'pharmacy'     => ['clock', 'First expiry first'],
    'billing'      => ['receipt', 'Receipt every payment'],
    'security'     => ['lock', 'Every action recorded'],
];
?>
<nav class="subnav" aria-label="Jump to a service"><div class="wrap"><ul>
    <?php foreach (site_modules() as $slug => [$ic, $name]): ?>
        <li><a href="#<?= e($slug) ?>"><?= icon($ic) ?><?= e($name) ?></a></li>
    <?php endforeach; ?>
</ul></div></nav>

<div class="sec sec-flush">
<?php $n = 0; foreach (site_modules() as $slug => [$ic, $name]): $n++; [$lead, $points, $img, $alt, $kind, $usedBy] = $detail[$slug]; [$fic, $ftext] = $float[$slug]; ?>
    <section class="svc" id="<?= e($slug) ?>" aria-labelledby="h-<?= e($slug) ?>">
        <div class="wrap split">
            <div class="split-copy reveal">
                <span class="svc-num"><?= $n ?></span>
                <h2 id="h-<?= e($slug) ?>"><?= e($name) ?></h2>
                <p><?= e($lead) ?></p>
                <ul class="checks">
                    <?php foreach ($points as $pt): ?><li><?= icon('check') ?><span><?= e($pt) ?></span></li><?php endforeach; ?>
                </ul>
                <ul class="used-by" aria-label="Used by"><?php foreach ($usedBy as $u): ?><li><?= e($u) ?></li><?php endforeach; ?></ul>
            </div>
            <div class="split-media reveal">
                <?php if ($kind === 'photo'): ?>
                    <figure class="frame"><img src="<?= e(asset($img)) ?>" width="1200" height="800" loading="lazy" alt="<?= e($alt) ?>"></figure>
                <?php else: ?>
                    <figure class="frame mockframe"><img src="<?= e(asset($img)) ?>" width="1100" height="743" loading="lazy" alt="<?= e($alt) ?>"></figure>
                <?php endif; ?>
                <div class="float-card glass-light float-br"><?= icon($fic) ?><span><?= e($ftext) ?></span></div>
            </div>
        </div>
    </section>
<?php endforeach; ?>
</div>

<section class="sec sec-tint" aria-labelledby="h-visit">
    <div class="wrap">
        <div class="sec-head reveal">
            <p class="eyebrow">How the modules connect</p>
            <h2 id="h-visit">One patient visit, from front desk to report</h2>
            <p class="sec-sub">No module is an island: each step hands the patient on to the next one.</p>
        </div>
        <ol class="tl">
            <?php foreach ([
                ['Reception', 'Register and book', 'One outpatient number and a clash-free diary.'],
                ['Nurse', 'Triage', 'Vital signs recorded before the doctor.'],
                ['Doctor', 'Consult', 'Diagnosis, test requests and prescriptions.'],
                ['Lab and pharmacy', 'Test and dispense', 'Results return; stock leaves by batch.'],
                ['Cashier', 'Invoice and pay', 'Built from recorded services; a receipt for each payment.'],
                ['Manager', 'Report', 'Attendance, revenue, stock and laboratory reports.'],
            ] as $i => [$who, $step, $what]): ?>
                <li class="reveal"><span class="tl-num" aria-hidden="true"><?= $i + 1 ?></span><p class="tl-who"><?= e($who) ?></p><h3><?= e($step) ?></h3><p><?= e($what) ?></p></li>
            <?php endforeach; ?>
        </ol>
    </div>
</section>

<section class="sec" aria-labelledby="h-more">
    <div class="wrap">
        <div class="sec-head reveal">
            <p class="eyebrow">Built in</p>
            <h2 id="h-more">Also included</h2>
        </div>
        <div class="cards cards-3">
            <?php foreach ([
                ['clipboard', 'System health and backups', 'A health page for the administrator, and scripts that make a checked backup and restore it.'],
                ['accessibility', 'Made to be read by everyone', 'Labels on every field, text statuses beside colour, and a layout that works on a phone.'],
                ['globe', 'Runs inside the hospital', 'A standard XAMPP server on the hospital network. No outside fonts, scripts or trackers.'],
            ] as [$ic, $t, $d]): ?>
                <article class="fx-card reveal"><span class="fx-ic"><?= icon($ic) ?></span><h3><?= e($t) ?></h3><p><?= e($d) ?></p></article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?= site_cta('See it for yourself', 'Sign in with your own account, or ask the administrator for one.', ['Staff sign in', 'login.php'], ['Request access', 'register.php']) ?>
<?php site_end();
