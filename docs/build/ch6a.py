"""Chapter 6, part 1: introduction, architecture, modules, database (6.1 to 6.4)."""
from doc_common import fig, landscape_figure

MODULES6 = [
    dict(n=1, name='Module 1: Patient registration and appointments',
         files='includes/patients.php, includes/appointments.php; pages patients/ (index, register, view, edit, import) and appointments/ (index, book, action)',
         io='**Inputs:** the registration form, the search text, a CSV file, the booking form (patient, doctor, date, time slot, reason) and status actions. **Outputs:** the patient record with its OP number, search results, the diary and the list of free slots.',
         logic='Field validation (names, date of birth, Kenyan phone, ID number); duplicate detection by ID number and by name with date of birth; the OP number is built from a per-year counter that is locked and incremented inside the saving transaction; appointments use a 20-minute grid, a doctor-row lock and a unique slot key; overdue Booked appointments are marked Missed when the diary is opened.',
         interacts='Module 2 reads patients and appointments at check-in and marks the appointment Attended. Module 5 reads both for the attendance report. Module 6 supplies permissions and the audit trail.',
         screens='Search patients, Register patient, Patient record, Correct patient, Import patients, Appointment diary, Book appointment (shown in section 5.6).',
         user='The receptionist types in the search box and presses Enter; chooses a doctor and a day and then a free slot; cancels or marks an appointment from the diary with one button each.',
         db='Tables patients, op_sequences, appointments, users (doctor row lock). Key queries: SELECT ... FOR UPDATE on op_sequences; the duplicate check by national_id and by name and date of birth; the slot check SELECT ... FOR UPDATE on users, then INSERT INTO appointments.',
         errors='Every rule returns errors by field name, shown beside the fields. A duplicate-key error from the database (code 23000) is turned into the same friendly message as the application check. Any other exception rolls the transaction back, is logged with a reference number and shows the calm error page.'),
    dict(n=2, name='Module 2: Clinical consultation',
         files='includes/clinical.php, includes/orders.php; pages visits/ (checkin, queue, triage, consult, view)',
         io='**Inputs:** check-in choices, the triage measurements, the consultation form, procedures. **Outputs:** the visit queues, the consultation page (triage, allergies, history) and the visit summary.',
         logic='One open visit per patient; the stage of a visit is worked out from what exists (triage, consultation) so it cannot disagree with the records; triage values are checked against ranges; a consultation can be changed only by its own doctor; a visit is closed only after a consultation; clinical details are shown only to clinical roles.',
         interacts='Uses Module 1 (patient, appointment). Passes test requests to Module 3 and prescriptions to Module 4. Every recorded service goes to Module 5 through billing_sync_visit(). Uses Module 6 for permissions and the audit trail.',
         screens='Check-in, Triage queue, Triage form, Consultation queue, Consultation page, Patient record (clinician view), All open visits (shown in section 5.6).',
         user='The nurse presses Triage on a waiting patient and fills a short form; the doctor opens a patient and sees everything on one page, then saves and uses the sections for tests and prescriptions on the same page.',
         db='Tables visits, triage, consultations, visit_services, services, icd10_codes, appointments, patients. Key queries: the queue query joins visits to patients and computes the stage and waiting time; the history query lists earlier visits with diagnoses.',
         errors='Messages by field; refusal messages for rules such as "only the doctor who recorded it can change it"; a closed visit shows its record read-only.'),
    dict(n=3, name='Module 3: Laboratory management',
         files='includes/laboratory.php (and the test-request section of includes/orders.php); pages lab/ (index, result, results)',
         io='**Inputs:** the doctor\'s test selection and reason, the technologist\'s start action and result text with the range flag. **Outputs:** the worklist, the result for the requesting doctor, and the My test results list.',
         logic='All tests in a request are saved in one transaction or none; a test already pending for the visit is refused; only the requesting doctor can cancel, and only before the test is started; a result cannot be empty or entered for a cancelled request; results are shown only to the requesting doctor; each request becomes an invoice line.',
         interacts='Takes requests from Module 2, adds charges through Module 5, and shows results back on the Module 2 consultation page.',
         screens='Laboratory worklist, Enter result, My test results, tests section of the consultation page (shown in section 5.6).',
         user='The technologist presses Start and then Enter result; the doctor sees a count of results ready on the dashboard.',
         db='Tables lab_tests, lab_requests, visits, users. The worklist query uses ix_lab_status; the doctor\'s results use ix_lab_requested_by.',
         errors='A request with one unknown test saves nothing; the page names the failing test. A repeat of the same pending test is refused with a message.'),
    dict(n=4, name='Module 4: Pharmacy and inventory',
         files='includes/pharmacy.php; pages pharmacy/ (index, dispense, stock, batches, receive, medicines, medicine)',
         io='**Inputs:** medicine details, deliveries (batch, expiry, quantity), adjustments with a reason, the doctor\'s prescription lines, the pharmacist\'s quantities to dispense. **Outputs:** the prescription queue, stock with status, batches with expiry state, the stock ledger and alerts.',
         logic='Usable stock excludes expired batches; status is Out at zero, Low at or below the reorder level; dispensing takes stock from unexpired batches earliest expiry first inside one transaction, with a guarded UPDATE that cannot go below zero and a ledger entry for each batch; the allergy reminder compares the patient\'s recorded allergies with the medicine name.',
         interacts='Takes prescriptions from Module 2, adds the medicines given to the invoice (Module 5), and supplies alerts to the manager dashboard and the stock report.',
         screens='Prescription queue, Dispense, Medicine stock, Batches, Receive stock, Medicine list (shown in section 5.6).',
         user='The pharmacist opens a prescription, types the quantity for each line and presses one button; the system shows which batches were used.',
         db='Tables medicines, medicine_batches, prescriptions, prescription_items, stock_movements. Key queries: the batch selection SELECT ... ORDER BY expiry_date FOR UPDATE and the guarded UPDATE medicine_batches SET quantity = quantity - ? WHERE quantity >= ?.',
         errors='If any line fails, the whole dispensing is rolled back and the page shows the line and the reason, for example "only 24 in stock (expired stock cannot be dispensed)".'),
    dict(n=5, name='Module 5: Billing and reports',
         files='includes/billing.php, includes/reports.php; pages billing/ (index, invoice, pay, receipt) and reports/ (index, attendance, revenue, stock, lab)',
         io='**Inputs:** the payment form (method, amount, reference), search filters and report dates. **Outputs:** invoices, receipts, the collections summary, four reports on screen, in print and as CSV.',
         logic='billing_sync_visit() rebuilds the invoice from the records of the visit each time something billable happens: it adds, updates or removes lines, recalculates the total and sets the status, and can be repeated safely; payments are validated inside a transaction with the invoice locked; money is calculated in cents; CSV cells that begin with =, +, - or @ are neutralised.',
         interacts='Reads Modules 1 to 4 for charges and report data; is called by Modules 2, 3 and 4 whenever a charge changes.',
         screens='Invoice list, Invoice, Receipt, the four reports (shown in section 5.6).',
         user='The cashier opens an invoice, chooses the method and presses Record payment; the manager chooses a date range and a report and optionally downloads CSV.',
         db='Tables invoices, invoice_items, payments, plus reads of the other modules\' tables. Key queries: the sync query set, the payment UPDATE inside a transaction, and the grouped queries behind each report.',
         errors='Reasons beside the field for a bad amount or reference; a refusal message if a removal would leave an invoice over-paid; bad report dates fall back to a safe range.'),
    dict(n=6, name='Module 6: User management and security',
         files='includes/auth.php, csrf.php, audit.php, users.php, health.php, bootstrap.php; pages login, logout, password, admin/ (users, user, prices, audit, health)',
         io='**Inputs:** username and password, account details, price changes, audit filters. **Outputs:** the session, the menu for the role, the audit trail, the health report.',
         logic='bcrypt hashing; lockout after five failures and a limit per computer; forced password change; session limits; one permission table checked on every page; CSRF token on every POST; audit entry for each important action; data-integrity reconciliation on the health page.',
         interacts='Every other module calls require_permission() at its start, audit() when it acts, and require_post() before it changes data.',
         screens='Sign in, Change password, User accounts, Edit user, Price list, Audit trail, System health (shown in section 5.6).',
         user='The administrator creates an account and reads a one-time password once; the manager filters the audit trail by person or date.',
         db='Tables users, roles, audit_log, services, lab_tests. Key queries: the sign-in lookup with lock test, the failure counter UPDATE, the audit INSERT and the filtered audit SELECT.',
         errors='Neutral messages that never say which part of a sign-in was wrong; 403, 404, 419 and 500 pages with no technical detail; unexpected errors logged with a reference number.'),
]

