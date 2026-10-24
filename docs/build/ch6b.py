"""Chapter 6, part 2: user interface, development process, security, deployment, appendices (6.5 to 6.9)."""
import os

from doc_common import HERE, GLOSSARY, REFERENCES, fig, shot, shot_main, landscape_figure

SNIPPETS = [
    ('Permission check at the start of every page (includes/auth.php)', '''function require_permission(string $permission): array
{
    $u = require_login();
    if (!role_can($u['role'], $permission)) {
        audit('access_denied', null, null, 'Permission ' . $permission . ' on ' . ($_SERVER['SCRIPT_NAME'] ?? ''));
        abort(403, 'Your account does not have permission to open this page.');
    }
    return $u;
}'''),
    ('Sign-in: lockout without revealing which part was wrong (includes/auth.php, shortened)', '''$passwordOk = password_verify($password, $user['password_hash'] ?? TIMING_DUMMY_HASH);
if ($user && $user['is_locked']) {
    audit('login_failed', 'user', (int) $user['id'], 'Account is locked', (int) $user['id'], $ip);
    return ['ok' => false, 'error' => 'This account is temporarily locked ...'];
}
if (!$user || !$passwordOk) {
    if ($user) {
        // locked_until is assigned first: SET clauses are evaluated left to right
        db_exec('UPDATE users SET locked_until = IF(failed_attempts + 1 >= ?, NOW() + INTERVAL ? MINUTE, locked_until),
                        failed_attempts = failed_attempts + 1 WHERE id = ?', [$max, $minutes, $user['id']]);
    }
    return ['ok' => false, 'error' => 'The username or password is not correct.'];
}'''),
    ('Booking an appointment: one doctor, one slot (includes/appointments.php, shortened)', '''$id = db_tx(function () use ($patientId, $doctorId, $date, $start, $end, $reason, $userId): int {
    // Lock the doctor's row so two receptionists booking at once are handled one after the other.
    db_value('SELECT id FROM users WHERE id = ? FOR UPDATE', [$doctorId]);
    if (db_value("SELECT id FROM appointments WHERE doctor_id = ? AND appointment_date = ?
                  AND start_time = ? AND status IN ('Booked','Attended')", [$doctorId, $date, $start . ':00'])) {
        throw new RuntimeException('slot_taken');
    }
    $newId = db_insert('INSERT INTO appointments (patient_id, doctor_id, appointment_date, start_time, end_time, reason, booked_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?)', [...]);
    audit('create', 'appointment', $newId, "Booked $date $start", $userId);
    return $newId;
});'''),
    ('Dispensing: earliest expiry first, never below zero (includes/pharmacy.php, shortened)', '''$batches = db_all('SELECT * FROM medicine_batches WHERE medicine_id = ? AND quantity > 0 AND expiry_date >= ?
                   ORDER BY expiry_date, id FOR UPDATE', [$it['medicine_id'], $today]);
if (array_sum(array_column($batches, 'quantity')) < $qty) {
    throw new RuntimeException("dispense:$label: only $available in stock (expired stock cannot be dispensed).");
}
foreach ($batches as $b) {
    $take = min($need, (int) $b['quantity']);
    if (db_exec('UPDATE medicine_batches SET quantity = quantity - ? WHERE id = ? AND quantity >= ?', [$take, $b['id'], $take]) !== 1) {
        throw new RuntimeException("dispense:$label: stock changed while dispensing. Please try again.");
    }
    db_insert('INSERT INTO stock_movements (medicine_id, batch_id, type, quantity, reference, user_id) VALUES (?, ?, ?, ?, ?, ?)',
              [$it['medicine_id'], $b['id'], 'Dispense', -$take, 'Rx ' . $prescriptionId . ' item ' . $it['id'], $userId]);
    $need -= $take;
}'''),
    ('Invoice built from the records of the visit (includes/billing.php, shortened)', '''foreach ($lines as [$source, $sourceId, $description, $qty, $price]) {
    $existing = db_one('SELECT id FROM invoice_items WHERE invoice_id = ? AND source = ? AND source_id = ?', [$invoiceId, $source, $sourceId]);
    if (!$existing) {
        db_insert('INSERT INTO invoice_items (...) VALUES (?, ?, ?, ?, ?, ?, ROUND(? * ?, 2))', [...]);
    } elseif ($hasPayments) {
        // once money has been taken the agreed price stays; only the quantity can change
        db_exec('UPDATE invoice_items SET quantity = ?, line_total = ROUND(unit_price * ?, 2) WHERE id = ?', [$qty, $qty, $existing['id']]);
    } else {
        db_exec('UPDATE invoice_items SET description = ?, quantity = ?, unit_price = ?, line_total = ROUND(? * ?, 2) WHERE id = ?', [...]);
    }
}'''),
    ('Form token check (includes/csrf.php)', '''function csrf_valid(?string $submitted): bool
{
    $expected = $_SESSION['csrf'] ?? '';
    return is_string($submitted) && $expected !== '' && hash_equals($expected, $submitted);
}'''),
]

