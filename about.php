<?php
/** About page: what UB-HMS is, how it was built, the team, the questions people ask, and the picture credits. Public; no database queries. */
require __DIR__ . '/includes/bootstrap.php';

site_start('About', 'about', ['description' => 'About UB-HMS: the hospital management system built for Uhai Bora Hospital in Nairobi as a KCA University diploma project.']);
echo site_banner('about', 'About UB-HMS', 'Built for one hospital, built with care', config('app_name') . ' replaces paper files and separate registers with one secure record that every department can trust.', [['Home', 'welcome.php'], ['About', null]]);

$principles = [
    ['lock', 'Private by design', 'Personal accounts, role-based access and an audit trail are part of the system from the first page, not added later.'],
    ['users', 'Least privilege', 'Each of the eight roles sees only the screens it needs. A cashier cannot open a consultation and a doctor cannot dispense.'],
    ['clipboard', 'Accountable', 'Every sign-in, change, view and payment is recorded with who, what, when and from which computer.'],
    ['globe', 'Simple and local', 'It runs on a standard XAMPP server inside the hospital, with no outside fonts, scripts or trackers to patch or lose.'],
];
$faq = [
    ['What is UB-HMS?', 'UB-HMS is a web-based hospital management system for ' . config('hospital') . '. It brings registration, appointments, consultation, laboratory, pharmacy, billing and reports into one secure record.'],
    ['Who built it, and why?', 'It is the Diploma in Information Technology project of Susan Wanjiku Waweru at KCA University, supervised by David Oloo Ouma. It was built to answer the problems of paper files: slow retrieval, lost results, late stock alerts, missed charges and no record of who changed a file.'],
    ['Has the hospital started using it?', 'Not yet. UB-HMS has been built and tested with made-up (synthetic) data. No hospital staff have used it, so user acceptance testing and training are still to be done before live use.'],
    ['Who can use it?', 'Only staff with a personal account. Patients do not use the system. Staff can ask for an account on the <a href="' . e(url('register.php')) . '">Request access</a> page; the System Administrator creates it after checking with the supervisor.'],
    ['Does it need the internet?', 'No. It runs on a server inside the hospital and is reached from a browser on the hospital network.'],
    ['What data does this website collect?', 'The public pages collect nothing about you unless you send a form. The one cookie is a session cookie. See the <a href="' . e(url('privacy.php')) . '">privacy policy</a> and the <a href="' . e(url('cookies.php')) . '">cookie notice</a>.'],
    ['How do I report a problem or ask for my data?', 'Use the <a href="' . e(url('contact.php')) . '#form">contact form</a> and choose the topic. For data protection requests choose <em>Data protection request</em>.'],
];
$next = [
    ['User acceptance testing', 'Staff from every department use the system and tell us what to change.'],
    ['HTTPS and a current database', 'Set up encryption on the network and move to a supported MariaDB before real patient data is stored.'],
    ['Patient data rights', 'A printable copy of a patient\'s data, retention periods and archiving of old audit entries.'],
    ['Refunds and doctor schedules', 'A manager-approved refund, and clinic hours and leave for each doctor.'],
];
?>
<section class="sec">
    <div class="wrap split">
        <div class="split-media reveal">
            <figure class="frame"><img src="<?= e(asset('img/site/reception.webp')) ?>" width="1200" height="800" loading="lazy" alt="A receptionist with a tablet helps a visitor at a clinic reception desk"></figure>
            <div class="float-card glass-light float-tl"><?= icon('map-pin') ?><span><?= e(config('location')) ?></span></div>
            <div class="float-card glass-light float-br"><?= icon('badge-check') ?><span>Covered by automated tests</span></div>
        </div>
        <div class="split-copy reveal">
            <p class="eyebrow">Our purpose</p>
            <h2>One record, instead of five registers</h2>
            <p>At <?= e(config('hospital')) ?>, a patient's story used to be spread across paper files and separate registers in reception, the clinic, the laboratory, the pharmacy and the cashier's office. That made it slow to find a file, easy to lose a result, and hard to know who had seen or changed what.</p>
            <p><?= e(config('short_name')) ?> keeps all of it in one secure database. Each person sees only what their job needs, and every action leaves a trace.</p>
            <ul class="used-by"><li>Registration</li><li>Clinic</li><li>Laboratory</li><li>Pharmacy</li><li>Billing</li><li>Management</li></ul>
        </div>
    </div>
</section>