TECH_STACK = [
    ['Client', 'HTML5, CSS3 (one stylesheet), JavaScript (one small file; no libraries)', 'Standard web technologies, as the proposal requires'],
    ['Server language', 'PHP 8.2.12 (needs 8.1 or newer) with PDO MySQL, mbstring, session and hash', 'Common, free, and taught on the programme'],
    ['Web server', 'Apache 2.4.58 with mod_headers and AllowOverride All', 'Part of XAMPP'],
    ['Database', 'MariaDB 10.4.32, InnoDB, utf8mb4', 'Part of XAMPP; compatible with MySQL'],
    ['Packaging', 'XAMPP 8.2.12 for Windows', 'One installer for Apache, PHP and MariaDB'],
    ['Editor', 'Visual Studio Code', 'Chosen in the proposal'],
    ['Version control', 'Git (branch main, one commit for each sprint)', 'History and backup of the code'],
    ['Testing', 'Own PHP unit-test runner, Bash and curl for end-to-end checks, PowerShell and Apache ab for server checks', 'No libraries to install; runs on the same machine'],
    ['Operations', 'PowerShell scripts for backup and restore; Windows Task Scheduler', 'Available on every Windows server'],
    ['Documentation tools', 'Python (python-docx), Graphviz, Playwright with Edge for screenshots', 'Used to build this report and its figures'],
]