SPRINTS = [
    ['1', 'Authentication, eight roles and user accounts, audit trail; patient registration, search, correction, CSV import', 'be82998', '57 unit, 36 end-to-end'],
    ['2', 'Appointments, check-in and queues, triage, consultation, procedures', '2558ec0', '38 unit, 32 end-to-end'],
    ['3', 'Laboratory requests and results, prescriptions, dispensing, medicine list and stock', '8199cee', '33 unit, 36 end-to-end'],
    ['4', 'Itemised invoices, payments and receipts, four reports with CSV export', '6a78010', '28 unit, 28 end-to-end'],
    ['5', 'Integration of the modules, OWASP Top 10 review and corrections, price list, health page, backup and restore, least-privilege database account, Apache checks', '46f19fb', '11 unit, 53 end-to-end, 23 Apache'],
]

LOC = [
    ['Pages in the main folder (index, login, logout, password, error)', '5', '181'],
    ['Pages in patients/, appointments/, visits/, lab/', '16', '1,049'],
    ['Pages in pharmacy/, billing/, reports/, admin/', '21', '1,068'],
    ['includes/: business rules and shared code', '19', '4,040'],
    ['assets/: one stylesheet and one script', '2', '279'],
    ['database/: schema, installer, demonstration data, account script', '5', '1,119'],
    ['scripts/: backup and restore', '2', '131'],
    ['tests/: unit tests, end-to-end, Apache and performance checks', '17', '3,587'],
    ['Total', '87', '11,454'],
]


