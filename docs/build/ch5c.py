"""Chapter 5, part 3: human-machine interface, integrity controls, references and glossary (5.6 to 5.9)."""
import json
import os

from doc_common import HERE, GLOSSARY, REFERENCES, shot, shot_main, landscape_figure

PERMS = json.load(open(os.path.join(HERE, 'permissions.json'), encoding='utf-8'))
ROLE_ORDER = ['admin', 'manager', 'receptionist', 'nurse', 'doctor', 'lab', 'pharmacist', 'cashier']
ROLE_SHORT = {'admin': 'Admin', 'manager': 'Mgr', 'receptionist': 'Recep', 'nurse': 'Nurse', 'doctor': 'Doctor', 'lab': 'Lab', 'pharmacist': 'Pharm', 'cashier': 'Cash'}
PERM_TEXT = {
    'users.manage': 'Manage user accounts', 'audit.view': 'View the audit trail', 'settings.manage': 'Change the price list', 'system.health': 'View system health',
    'reports.attendance': 'Attendance report', 'reports.revenue': 'Revenue report', 'reports.stock': 'Stock report', 'reports.lab': 'Laboratory report',
    'pharmacy.stock': 'View medicine stock and batches', 'patients.view': 'View and search patients', 'patients.register': 'Register patients', 'patients.edit': 'Correct patient details',
    'patients.import': 'Import patients', 'appointments.view': 'View the diary', 'appointments.book': 'Book appointments', 'appointments.update': 'Cancel or mark appointments',
    'visits.checkin': 'Check patients in', 'visits.view': 'View queues and visits', 'triage.record': 'Record triage', 'services.record': 'Record procedures',
    'consult.record': 'Record consultations', 'lab.request': 'Request tests and read own results', 'pharmacy.prescribe': 'Prescribe medicines', 'lab.worklist': 'View the laboratory worklist',
    'lab.result': 'Enter laboratory results', 'pharmacy.dispense': 'Dispense prescriptions', 'pharmacy.manage': 'Receive and adjust stock, keep medicine list', 'billing.view': 'View invoices and receipts', 'billing.pay': 'Take payments',
}