def build_intro(d):
    d.page_break()
    d.h1('CHAPTER SIX: SYSTEM IMPLEMENTATION')
    d.set_chapter(6)
    d.h2('6.1 Introduction')
    d.h3('6.1.1 Overview')
    d.p('The Uhai Bora Hospital Management System (UB-HMS) is a web-based system that keeps the patient, clinical, laboratory, pharmacy and billing information of Uhai Bora Hospital in one secure database and shows each member of staff only what their role needs (Waweru, 2026). '
        'Chapter 4 specified what the system must do and Chapter 5 how it is designed. This chapter describes how the design was turned into a working system: the technical set-up, the code of each module, the database, the screens, the way the work was organised, the security measures, and how the system is installed and handed over to the hospital.')
    d.h3('6.1.2 Objectives of the Implementation')
    d.bullets([
        'To build the six modules of the design as working software that passes the tests of Chapter 7.',
        'To keep every rule of the requirements in code, with the database enforcing the rules that matter most.',
        'To make the system easy to install, back up, restore and maintain on one XAMPP server.',
        'To prepare the hospital for use: data migration, training and a safe changeover from paper.',
    ])
    d.h3('6.1.3 Scope and Limitations')
    d.p('The implementation covers release 1.0 of all six modules, the installation and backup scripts, the automated tests and the plans for deployment. It does not cover the exclusions of the proposal (payroll, ward management, theatre, radiology, connection to national systems or mobile applications). '
        'Four limitations apply and are repeated in Chapter 7. Development and testing used only synthetic data, because patient information is confidential. Only one platform was used: Windows 11 with XAMPP 8.2.12 (PHP 8.2.12, MariaDB 10.4.32) and the Microsoft Edge browser. '
        'The system has not been used by hospital staff, so user acceptance testing and training are still to be done. And the plans for data migration, training and changeover in section 6.8 are plans: they have been written but not carried out.')