def build_ui(d):
    d.h2('6.5 User Interface Implementation')
    d.p('The interface is made of plain HTML5 pages built by PHP, one stylesheet (assets/css/app.css) and one small script (assets/js/app.js). The stylesheet defines the layout, forms, tables, status badges, the narrow-screen menu and the print layout. The script does only three things: it opens and closes the menu on narrow screens, asks for confirmation before actions marked as dangerous, and puts the cursor in the search box. Because the Content Security Policy allows only scripts from the system itself, there is no inline script anywhere (test E2E-243).')
    d.h3('6.5.1 Screen Descriptions')
    d.p('{ref:tab_screens6} describes the key screens. The full list of input and output screens with their data elements, and the screenshots, are in section 5.6 of Chapter 5.')
    d.table(['Screen', 'Purpose and content', 'Main user actions'], [
        ['Sign in', 'Username and password; the name of the hospital.', 'Type and press Sign in.'],
        ['Dashboard', 'A greeting, counts of work waiting for the role, alerts and a "What you can do" list.', 'Click a count or a menu item to go to the work.'],
        ['Search patients and Patient record', 'Search results with OP number, name, age and phone; the record with details, allergies, next of kin, appointments and visits.', 'Search, open a record, correct details, book an appointment or check in.'],
        ['Appointment diary and Book appointment', 'Day, doctor and status filters; the day\'s 27 slots shown as free, taken or past.', 'Choose date and doctor, click a free slot, save; cancel or mark missed.'],
        ['Queues (triage, doctor, open visits)', 'Arrival time, waiting time, patient with age and allergy flag, doctor, reason, stage.', 'Press Triage, Consult or View on a row.'],
        ['Triage form', 'Eight measurement fields and notes; patient banner with allergies.', 'Type values; save.'],
        ['Consultation page', 'Triage, allergies and earlier visits on one page; forms for consultation, tests, prescriptions and procedures; results received.', 'Save the consultation; tick tests; add prescription lines; close the visit.'],
        ['Laboratory worklist and Enter result', 'Requests waiting and completed with patient, test, requesting doctor and notes.', 'Press Start, then Enter result; choose the range flag.'],
        ['Prescription queue and Dispense', 'Prescriptions waiting; for each line the amount still to give and the usable stock.', 'Type quantities; press Dispense.'],
        ['Medicine stock, Batches, Receive stock, Medicine list', 'Usable stock and status; batches with expiry state and the ledger; delivery form; medicine form.', 'Receive a delivery; adjust stock with a reason; edit a medicine.'],
        ['Invoice list, Invoice, Receipt', 'Invoices with balance and status; itemised charges and payments; the numbered receipt.', 'Choose the method, amount and reference; Record payment; Print.'],
        ['Reports', 'Date range filter, summary tiles and tables.', 'Show report, Print, Download CSV.'],
        ['User accounts, Price list, Audit trail, System health', 'Accounts with status; prices; audit entries with filters; health checks as OK, Warning or Failed.', 'Create or deactivate accounts; change prices; filter; read.'],
    ], widths=[3.6, 7.3, 4.4], size=8.5, caption='Key Screens, Their Content and the Main Actions', label='tab_screens6')
    d.h3('6.5.2 Navigation Flow')
    d.p('Everyone starts at the sign-in page. A user who must change the password goes to the change-password page first and cannot reach any other page until it is done. After that the dashboard opens, and its left-hand menu lists only the pages the role may use, grouped by department. {ref:fig_screens6} shows the navigation. '
        'On a screen narrower than 860 pixels the menu is hidden behind a button at the top left. Links inside pages follow the work: from a diary entry to Check in, from a queue to Triage or Consultation, from a consultation to its tests and prescriptions, and from an invoice to its payment form and receipt.')
    landscape_figure(d, None, '', fig('screen_map'), 'Navigation flow of the user interface', 'fig_screens6')
    d.h3('6.5.3 Error Handling in the Interface')
    d.bullets([
        '**Form validation.** The browser helps with required fields and input types, but the server checks everything again. A wrong form is shown again with what the user typed, an error summary at the top and a message beside each wrong field, for example "Enter the 10-character M-Pesa confirmation code, for example QJK4H2M9XP."',
        '**Refused actions.** An action the system will not allow shows a red message that says why, for example "That time is already booked for this doctor. Choose another slot."',
        '**Error pages.** Four calm pages replace the web server\'s own: 403 "not allowed", 404 "page not found", 419 "the form has expired" and 500 "something went wrong" with a reference number. None shows file paths, program code or database messages.',
        '**Confirmation.** Dangerous actions, such as deactivating an account, ask the user to confirm first.',
    ])
    d.figure(shot_main('14-patient-register-errors'), 'Validation messages beside the fields of a form', label='fig_ui_err', width_cm=13.5, max_height_cm=11)


def build_process(d):
    d.h2('6.6 Development Process')
    d.h3('6.6.1 Integrated Development Environment and Tools')
    d.p('The code was written in Visual Studio Code on Windows 11, run on a local XAMPP server, and tested in the Microsoft Edge browser. Git kept the history. PowerShell and Bash were used for the scripts and the test runs. The tools are those named in the proposal (Waweru, 2026).')
    d.h3('6.6.2 Programming Languages and Libraries')
    d.p('The system is written in PHP 8.2 (the rules and pages), SQL (the schema and queries), HTML5 and CSS3 (the pages and their style), JavaScript (a very small file) and PowerShell (backup and restore). No library or framework is used. This was a deliberate choice: the hospital has no one to update a framework, and a smaller code base is easier to read, test and secure (Sommerville, 2016).')
    d.h3('6.6.3 Code Structure')
    d.p('The code is organised by area of work. Each folder holds the pages of one department, each page is short (about 40 to 100 lines) and follows the same pattern: require the permission, read and check the input, call the rule, show the page. The rules live in the includes folder, one file for each module, so that they can be tested without a web server. '
        '{ref:tab_loc} gives the size of each part.')
    d.code('''ub-hms/
  index.php login.php logout.php password.php error.php
  patients/  appointments/  visits/  lab/  pharmacy/  billing/  reports/  admin/
  includes/    bootstrap.php core.php db.php helpers.php layout.php
               auth.php csrf.php audit.php users.php health.php dashboard.php
               patients.php appointments.php clinical.php orders.php
               laboratory.php pharmacy.php billing.php reports.php
  assets/      css/app.css  js/app.js
  config/      config.php   (config.local.php is ignored by Git)
  database/    schema.sql install.php demo_data.php demo_clinical.php create_app_user.php
  scripts/     backup.ps1 restore.ps1
  storage/     logs/ backups/            (refused by the web server)
  tests/       run_tests.php lib.php unit/*_test.php e2e.sh apache_check.ps1 perf_seed.php
  docs/        defects.md security-review.md gap-analysis.md chapters/ figures/ screenshots/ evidence/''')
    d.table(['Part', 'Files', 'Lines'], LOC, widths=[10.0, 2.3, 3.0], size=9, caption='Size of the Code', label='tab_loc', align=['l', 'r', 'r'])
    d.h3('6.6.4 Version Control and the Agile Process')
    d.p('The project used Git on a single branch (main) with one commit for each sprint and further commits for the documentation work. Each sprint followed the four steps of the proposal: plan, design, develop and test (Schwaber & Sutherland, 2020; Waweru, 2026). The student acted as product owner and developer. The tests written in each sprint were run before the commit, and the whole suite was run again at the end of Sprint 5.')
    d.table(['Sprint', 'Built', 'Git commit', 'Tests added in the sprint'], SPRINTS, widths=[1.5, 8.2, 2.0, 3.6], size=9, caption='The Five Sprints and Their Commits', label='tab_sprints', align=['c', 'l', 'l', 'l'])
    d.p('The sprint reviews with hospital staff described in the proposal (section 3.3.1) have not yet taken place, because the system has not been shown to staff. The comments they would give are to be collected at user acceptance testing and recorded in section 7.5. The commit dates all fall on the same day because the code and the documentation were produced in one working period and are not evidence of the planned sprint dates.')