INPUTS = [
    ['IN-01', 'Sign in', 'login.php', 'Everyone', 'Username (3 to 40 characters); password', 'Neutral message on failure; lockout after 5 failures'],
    ['IN-02', 'Change password', 'password.php', 'Everyone', 'Current password; new password; confirmation', '10 to 72 characters with upper-case, lower-case and a number; not containing the username'],
    ['IN-03', 'Register patient', 'patients/register.php', 'Receptionist', 'First, middle, last name; sex; date of birth (and "estimated"); ID number; phone; residence; allergies; next of kin name, relationship, phone', 'Names letters only; date not future; phone Kenyan; ID 5 to 20 letters or digits and unique; duplicate check'],
    ['IN-04', 'Correct patient', 'patients/edit.php', 'Receptionist', 'Same fields as IN-03; OP number shown but not editable', 'Same rules; ID number cannot belong to another patient'],
    ['IN-05', 'Import patients', 'patients/import.php', 'Receptionist', 'A CSV file with the columns of IN-03', 'Up to 500 rows and 1 MB; preview before saving'],
    ['IN-06', 'Book appointment', 'appointments/book.php', 'Receptionist', 'Patient; doctor; date; time slot; reason', 'Working day, 0 to 90 days ahead, on the 20-minute grid, slot free'],
    ['IN-07', 'Check in', 'visits/checkin.php', 'Receptionist', 'Patient or booked appointment; preferred doctor; reason', 'One open visit per patient'],
    ['IN-08', 'Triage', 'visits/triage.php', 'Nurse', 'Temperature; pulse; blood pressure (two values); breathing rate; oxygen saturation; weight; height; notes', 'Each value within its range; both blood pressure values with upper above lower'],
    ['IN-09', 'Consultation', 'visits/consult.php', 'Doctor', 'Consultation type; complaint; examination; diagnosis; ICD-10 code; plan', 'Type, complaint and diagnosis required; code from the hospital list'],
    ['IN-10', 'Procedure', 'visits/consult.php, triage.php', 'Nurse, doctor', 'Procedure; quantity', 'Quantity 1 to 20; visit open'],
    ['IN-11', 'Test request', 'visits/consult.php', 'Doctor', 'Tests (tick boxes); reason', 'At least one test; none already pending'],
    ['IN-12', 'Prescription', 'visits/consult.php', 'Doctor', 'For each line: medicine; dose; frequency; duration; quantity', 'All lines complete; quantity 1 to 9,999'],
    ['IN-13', 'Laboratory result', 'lab/result.php', 'Laboratory technologist', 'Result text; within or outside range', 'Result not empty; request not cancelled'],
    ['IN-14', 'Dispense', 'pharmacy/dispense.php', 'Pharmacist', 'Quantity to give for each line', 'At least one quantity; not above what remains or the usable stock'],
    ['IN-15', 'Medicine', 'pharmacy/medicine.php', 'Pharmacist', 'Name; form; strength; unit; price; reorder level; active', 'Price 0 or more; no duplicate'],
    ['IN-16', 'Receive stock', 'pharmacy/receive.php', 'Pharmacist', 'Medicine; batch number; expiry date; quantity', 'Expiry in the future; quantity above 0'],
    ['IN-17', 'Adjust stock', 'pharmacy/batches.php', 'Pharmacist', 'Batch; change in units; reason', 'Reason required; result not below zero'],
    ['IN-18', 'Payment', 'billing/pay.php', 'Cashier', 'Method; amount; reference', 'Amount above 0 and not above the balance; reference fits the method'],
    ['IN-19', 'User account', 'admin/user.php', 'Administrator', 'Full name; username; role; phone; active', 'Unique username; valid role; protects the last administrator'],
    ['IN-20', 'Price list', 'admin/prices.php', 'Administrator', 'Price of each service and test', 'Price 0 or more'],
    ['IN-21', 'Search and report filters', 'patients/index.php, billing/index.php, reports/, admin/audit.php', 'Various', 'Search text; status; dates; user; action', 'Treated as plain text; bad dates fall back to a safe range'],
]