def build_architecture(d):
    d.h2('6.2 System Architecture Implementation')
    d.h3('6.2.1 Architectural Implementation')
    d.p('The architecture of Chapter 5 was implemented as three tiers on one server computer. {ref:fig_impl_comp} shows the code, which is arranged in layers so that each part has one job: the pages in the department folders, the business rules in the files of the includes folder, the shared core and security code, and the database.')
    landscape_figure(d, None, '', fig('components'), 'Components and modules of the implemented system', 'fig_impl_comp')
    d.p('The technical set-up is as follows:')
    d.bullets([
        '**Web server.** Apache serves the folder of the application, which is installed in the folder ub-hms under the XAMPP document root (C:\\xampp\\htdocs\\ub-hms). A file called .htaccess in the main folder switches off directory listing, refuses internal file types (.sql, .md, .log, .ps1 and others) and sets three security headers. Each internal folder (config, includes, database, scripts, storage, tests) has its own .htaccess that refuses all web requests. This was checked on a real Apache server with 23 checks (Chapter 7).',
        '**PHP.** Every page begins by including includes/bootstrap.php. It turns off the display of errors (they are logged to storage/logs), installs a handler that shows a calm error page with a reference number, sends the security headers, starts the session with strict settings, and loads the shared code.',
        '**Configuration.** The defaults are in config/config.php, which holds no secrets. A file config/config.local.php (ignored by Git) or environment variables UBHMS_DB_HOST, UBHMS_DB_PORT, UBHMS_DB_NAME, UBHMS_DB_USER and UBHMS_DB_PASS override them. The same file holds the hospital\'s name, clinic hours, slot length, password rules, lockout settings and session timeouts.',
        '**Database connection.** includes/db.php opens one PDO connection with exceptions switched on, prepared-statement emulation switched off, strict SQL mode and the time zone +03:00. All queries pass through five helper functions (db_all, db_one, db_value, db_exec, db_insert) and a transaction helper (db_tx) that can be nested.',
        '**Sessions.** The cookie is named UBHMSSESSID, is HttpOnly and SameSite=Strict (and Secure under HTTPS), and the session ends after 30 idle minutes or 8 hours.',
        '**Files outside the public area.** storage/logs holds the application and PHP error logs and storage/backups holds the backups. Both are refused by Apache.',
    ])
    d.h3('6.2.2 Technology Stack')
    d.table(['Layer', 'Technology and version', 'Reason'], TECH_STACK, widths=[2.8, 7.4, 5.1], caption='Technology Stack Used', label='tab_stack', size=9)
    d.p('The system uses no third-party PHP or JavaScript libraries and loads nothing from other websites (no fonts, scripts or trackers). This keeps the hospital independent of the internet, leaves nothing of its own to patch, and keeps the strict Content Security Policy simple (OWASP Foundation, 2021).')


def build_modules(d):
    d.h2('6.3 Modules and Components Implementation')
    d.p('Each module is described below in the order of the guide: its inputs and outputs, its business logic, the modules it depends on, its screens, how users work with it, its database interactions and how it handles errors. The sizes of the code are given in section 6.6.')
    for m in MODULES6:
        d.h3(f'6.3.{m["n"]} {m["name"]}')
        rows = [
            ['Code', m['files']],
            ['Inputs and outputs', m['io']],
            ['Business logic', m['logic']],
            ['Interaction with other modules', m['interacts']],
            ['Screens and views', m['screens']],
            ['User interactions', m['user']],
            ['Database interactions', m['db']],
            ['Error handling', m['errors']],
        ]
        d.table(['Item', 'Description'], rows, widths=[3.4, 11.9], size=9, caption=f'Implementation of {m["name"]}', label=f'tab_mod{m["n"]}', align=['l', 'l'])