def build_security(d):
    d.h2('6.7 Security and Authentication')
    d.h3('6.7.1 Security Measures')
    d.p('The measures were designed in Chapter 5 (section 5.7) and implemented as follows. Each was tested; the evidence is in Chapter 7 and in docs/security-review.md.')
    d.table(['Threat', 'Measure implemented', 'Where in the code'], [
        ['Unauthorised access to a page or action', 'One permission table; every page begins with require_permission(); refusals are audited', 'includes/auth.php'],
        ['Guessing or stealing passwords', 'bcrypt cost 11, a rule for password strength, lockout after five failures, a limit on failures per computer, forced change of one-time passwords', 'includes/auth.php'],
        ['SQL injection', 'PDO prepared statements with emulation off; LIKE wildcards escaped; limits cast to integers', 'includes/db.php, all rule files'],
        ['Cross-site scripting', 'e() escapes every value printed; strict Content Security Policy; no inline scripts', 'includes/helpers.php, bootstrap.php'],
        ['Cross-site request forgery', 'A secret token in every form, compared in constant time; changes accepted only by POST', 'includes/csrf.php'],
        ['Session theft or fixation', 'HttpOnly, SameSite=Strict cookie; new session id at sign-in; idle and absolute timeouts; deactivation ends the session', 'includes/bootstrap.php, auth.php'],
        ['Exposure of internal files', '.htaccess rules in every internal folder; directory listing off; errors logged, not shown', '.htaccess files'],
        ['Tampering with history', 'Append-only database permissions for the audit trail, payments and stock ledger; no delete pages', 'database/create_app_user.php'],
        ['Data leaving through the audit trail', 'The audit trail records identifiers and short descriptions only', 'includes/audit.php'],
    ], widths=[3.8, 8.0, 3.5], size=8.5, caption='Security Measures Implemented', label='tab_secimpl')
    d.h3('6.7.2 Authentication Process')
    d.numbered([
        'The user opens the sign-in page, which carries a secret token, and types the username and password.',
        'The system checks the token and whether the computer has had 20 failed attempts in the last 15 minutes.',
        'It looks the account up and checks the password against the stored hash. If the username is unknown it still checks against a dummy hash so that the answer takes the same time.',
        'It refuses a locked account (even with the right password), counts a wrong password (locking the account at the fifth), and refuses a deactivated account. All refusals give one neutral message and are audited.',
        'On success it clears the failure count, records the time, issues a new session number and a new token, and writes an audit entry.',
        'If the password is a one-time password, the user is sent to the change-password page and can reach nothing else until a new password that follows the rule has been set. Changing it needs the current password.',
        'On every later request the system checks that the session has not expired, that the account is still active, and that the role holds the permission of the page.',
    ])
    d.h3('6.7.3 Management of Security Errors')
    d.p('A security error must help the hospital and not the attacker. A wrong sign-in gives one neutral message. A refused page shows the 403 page with no hint of what exists behind it. A missing or wrong form token shows the 419 page and is audited. '
        'An unexpected fault is written to the log with a six-character reference and the user sees only the reference. Nothing shows file paths, stack traces, SQL or the PHP version (tests E2E-246 and E2E-247, and Apache check APA-019).')