OUTPUTS = [
    ['OUT-01', 'Dashboard', 'index.php', 'Every role', 'Welcome line, counts of work waiting for the role, alerts and shortcuts'],
    ['OUT-02', 'Patient search results', 'patients/index.php', 'Receptionist, nurse, doctor', 'OP number, name, sex, age, phone, ID number; 25 to a page'],
    ['OUT-03', 'Patient record', 'patients/view.php', 'Receptionist, nurse, doctor', 'Details, allergies, next of kin, appointments, visits; diagnoses shown to clinical roles only'],
    ['OUT-04', 'Appointment diary', 'appointments/index.php', 'Receptionist, nurse, doctor', 'Time, patient, doctor, reason, status, for a chosen day and doctor'],
    ['OUT-05', 'Queues', 'visits/queue.php', 'Receptionist, nurse, doctor', 'Arrival time, waiting time, patient, doctor, reason, stage, allergy flag'],
    ['OUT-06', 'Consultation page', 'visits/consult.php', 'Doctor', 'Triage, allergies, earlier visits, forms for consultation, tests, prescriptions and procedures, results received'],
    ['OUT-07', 'My test results', 'lab/results.php', 'Doctor', 'Results of the tests the doctor requested, with the range flag'],
    ['OUT-08', 'Laboratory worklist and results', 'lab/index.php, lab/result.php', 'Laboratory technologist', 'Requests waiting and completed, with patient, test, requesting doctor and notes'],
    ['OUT-09', 'Prescription queue', 'pharmacy/index.php', 'Pharmacist', 'Prescriptions waiting, oldest first, with allergy flag'],
    ['OUT-10', 'Medicine stock and batches', 'pharmacy/stock.php, batches.php', 'Pharmacist, doctor, manager', 'Usable stock, reorder level, status, nearest expiry; batches with expiry state; the stock ledger'],
    ['OUT-11', 'Invoice list', 'billing/index.php', 'Cashier', 'Invoice number, date, patient, total, paid, balance, status; today\'s collections'],
    ['OUT-12', 'Invoice (screen and print)', 'billing/invoice.php', 'Cashier', 'Itemised charges, totals, payments received'],
    ['OUT-13', 'Receipt (screen and print)', 'billing/receipt.php', 'Cashier', 'Receipt number, patient, invoice, method, amount, balance, received by'],
    ['OUT-14 to OUT-17', 'Attendance, revenue, stock and laboratory reports', 'reports/attendance.php, revenue.php, stock.php, lab.php', 'Manager (stock report also pharmacist)', 'Totals and tables for the chosen dates; Print and Download CSV'],
    ['OUT-18', 'CSV files', 'reports/*.php?csv=1', 'Manager, pharmacist', 'The same figures as the report, as a spreadsheet file'],
    ['OUT-19', 'Audit trail', 'admin/audit.php', 'Administrator, manager', 'Date and time, person, action, record, details, computer address'],
    ['OUT-20', 'System health', 'admin/health.php', 'Administrator', 'Each check as OK, Warning or Failed with its detail'],
    ['OUT-21', 'Error pages', 'error.php', 'Everyone', 'Calm messages for 403 (not allowed), 404 (not found), 419 (form expired) and 500 (error), with no technical detail'],
]