<section class="dark-sec" aria-labelledby="h-facts">
    <div class="wrap">
        <div class="sec-head reveal">
            <p class="eyebrow">By the numbers</p>
            <h2 id="h-facts">What has been built</h2>
        </div>
        <ul class="facts">
            <li class="glass reveal"><strong>6</strong><span>modules</span></li>
            <li class="glass reveal"><strong>8</strong><span>staff roles</span></li>
            <li class="glass reveal"><strong>23</strong><span>database tables</span></li>
            <li class="glass reveal"><strong>5</strong><span>development sprints</span></li>
        </ul>
        <p class="fine">Built with PHP 8.2, MariaDB and Apache (XAMPP), and a plain HTML5, CSS3 and JavaScript front end. Developed in five Scrum sprints (Schwaber &amp; Sutherland, 2020).</p>
    </div>
</section>

<section class="sec" aria-labelledby="h-principles">
    <div class="wrap">
        <div class="sec-head reveal">
            <p class="eyebrow">What we stand for</p>
            <h2 id="h-principles">Four principles behind every screen</h2>
        </div>
        <div class="cards cards-4">
            <?php foreach ($principles as [$ic, $t, $d]): ?>
                <article class="fx-card reveal"><span class="fx-ic"><?= icon($ic) ?></span><h3><?= e($t) ?></h3><p><?= e($d) ?></p></article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="sec sec-tint" aria-labelledby="h-team">
    <div class="wrap">
        <div class="sec-head reveal">
            <p class="eyebrow">The people</p>
            <h2 id="h-team">Who is behind it</h2>
        </div>
        <div class="cards cards-3">
            <article class="fx-card person reveal"><span class="avatar" aria-hidden="true">SW</span><div><h3>Susan Wanjiku Waweru</h3><p>Developer and author. Diploma in Information Technology, KCA University (25/04035).</p></div></article>
            <article class="fx-card person reveal"><span class="avatar" aria-hidden="true">DO</span><div><h3>David Oloo Ouma</h3><p>Project supervisor, KCA University.</p></div></article>
            <article class="fx-card person reveal"><span class="avatar" aria-hidden="true"><?= icon('building') ?></span><div><h3><?= e(config('hospital')) ?></h3><p>The hospital this system is designed for, in <?= e(config('location')) ?>.</p></div></article>
        </div>
    </div>
</section>

<section class="sec" aria-labelledby="h-next">
    <div class="wrap">
        <div class="sec-head reveal">
            <p class="eyebrow">Where the project stands</p>
            <h2 id="h-next">Built and tested, with honest next steps</h2>
            <p class="sec-sub">UB-HMS has passed its automated tests. Before real patient data is stored, these steps come first.</p>
        </div>
        <div class="cards cards-4">
            <?php foreach ($next as $i => [$t, $d]): ?>
                <article class="fx-card reveal"><span class="svc-num"><?= $i + 1 ?></span><h3><?= e($t) ?></h3><p><?= e($d) ?></p></article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="sec sec-tint" id="faq" aria-labelledby="h-faq">
    <div class="wrap">
        <div class="sec-head reveal">
            <p class="eyebrow">Questions</p>
            <h2 id="h-faq">Frequently asked questions</h2>
        </div>
        <div class="faq">
            <?php foreach ($faq as [$q, $a]): ?>
                <details class="reveal"><summary><?= e($q) ?></summary><div class="faq-a"><p><?= $a /* trusted text written in this file */ ?></p></div></details>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="sec" id="credits" aria-labelledby="h-credits">
    <div class="wrap">
        <div class="sec-head reveal">
            <p class="eyebrow">Image credits</p>
            <h2 id="h-credits">Photographs</h2>
            <p class="sec-sub">The photographs on this website are free stock images from Pexels, used under the Pexels licence. They do not show staff or patients of <?= e(config('hospital')) ?>. Screens of the system show made-up demonstration data.</p>
        </div>
        <div class="table-wrap reveal"><table class="credits">
            <thead><tr><th scope="col">Image</th><th scope="col">Photographer</th><th scope="col">Source</th></tr></thead>
            <tbody>
            <?php foreach (photo_credits() as $what => [$who, $id]): ?>
                <tr><td><?= e($what) ?></td><td><?= e($who) ?></td><td><a href="https://www.pexels.com/photo/<?= (int) $id ?>/" rel="noopener noreferrer">pexels.com/photo/<?= (int) $id ?></a></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</section>

<?= site_cta('Talk to us', 'Questions about UB-HMS, or want to see it working?', ['Contact us', 'contact.php'], ['Staff sign in', 'login.php']) ?>
<?php site_end();