def build_deployment(d):
    d.h2('6.8 Deployment')
    d.h3('6.8.1 Server Configuration')
    d.table(['Item', 'Specification'], [
        ['Server computer', 'Core i5 or better, 8 GB RAM, 256 GB solid-state disk or larger (minimum Core i3, 4 GB RAM, 128 GB), wired network card, UPS. The computer is used only as the server and is kept in a locked room.'],
        ['Operating system', 'Windows 11 (tested) or Windows 10 or Ubuntu Linux, with automatic updates, a firewall that allows web traffic (port 80, and 443 if HTTPS is used) from the hospital network only, and a password on every account.'],
        ['Software', 'XAMPP 8.2.12 or newer: Apache 2.4 with mod_headers and AllowOverride All, PHP 8.1 or newer with pdo_mysql and mbstring, MariaDB. MariaDB 10.4 is past its support period, so a newer XAMPP with a supported MariaDB should be used and the system retested.'],
        ['Network', 'Wired LAN; the server has a fixed address and a name; the database port (3306) is not open to the network.'],
        ['Staff computers', 'Any computer with a current browser; a shortcut to the server address on the desktop.'],
        ['Printers', 'An office printer at reception and in the pharmacy, a receipt printer at the cashier, set as the default printer of each computer.'],
        ['Power and backup', 'UPS that keeps the server running for at least 15 minutes; an external hard disk for weekly copies of the backups.'],
        ['Folder name', 'The application is placed in C:\\xampp\\htdocs\\ub-hms. The branded error pages in the main .htaccess point to /ub-hms/error.php; if another folder name is used that file must be changed.'],
    ], widths=[3.4, 11.9], size=9, caption='Server Configuration', label='tab_server', align=['l', 'l'])
    d.h3('6.8.2 Deployment Plan')
    d.p('The steps below install the system on the hospital\'s server. They take about two hours for a technician who has done it before. Steps 1 to 7 are done once; steps 8 to 10 prepare the hospital for use.')
    d.numbered([
        '**Prepare the server.** Install Windows updates, set a fixed address and a name, set the firewall, plug the server into the UPS and set the time zone to East Africa Time.',
        '**Install XAMPP.** Install it to C:\\xampp, start Apache and MySQL (MariaDB) as services so they start with the computer, and set a password for the MariaDB root account.',
        '**Copy the system.** Copy the folder ub-hms to C:\\xampp\\htdocs\\ub-hms. Do not copy the folders tests and docs to a live server unless needed; Apache refuses them anyway.',
        '**Create the database and the first administrator.** In a terminal in the folder, run: C:\\xampp\\php\\php.exe database\\install.php --admin-user=sysadmin --admin-pass="a-strong-password". Leave out --demo, because a real installation holds no demonstration data. Write the one-time password down; the administrator must change it at first sign-in.',
        '**Create the restricted database account.** Run: C:\\xampp\\php\\php.exe database\\create_app_user.php --password="a-long-random-password". Then create config\\config.local.php containing the account name and password, as shown in the README. Check that the system still opens.',
        '**Switch on HTTPS.** Enable the SSL module of Apache with a certificate, and send port 80 to 443. Where this cannot be done at once, record the risk and do it before wireless links are used.',
        '**Schedule the backup.** Create the Windows scheduled task for scripts\\backup.ps1 every night at 22:00; run it once by hand; check that the health page shows the backup as OK; and copy the first backup to the external disk.',
        '**Enter the standard data.** The administrator signs in, creates the accounts of the staff (one for each person, with the right role), and checks the price list. The pharmacist enters the medicine list and the opening stock as batches.',
        '**Migrate the existing records** (section 6.8.3), then **train the staff** (section 6.8.4).',
        '**Change over** from paper to the system (section 6.8.5) and hold the post-implementation review.',
    ])
    d.p('Before the system is used for real, the hospital must also complete its own data protection steps: register as a data controller or processor where the law requires, assess the risk to patients\' data, and set the rules for how long records are kept (Republic of Kenya, 2019). The system helps with these steps but cannot do them.')

    d.h3('6.8.3 Data Migration')
    d.p('The hospital\'s information today is on paper. Moving it into the system is limited to what is useful on day one, which is the list of registered patients, the price list, the medicines in stock and the staff accounts. Old visits, diagnoses, results and invoices stay on paper and are looked up there; copying them would be slow, error-prone and of little use.')
    d.table(['What', 'From', 'How', 'Checked by'], [
        ['Patients', 'Paper registers or a spreadsheet', 'Records clerks copy the register into a spreadsheet with the columns given on the Import patients page (first, middle and last name, sex, date of birth, ID number, phone, residence, allergies, next of kin). The file is saved as CSV and loaded in two steps: a preview that marks every row To be added, Possible duplicate or Error and saves nothing, then a confirmation that registers only the valid rows. Up to 500 rows a file.', 'The preview; then a clerk compares 5% of the new records, chosen at random, with the paper register. Rows marked Error are corrected and loaded again. Each import is audited.'],
        ['Staff accounts', 'The hospital\'s staff list', 'The administrator creates one account for each person with one role and gives each a one-time password in person.', 'The administrator checks the list against the staff list.'],
        ['Price list', 'The hospital\'s current price list', 'The administrator enters the prices of consultations, procedures and tests; the pharmacist enters the price of each medicine.', 'The manager signs the price list printed from the system.'],
        ['Medicines and stock', 'Stock cards and a physical count', 'The pharmacist counts the shelves and receives each batch with its batch number, expiry date and quantity.', 'The stock report is compared with the count; expired batches found are written off with an adjustment and a reason.'],
    ], widths=[2.3, 3.0, 6.3, 3.7], size=8.5, caption='Data Migration Plan', label='tab_migration')
    d.p('Old OP numbers on paper files need a rule. The recommended rule is that patients who are loaded from the register receive new numbers in the form OPyy-nnnnn, the old file number is written on the new file, and from the day of changeover only the new number is used. The old file number is not stored in the system, which is a limitation (section 7.4.3).')

    d.h3('6.8.4 Training Plan')
    d.p('The training aims at the tasks that each role does every day. Each session is practical, at a computer, with synthetic data on a training copy of the system, and uses the manual in Chapter 8. A "train the trainer" session comes first, with one representative of each department, who then helps their colleagues. The plan is a proposal; the dates must be agreed with the hospital management.')
    d.table(['Group', 'People', 'Content', 'Length', 'Practical check before signing off'], [
        ['Receptionists and records clerks', '6', 'Sign in and password; register and search patients; correct details; import; book, cancel and mark appointments; check in', '3 hours', 'Register a patient, avoid a duplicate, book and check in without help'],
        ['Nurses', '12', 'Sign in; triage queue; record vital signs and procedures; allergy flags', '2 hours', 'Record triage with one wrong value and correct it'],
        ['Doctors and clinical officers', '8', 'Queue; consultation page; ICD-10 codes; tests; prescriptions; results; close a visit', '2 hours', 'Complete a consultation with a test and a prescription'],
        ['Laboratory technologists', '4', 'Worklist; start tests; enter and correct results', '1.5 hours', 'Enter a result and flag it'],
        ['Pharmacists', '4', 'Prescription queue; dispense; receive stock; adjust; medicine list; alerts', '3 hours', 'Dispense across two batches; receive a delivery'],
        ['Cashiers and billing clerks', '5', 'Invoice list; payments by each method; references; receipts; daily collections', '2.5 hours', 'Take a part-payment by M-Pesa and a final payment in cash'],
        ['Managers', '3', 'Dashboard; the four reports; CSV; audit trail', '1.5 hours', 'Run each report and download one'],
        ['System administrator', '1 or 2', 'Accounts; one-time passwords; unlocking; price list; health page; backup and restore; updates', '4 hours', 'Create and deactivate an account; run a backup and restore it on a copy'],
    ], widths=[3.0, 1.1, 5.5, 1.5, 4.2], size=8.5, caption='Training Plan', label='tab_training', align=['l', 'c', 'l', 'c', 'l'])
    d.p('The first session, for the representatives, is planned for the user acceptance testing days in the proposal (14 to 16 December 2026). Department sessions follow in the two weeks before changeover, in groups of at most six so that each person has a computer. '
        'Each person receives the section of the manual for their role and a one-page quick guide, and a "floor walker" from the first group is present in each department for the first week of use. Training is considered complete when a person has done the practical check without help; the trainer records this on a list kept by the manager.')

    d.h3('6.8.5 Changeover Plan')
    d.p('The recommended method of changeover is a phased parallel run: the system is used beside the paper records for a short time, one department group after another, before the paper is stopped. This is safer than switching everything on one day, because a problem found in one department does not stop the others, and the paper records remain as a fall-back. {ref:tab_changeover} shows the phases.')
    d.table(['Phase', 'What happens', 'Length', 'Condition to move on'], [
        ['0. Preparation', 'Server installed and tested; accounts, prices, stock and patient register entered; training done; downtime procedure printed and posted', '2 weeks', 'Checklist signed by the administrator and the manager; backup and restore tested on the live server'],
        ['1. Front desk and clinic', 'Reception, triage and consultation use the system and also the paper register and slips', '1 week', 'No unresolved defect of High severity; staff can finish their tasks without help'],
        ['2. Laboratory and pharmacy', 'Laboratory and pharmacy join; paper requests and stock cards are still kept', '1 week', 'Stock on screen matches the shelf count; results reach doctors'],
        ['3. Billing and reports', 'The cashier works from the system\'s invoices; the paper receipt book is kept for one more week; the manager compares the first reports with paper totals', '1 week', 'The day\'s collections and the invoices agree with the cash and the mobile money records'],
        ['4. Full use', 'Paper registers are stopped except for the downtime procedure; the system is the official record', 'From the end of phase 3', 'Management sign-off'],
        ['5. Review', 'Post-implementation review: problems, staff comments, changes to make', '4 weeks after', 'Report to management'],
    ], widths=[2.7, 7.0, 1.9, 3.7], size=8.5, caption='Changeover Plan', label='tab_changeover', align=['l', 'l', 'c', 'l'])
    d.bullets([
        '**Go and no-go.** The manager and the administrator decide at the end of each phase using the condition in the table. If a condition is not met, the phase is extended.',
        '**Fall-back.** If the system cannot be used, the department returns to paper for that day. The paper forms are kept ready. When the system is back, the records made on paper are entered the same day, marked as "entered late" in the reason field.',
        '**Downtime procedure.** A power cut or server failure is handled with the UPS, then the paper forms. The administrator restores the latest backup if the database is damaged; the maximum loss is the work since the last backup.',
        '**Support.** The administrator is the first contact; the developer is the second. Problems are written on a log sheet with the time, the person, what happened and the reference number shown on an error page.',
        '**Risks.** Staff resistance, double work during the parallel run, a power cut or a wrong stock count at the start. The plan answers them with training, a short parallel run, a UPS and a counted opening stock.',
    ])