def build_hmi(d):
    d.h2('5.6 Human-Machine Interface')
    d.p('The interface was designed so that a member of staff who has used one screen can use the others. It follows the usual rules of good interface design: the system shows what is happening, uses the staff\'s own words, helps people recover from mistakes, prevents errors where it can, and keeps the layout the same on every page (Nielsen, 1994; Shneiderman et al., 2016). '
        'It also follows the basic rules of accessible web design: every field has a label, colour is never the only signal, and text and background have strong contrast (World Wide Web Consortium, 2018).')
    d.table(['Design rule', 'How it is applied in UB-HMS'], [
        ['Consistent layout', 'The same header, menu, content area and footer on every page; the main action of a form is always the solid green button.'],
        ['Visibility of status', 'Statuses are written in words in coloured badges (Waiting for triage, Partially paid, Low); a message confirms each saved action.'],
        ['Match with the real world', 'Words and forms the staff already use: outpatient number, triage, consultation, dispense, receipt.'],
        ['Error prevention', 'Impossible choices are not offered (only free slots can be booked); dangerous actions ask for confirmation; limits are written beside fields.'],
        ['Help to recover from errors', 'The form is kept as typed; every wrong field has a message that says how to correct it; a summary at the top lists them.'],
        ['Least surprise for each role', 'The menu shows only the pages the role may use, so nobody meets a page that refuses them in normal work.'],
        ['Works on narrow screens and in print', 'The menu folds behind a button on narrow screens; invoices, receipts and reports have a print layout without the menu.'],
    ], widths=[4.2, 11.1], caption='Interface Design Rules and How They Are Applied', label='tab_hmi', size=9, align=['l', 'l'])
    landscape_figure(d, None, '{ref:fig_screens} shows how a user moves between the screens. Everyone starts at sign-in, goes through the change-password page the first time, and reaches the dashboard, whose menu leads to the screens of the role.',
        os.path.join(HERE, '..', 'figures', 'screen_map.png'), 'Screen map of UB-HMS', 'fig_screens')

    d.h3('5.6.1 System Input Interface Design')
    d.p('Data enters the system only through web forms and one CSV file; there are no scanners or other input devices. {ref:tab_inputs} lists the input screens, the data elements of each, and the checks that are made. '
        'Each data element is defined in the data dictionary (section 5.5.7) under the table named for it. Every form carries a hidden security token, and every form that changes data is sent with the POST method. '
        'No paper form is keyed or scanned. The system\'s forms replace the hospital\'s paper registers, request forms and receipt book, and each screen follows the order of the paper form it replaces (Waweru, 2026). '
        'The access rule for every form is the permission of its role (section 5.7.1); a user without the permission receives the "not allowed" page.')
    d.table(['Code', 'Screen', 'Page', 'Used by', 'Data elements', 'Main checks'], INPUTS, widths=[1.1, 2.1, 2.5, 1.9, 4.2, 3.5], size=7.5, caption='Input Screens (Forms)', label='tab_inputs')
    d.p('The transactions of the system are the business actions of section 5.4, each with its own code in the audit trail: for example create (registering), checkin, update, dispense, receive, adjust, payment, price_change, password_change, login and logout. Each is a single transaction in the database.')

    d.p('The layout of the main input screens is shown in the figures that follow. The screenshots were taken from the working system with synthetic data. The menu and top bar are cropped from these pictures so that the form is easier to read; every page also shows them (see {ref:fig_dash_doc}).')
    inputs = [
        ('01-login', 'IN-01: the sign-in screen', 'fig_in01', 10.5, False),
        ('13-patient-register', 'IN-03: registering a patient', 'fig_in03', 14, True),
        ('14-patient-register-errors', 'IN-03: the same form with errors shown beside the fields', 'fig_in03e', 14, True),
        ('17-book-appointment', 'IN-06: booking an appointment (free and taken slots)', 'fig_in06', 14, True),
        ('25-triage-form', 'IN-08: recording triage', 'fig_in08', 14, True),
        ('29-consult-orders', 'IN-11 and IN-12: requesting tests and prescribing, on the consultation page', 'fig_in11', 14, True),
        ('35-lab-result-form', 'IN-13: entering a laboratory result', 'fig_in13', 14, True),
        ('38-dispense', 'IN-14: dispensing a prescription', 'fig_in14', 14, True),
        ('41-receive-stock', 'IN-16: receiving stock', 'fig_in16', 14, True),
        ('47-invoice-partial', 'IN-18: taking a payment on an invoice', 'fig_in18', 14, True),
        ('07-admin-user-form', 'IN-19: creating a user account', 'fig_in19', 14, True),
    ]
    for name, cap, label, w, crop in inputs:
        d.figure(shot_main(name) if crop else shot(name), cap, label=label, width_cm=w, max_height_cm=11.5)

    d.h3('5.6.2 System Output Design')
    d.p('Output is shown as pages, printed as invoices, receipts and reports, and downloaded as CSV files. {ref:tab_outputs} lists the outputs with a code, the page, the main users and the contents. '
        'Every data element on an output screen is defined in the data dictionary. Money is shown as KSh 1,000.00, dates as 24 Oct 2026, and statuses as words in coloured badges.')
    d.table(['Code', 'Output', 'Page', 'Primary users', 'Contents'], OUTPUTS, widths=[1.7, 2.9, 3.2, 2.6, 4.9], size=8, caption='Output Screens, Printouts and Files', label='tab_outputs')
    outs = [
        ('26-doctor-dashboard', 'OUT-01: the dashboard of a doctor, with the menu, header and footer that every page has', 'fig_dash_doc', 14, False),
        ('50-manager-dashboard', 'OUT-01: the dashboard of the hospital manager, with revenue and stock alerts', 'fig_dash_mgr', 14, False),
        ('15-patient-record-reception', 'OUT-03: a patient record as the receptionist sees it (no clinical details)', 'fig_out03', 14, True),
        ('16-appointment-diary', 'OUT-04: the appointment diary', 'fig_out04', 14, True),
        ('23-triage-queue', 'OUT-05: the triage queue', 'fig_out05', 14, True),
        ('31-my-test-results', 'OUT-07: the results of the tests a doctor requested', 'fig_out07', 14, True),
        ('39-stock', 'OUT-10: medicine stock with status and nearest expiry', 'fig_out10', 14, True),
        ('45-invoice-list', 'OUT-11: the invoice list with today\'s collections', 'fig_out11', 14, True),
        ('49-invoice-print', 'OUT-12: an invoice as printed', 'fig_out12', 12, False),
        ('48-receipt', 'OUT-13: a receipt', 'fig_out13', 12, False),
        ('51-report-attendance', 'OUT-14: the attendance report', 'fig_out14', 14, True),
        ('52-report-revenue', 'OUT-15: the revenue report', 'fig_out15', 14, True),
        ('53-report-stock', 'OUT-16: the stock report', 'fig_out16', 14, True),
        ('54-report-lab', 'OUT-17: the laboratory report', 'fig_out17', 14, True),
        ('08-admin-audit', 'OUT-19: the audit trail', 'fig_out19', 14, True),
        ('10-admin-health', 'OUT-20: the system health page', 'fig_out20', 14, True),
        ('21-access-denied', 'OUT-21: the "not allowed" page, shown when a role opens a page it does not hold', 'fig_out21', 14, True),
        ('56-mobile-menu', 'The dashboard on a narrow screen (390 pixels), with the menu behind a button', 'fig_mobile', 6.5, False),
    ]
    for name, cap, label, w, crop in outs:
        d.figure(shot_main(name) if crop else shot(name), cap, label=label, width_cm=w, max_height_cm=11.5)


