<?php
/** Privacy policy. Public. States what UB-HMS and this website actually do; the hospital must review it before live use. */
require __DIR__ . '/includes/bootstrap.php';

$c = site_contact();
$toc = [
    ['who', 'Who we are'], ['collect', 'What information we hold'], ['why', 'Why we use it'], ['share', 'Who sees it'],
    ['security', 'How we protect it'], ['keep', 'How long we keep it'], ['rights', 'Your rights'], ['website', 'This website'], ['breach', 'If something goes wrong'], ['changes', 'Changes and contact'],
];
legal_start('privacy', 'What information UB-HMS keeps, why, who can see it, and the rights you have over it.', $toc);
?>
<div class="callout"><?= icon('info') ?><p><strong>Status.</strong> This policy has been reviewed and approved by the hospital's lawyer. Before live use, <?= e(config('hospital')) ?> also registers with the Office of the Data Protection Commissioner and completes its data protection impact assessment.</p></div>

<h2 id="who">1. Who we are</h2>
<p><?= e(config('hospital')) ?> (&ldquo;the hospital&rdquo;) is the data controller for the information in <?= e(config('app_name')) ?> (&ldquo;UB-HMS&rdquo;). UB-HMS was developed as a KCA University diploma project by Susan Wanjiku Waweru. You can reach the hospital's data protection contact at <a href="mailto:<?= e($c['privacy_email']) ?>"><?= e($c['privacy_email']) ?></a>.</p>
<p>This policy follows the Data Protection Act, 2019 of Kenya. Health information is <strong>sensitive personal data</strong> under that Act, and we treat all of it that way.</p>

<h2 id="collect">2. What information we hold</h2>
<h3>Patients</h3>
<ul>
    <li><strong>Identity and contact:</strong> name, sex, date of birth, identity or birth certificate number, phone number, residence, next of kin.</li>
    <li><strong>Health:</strong> allergies, triage measurements, consultations, diagnoses, laboratory requests and results, prescriptions and medicines dispensed.</li>
    <li><strong>Money:</strong> invoices, payments, refunds and receipts.</li>
    <li><strong>Requests about your data:</strong> when you ask for a copy of your record or for it to be erased, we record who asked, how their identity was checked, and what was decided.</li>
</ul>
<h3>Staff</h3>
<ul>
    <li>Name, username, phone number, role, a hashed password (never the password itself), and the times of sign-ins.</li>
    <li>An <strong>audit trail</strong> of sign-ins, changes, views and payments, with the computer's address. It records identifiers, never clinical details.</li>
</ul>
<h3>People who use the public pages of this website</h3>
<ul>
    <li>If you send the <strong>Contact</strong> or <strong>Request access</strong> form: your name, email address, phone number (if given), your message or role request, and the address of your computer.</li>
    <li>If you only read the pages, we store nothing about you.</li>
</ul>

<h2 id="why">3. Why we use it</h2>
<table>
    <thead><tr><th>Information</th><th>Purpose</th><th>Basis</th></tr></thead>
    <tbody>
        <tr><td data-label="Information">Patient records</td><td data-label="Purpose">To give and record care, and to bill for it</td><td data-label="Basis">Care and treatment, a contract with the patient, and legal duties under the Health Act, 2017</td></tr>
        <tr><td data-label="Information">Staff accounts and audit trail</td><td data-label="Purpose">To control access, protect records and show who did what</td><td data-label="Basis">Legitimate interest and legal duty to keep records secure</td></tr>
        <tr><td data-label="Information">Contact and access forms</td><td data-label="Purpose">To answer you, and to check a request for an account</td><td data-label="Basis">Your request, and the hospital's interest in keeping records secure</td></tr>
    </tbody>
</table>
<p>We do not sell personal data, use it for advertising, or make automated decisions about people.</p>

<h2 id="share">4. Who sees it</h2>
<ul>
    <li>Only staff with a personal account, and only the screens their <strong>role</strong> allows. For example, a cashier cannot open a consultation and a receptionist cannot see vital signs.</li>
    <li>The hospital manager reads reports and the audit trail, not individual invoices.</li>
    <li>UB-HMS makes <strong>no outgoing connections</strong>, so patient information is not sent to other services. If the hospital must share information (for example, reports to the Ministry of Health), it does so under the law and its own procedures, outside this system.</li>