def build_database(d):
    d.h2('6.4 Database Implementation')
    d.h3('6.4.1 Database Schema')
    d.p('The database uhai_bora_hms has 22 InnoDB tables with 180 fields, 36 foreign keys and 80 indexes. The entity relationship diagrams and the data dictionary are in sections 5.5.1 and 5.5.7. '
        'The whole schema is created by one script, database/schema.sql, which drops and recreates every table and loads the standard data (eight roles, five services, seven laboratory tests and 43 ICD-10 codes). The installer, database/install.php, creates the database if it does not exist, runs the script, creates the first administrator with a one-time password and can add synthetic demonstration data. It refuses to run over an existing database unless told to, because the script drops tables.')
    d.p('Rules the database enforces on its own, whatever the application does, are:')
    d.bullets([
        'primary keys, 36 foreign keys with no cascading deletes, and a unique key for each business identity (OP number, ID number, username, invoice number, one invoice per visit, batch per medicine, appointment slot);',
        'CHECK constraints: prices and amounts not negative, a payment above zero, a batch quantity not below zero, dispensed quantity not above prescribed;',
        'a stored generated column, appointments.slot_key, whose unique index makes a double booking impossible.',
    ])
    d.h3('6.4.2 Key Queries')
    d.p('Six queries carry the most important business rules. They are shown in simplified form; the complete code is in the include files named.')
    d.table(['Purpose', 'Query (simplified)', 'Where'], [
        ['Issue the next OP number safely', 'SELECT last_number FROM op_sequences WHERE year = ? FOR UPDATE; UPDATE op_sequences SET last_number = last_number + 1 ...', 'patients.php, next_op_number()'],
        ['Check and take an appointment slot', 'SELECT id FROM users WHERE id = ? FOR UPDATE; SELECT id FROM appointments WHERE doctor_id = ? AND appointment_date = ? AND start_time = ? AND status IN (\'Booked\',\'Attended\'); INSERT INTO appointments ...', 'appointments.php, book_appointment()'],
        ['Choose the batch that expires first', 'SELECT * FROM medicine_batches WHERE medicine_id = ? AND quantity > 0 AND expiry_date >= ? ORDER BY expiry_date, id FOR UPDATE', 'pharmacy.php, dispense_prescription()'],
        ['Take stock without going below zero', 'UPDATE medicine_batches SET quantity = quantity - ? WHERE id = ? AND quantity >= ?   (one row must be changed)', 'pharmacy.php, dispense_prescription()'],
        ['List what the visit must be charged for', 'Four SELECTs (consultation, tests not cancelled, medicines dispensed, procedures) combined by billable_lines(); then INSERT or UPDATE invoice_items and UPDATE invoices', 'billing.php, billing_sync_visit()'],
        ['Count failed sign-ins from one computer', 'SELECT COUNT(*) FROM audit_log WHERE action = \'login_failed\' AND ip_address = ? AND created_at > NOW() - INTERVAL 15 MINUTE', 'auth.php, attempt_login()'],
    ], widths=[3.8, 8.2, 3.3], size=8.5, caption='Key Database Queries', label='tab_queries')
    d.h3('6.4.3 Backup and Recovery')
    d.p('The backup and recovery plan has four parts, all of which were tested on a database of 20,040 patients and 30,067 visits (Chapter 7, tests MAN-01 and MAN-02):')
    d.table(['Part', 'Plan'], [
        ['Backup', 'scripts/backup.ps1 uses mysqldump to write a consistent copy (one transaction), checks that the file ends with the "Dump completed" line, compresses it, deletes copies older than 30 days and adds a line to a log. The database password is passed through a temporary file that only the user can read, never on the command line. The large test database (62.5 MB) took 3.3 seconds and produced a 3.1 MB file.'],
        ['Schedule', 'Every night at 22:00 through Windows Task Scheduler (the command is in the header of the script). The health page warns when the newest backup is older than 26 hours and shows a failure after 48.'],
        ['Off-site copy', 'Each week the administrator copies the newest backup to the external hard disk and keeps it away from the server. A backup on the same disk as the database does not protect against a failed disk.'],
        ['Recovery', 'scripts/restore.ps1 makes a safety backup of the current data, asks the administrator to type the database name, then restores the chosen file. Users must be told to stop first. Restoring the large test backup took 39 seconds, and the row counts and invoice totals matched exactly.'],
        ['Recovery targets', 'At most one day of data lost (the last night\'s backup) and recovery in under an hour once the server is working. Both depend on the nightly backup being made; they were not tested under real working conditions.'],
    ], widths=[2.8, 12.5], size=9, caption='Backup and Recovery Plan', label='tab_backup', align=['l', 'l'])
    d.h3('6.4.4 Security Measures for the Database')
    d.bullets([
        'The application should connect with the account ubhms_app, created by database/create_app_user.php. It can read and write the data tables it needs, can only add rows to audit_log, payments and stock_movements, cannot change the structure and cannot read other databases. The complete end-to-end suite of 185 checks passes when the web server runs as this account.',
        'MariaDB accepts connections from the server computer only, and its root account must have a password before real use.',
        'No password is stored in source control. Passwords of users are bcrypt hashes. Prepared statements are used for every query.',
        'Backups hold patient information and must be stored with the same care as the database.',
    ])
    d.h3('6.4.5 Monitoring Tools')
    d.table(['Tool', 'What it shows', 'Who uses it'], [
        ['System health page (admin/health.php)', 'Database connection time, table count and size; PHP version; free disk space; log and backup folders; age of the newest backup; data-integrity reconciliation of invoices, payments, stock and prescriptions; failed sign-ins, locked accounts and refused requests in the last 24 hours; demonstration accounts still present; errors in the log; whether HTTPS is used.', 'Administrator'],
        ['Audit trail (admin/audit.php)', 'Who did what, to which record, from which computer and when, with filters.', 'Administrator, manager'],
        ['Error logs (storage/logs)', 'Unexpected errors with a reference number that the user can quote.', 'Administrator, developer'],
        ['Response-time check (tests/apache_check.ps1 -Perf)', 'Time to serve the main pages for one and for ten users, using Apache\'s ab tool.', 'Developer, administrator after changes'],
        ['Integrity check (data_integrity_problems())', 'Differences between invoices, payments, stock and prescriptions.', 'Run by the health page; also by the tests'],
    ], widths=[4.3, 8.3, 2.7], size=9, caption='Monitoring Tools', label='tab_monitor')