def build_integrity(d):
    d.h2('5.7 System Integrity Controls')
    d.p('Patient records are sensitive information: loss, misuse, change or unauthorised access could harm patients and breach the law. The design therefore provides the minimum controls that sensitive systems need: restricted access, audit procedures, audit trails of access to critical data, standard tables for validation, verification of changes, and identification of every action by user, computer, date and time. '
        'Each control follows, with the requirement that states it and the test that proves it.')
    d.h3('5.7.1 Internal Security: Access Restricted to What Each Role Needs')
    d.p('{ref:tab_matrix} is the permission table, taken directly from the system. A dot means the role holds the permission. It is the only place where access is decided: every page asks for its permission before it runs. The system administrator holds no clinical permission, the manager holds no billing permission and the doctor holds no dispensing permission, so no one person can both do and hide an action (REQ-072; tests E2E-200 to E2E-224).')
    rows = []
    for perm, text in PERM_TEXT.items():
        rows.append([perm, text] + ['●' if perm in PERMS[r] else '' for r in ROLE_ORDER])
    d.table(['Permission', 'Allows'] + [ROLE_SHORT[r] for r in ROLE_ORDER], rows, widths=[3.0, 4.9] + [1.0] * 8, size=7.5, caption='Permission Table: Which Role Holds Which Permission', label='tab_matrix', align=['l', 'l'] + ['c'] * 8)
    d.p('Other internal controls are:')
    d.bullets([
        '**Identification.** Every user has a personal account; shared accounts are not offered. Passwords follow the rule in REQ-066 and are stored as bcrypt hashes.',
        '**Protection against guessing.** Five wrong passwords lock the account for 15 minutes, and a computer with 20 failures in 15 minutes is blocked.',
        '**Sessions.** A session ends after 30 idle minutes or 8 hours; the number changes at sign-in; sign-out and deactivation end the session at once.',
        '**Protection of data in transit and at the browser.** HTTPS is recommended, pages are sent with no-store caching, and a strict Content Security Policy blocks scripts that were not written for the system.',
        '**Protection in the database.** The application account can only add rows to the audit trail, payments and stock ledger, and cannot change the structure of the database.',
    ])
    d.h3('5.7.2 Audit Procedures and Application Audit Trails')
    d.p('The audit trail (table audit_log) records, for every important action, the user, the action code, the type and number of the record, a short description, the address of the computer and the date and time. '
        'It records retrieval as well as change: opening a patient record, searching for patients, opening the audit trail itself and exporting a report are all logged, so that curiosity about a patient can be discovered. '
        'It also records refused requests, failed sign-ins and forms sent with a wrong token. The action codes in use are: login, login_failed, login_blocked, logout, session_expired, password_change, password_change_failed, password_reset, unlock, create, update, view, search, status, cancel, auto_missed, checkin, close, start, test_action, import_preview, import, receive, adjust, dispense, payment, price_change, export, access_denied and csrf_rejected.')
    d.bullets([
        '**What it must not hold.** Names, phone numbers, diagnoses, laboratory results and payment reference codes are never written to the trail (REQ-077; tests E2E-033, E2E-115, E2E-146).',
        '**Who may read it.** The administrator and the manager. Reading it is itself recorded (E2E-252).',
        '**Who may change it.** Nobody. No page edits or deletes entries, and the database account of the application cannot.',
        '**Retention.** Entries are kept without limit, and the log grows by about 75 MB a year (section 5.5.5). The hospital must decide how long to keep them and archive old entries by a database administrator.',
        '**If writing fails.** A failure to write an audit entry is logged to the server file and appears as an error on the health page; it does not stop the clinical work in progress. This is a deliberate choice, so that a fault in the log cannot stop the care of a patient.',
    ])
    d.h3('5.7.3 Standard Tables for Validating Data Fields')
    d.p('Fields that hold coded values are validated against standard tables or fixed lists, so that they cannot hold free-form values. {ref:tab_std} lists them.')
    d.table(['Field', 'Validated against'], [
        ['users.role_id', 'Table roles (the eight roles)'],
        ['consultations.icd10_code', 'Table icd10_codes (the hospital\'s ICD-10 list; World Health Organization, 2019)'],
        ['consultations.service_id, visit_services.service_id', 'Table services (the price list)'],
        ['lab_requests.test_id', 'Table lab_tests (the test catalogue)'],
        ['prescription_items.medicine_id, medicine_batches.medicine_id', 'Table medicines'],
        ['status fields and payment method (ENUM)', 'Fixed lists in the table definition, for example Booked, Attended, Missed, Cancelled; Cash, M-Pesa, Card, Insurance'],
        ['Phone numbers, dates, money, names', 'Checked by the rules of section 4.3.2 before they are saved'],
    ], widths=[6.2, 9.1], caption='Standard Tables and Lists Used for Validation', label='tab_std', size=9)
    d.h3('5.7.4 Verification of Additions, Deletions and Changes of Critical Data')
    d.bullets([
        '**Additions.** Registering a patient with a similar name and date of birth needs a confirmation; a patient import needs a preview and a confirmation; a payment shows the receipt, which the cashier checks with the patient.',
        '**Changes.** Corrections are audited with the names of the fields changed; price changes are audited with the old and the new price; a consultation can be changed only by its own doctor; a corrected laboratory result is audited as an amendment.',
        '**Deletions.** The system deletes no patient, visit, clinical, financial, stock or audit record. Accounts are deactivated, not deleted; cancelled appointments and tests keep their record with the status Cancelled. Dangerous actions such as deactivating an account ask for confirmation first.',
        '**Reconciliation.** The health page compares invoice totals with their lines, amounts paid with payments, batch balances with the stock ledger, and dispensed quantities with prescriptions, and reports any difference (tests UT-INT-003, UT-INT-004).',
    ])
    d.h3('5.7.5 Identification of Audit Information')
    d.p('Every audit entry can be traced by user identification (user_id), terminal identification (ip_address, the address of the computer), date and time (created_at), and data accessed or changed (entity and entity_id, with a short description). '
        'The terminal address is the one the server sees. If a proxy server is placed in front of the system, the hospital must say so in the configuration (trusted_proxy); otherwise the system ignores headers a user could forge.')
    d.h3('5.7.6 Summary Against the OWASP Top 10')
    d.p('The design was reviewed against the OWASP Top 10 (OWASP Foundation, 2021) in Sprint 5. {ref:tab_owasp} summarises the review. Seven findings were corrected, and the whole test suite was run again.')
    d.table(['Category', 'Main controls in the design', 'Evidence'], [
        ['A01 Broken access control', 'One permission table; permission checked in every page; object rules (own consultation, own results)', 'E2E-200 to E2E-229'],
        ['A02 Cryptographic failures', 'bcrypt hashes; HttpOnly and SameSite=Strict cookie; no secrets in code; HTTPS at deployment', 'UT-AUT-009, E2E-230'],
        ['A03 Injection', 'Prepared statements only; escaped output; strict CSP; CSV formula guard', 'UT-COR-015, E2E-023, E2E-024, E2E-237 to E2E-245'],
        ['A04 Insecure design', 'Business rules enforced in the server and again in the database', 'UT-APT-014, UT-PHA-019, UT-BIL-019'],
        ['A05 Security misconfiguration', 'Security headers; internal folders refused; quiet error pages; least-privilege database account', 'E2E-002, APA-002 to APA-023'],
        ['A06 Vulnerable components', 'No third-party libraries; platform versions recorded (MariaDB 10.4 is past its support period)', 'Review'],
        ['A07 Authentication failures', 'Lockout, throttle, session limits, forced password change, neutral messages', 'UT-AUT-001 to UT-AUT-012, E2E-231 to E2E-236'],
        ['A08 Integrity failures', 'CSRF tokens on every POST; checked CSV import; no deserialisation', 'UT-INT-009, E2E-003, E2E-124'],
        ['A09 Logging failures', 'Audit trail of sign-ins, changes, views, refusals and exports; health page', 'UT-INT-002, E2E-226, E2E-249'],
        ['A10 Server-side request forgery', 'Not applicable: the application makes no outgoing requests', 'Code review'],
    ], widths=[4.0, 8.1, 3.2], caption='Review of the Design Against the OWASP Top 10', label='tab_owasp', size=8.5)