def build_appendices(d):
    d.h2('6.9 Appendices')
    d.h3('6.9.1 Code Snippets')
    d.p('Six short extracts show the most important rules in the code. They are shortened for the page.')
    for i, (title, code) in enumerate(SNIPPETS, 1):
        d.p(f'**Snippet {i}.** {title}', keep_next=True)
        d.code(code)
    d.h3('6.9.2 References')
    keys = ['proposal', 'owasp', 'kenya2019', 'schwaber', 'sommerville']
    for key in sorted(keys, key=lambda k: REFERENCES[k].lower()):
        p = d.p(REFERENCES[key], align='left')
        _hanging(p)
    d.h3('6.9.3 Glossary')
    d.p('The terms used in this chapter are defined in Appendix A of Chapter 4. These terms are added.')
    d.table(['Term', 'Meaning'], [
        ['Changeover', 'The move from the old way of working (paper) to the new system.'],
        ['Parallel run', 'Using the old and the new way of working together for a time.'],
        ['Data migration', 'Moving existing records into the new system.'],
        ['Deployment', 'Installing the system on the hospital\'s server and making it ready for use.'],
        ['Downtime procedure', 'The steps to follow when the system cannot be used.'],
        ['Least privilege', 'Giving a person or program only the access its work needs.'],
        ['Cron / Task Scheduler', 'The part of the operating system that runs a program at a set time; here, the nightly backup.'],
    ], widths=[4.3, 11.0], size=9, caption='Additional Terms Used in Chapter 6', label='tab_gloss6')


def _hanging(p):
    from docx.shared import Cm
    p.paragraph_format.left_indent = Cm(1.0)
    p.paragraph_format.first_line_indent = Cm(-1.0)
    p.paragraph_format.line_spacing = 1.15