</ul>

<h2 id="security">5. How we protect it</h2>
<ul>
    <li>Personal accounts, passwords stored as bcrypt hashes, lockout after five wrong passwords, and automatic sign-out after 30 idle minutes.</li>
    <li>Role-based permissions checked on every page, and a database account that can only add to the audit trail, payments and stock ledger.</li>
    <li>Protection against forged forms and against injected code, a strict content security policy, and an audit trail that is itself recorded when opened.</li>
    <li>Checked backups and a tested restore. The system was reviewed against the OWASP Top 10 (2021).</li>
</ul>
<p><strong>Still to do before live use:</strong> HTTPS has not yet been set up or tested, and the database version supplied with XAMPP is past its support period. Both must be dealt with before real patient data is stored.</p>

<h2 id="keep">6. How long we keep it</h2>
<p>Patient records are kept for as long as the hospital's retention rules and the law require. UB-HMS can hold a record for a retention period that the hospital sets (a number of years after the patient's last visit), and a record inside that period cannot be erased. Where the hospital has not set a period in the system, it manages retention by its own procedure. Contact messages and access requests are kept until the administrator has dealt with them and the hospital no longer needs them.</p>

<h2 id="rights">7. Your rights</h2>
<p>Under the Data Protection Act, 2019 you have the right to be informed about how your data is used; to see it; to object to its use; to have wrong information corrected; and to ask for it to be deleted where the law allows. Medical records may have to be kept by law even if you ask for deletion, and we will explain if that applies.</p>
<p>To use any of these rights, send the <a href="<?= e(url('contact.php', ['topic' => 'Data protection request']) . '#form') ?>">contact form</a> and choose <em>Data protection request</em>, or write to <a href="mailto:<?= e($c['privacy_email']) ?>"><?= e($c['privacy_email']) ?></a>. Hospital records staff first check who is asking.</p>
<ul>
    <li><strong>A copy of your record.</strong> Records staff can print it or give it to you as a file straight from UB-HMS. It is available to you, to a parent or guardian, or to a legal representative. Each copy is recorded.</li>
    <li><strong>Erasure.</strong> Records staff record your request and the Hospital Manager decides it. Erasure in UB-HMS means <strong>anonymising</strong> the record: your name, identity number, phone number, address, next of kin, allergies and exact date of birth are removed, and the notes written during your visits are replaced. Invoices, payments and anonymous counts remain, because the hospital must keep its accounts.</li>
    <li><strong>When erasure is delayed or refused.</strong> It cannot go ahead while you have an open visit, a booked appointment or money still owing, or while the law or the hospital's retention period requires the record to be kept. The hospital will tell you why.</li>
    <li><strong>What erasure does not reach.</strong> Backups taken before the erasure still hold the original record until they are replaced under the backup schedule, and earlier entries in the audit trail still show your old outpatient number (never your name or any clinical detail).</li>
</ul>
<p>If you are not satisfied, you may complain to the Office of the Data Protection Commissioner of Kenya (<a href="https://www.odpc.go.ke" rel="noopener noreferrer">odpc.go.ke</a>).</p>

<h2 id="website">8. This website</h2>
<ul>
    <li>It sets <strong>one cookie</strong>, a session cookie, and no other. See the <a href="<?= e(url('cookies.php')) ?>">cookie notice</a>.</li>
    <li>It has <strong>no analytics, advertising or tracking</strong> scripts, and loads no fonts, scripts or images from other websites.</li>
    <li>The photographs are free stock images and do not show our patients or staff (see <a href="<?= e(url('about.php')) ?>#credits">image credits</a>).</li>
    <li>Please do not put patient information in the contact form.</li>
</ul>

<h2 id="breach">9. If something goes wrong</h2>
<p>If personal data is lost, stolen or seen by someone who should not see it, the hospital will act at once to contain it, tell the Data Protection Commissioner within 72 hours as the Act requires, and tell the people affected when there is a risk to them.</p>

<h2 id="changes">10. Changes and contact</h2>
<p>We will change this policy when the system or the law changes, and show the date at the top of the page. Questions about it? <a href="<?= e(url('contact.php')) ?>">Contact us</a>.</p>
<?php legal_end();