def build_references_glossary(d, cited):
    d.h2('5.8 Project References')
    d.p('This chapter rests on the following project documents, which were produced before it: the approved project proposal (Waweru, 2026), which gives the problem, objectives, scope and plan, and Chapter 4 of this report, the System Requirements Specification, which gives the requirements this design answers. '
        'The system itself, its tests and its security review are described in Chapters 6 and 7. The sources cited in this chapter follow in APA 7th edition style.')
    for key in sorted(cited, key=lambda k: REFERENCES[k].lower()):
        p = d.p(REFERENCES[key], align='left')
        _hanging(p)
    d.h2('5.9 Glossary')
    d.p('The terms and abbreviations used in this document are defined in Appendix A of Chapter 4 ({ref:tab_gloss}). Four terms are specific to the design and are added here.')
    d.table(['Term', 'Meaning'], [
        ['Append-only table', 'A table to which the application can add rows but not change or delete them: audit_log, payments and stock_movements.'],
        ['Generated column', 'A column whose value the database works out itself from other columns, such as appointments.slot_key.'],
        ['Process, module', 'A process is one of the six main parts of the level 1 data flow diagram; a module is a numbered part of a process in the structured chart.'],
        ['Tier', 'A layer of the architecture: presentation (browser), application (PHP) or data (MariaDB).'],
    ], widths=[4.3, 11.0], caption='Additional Terms Used in Chapter 5', label='tab_gloss5', size=9)


def _hanging(p):
    from docx.shared import Cm
    p.paragraph_format.left_indent = Cm(1.0)
    p.paragraph_format.first_line_indent = Cm(-1.0)
    p.paragraph_format.line_spacing = 1.15
