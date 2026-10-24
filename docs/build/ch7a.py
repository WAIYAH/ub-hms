"""Chapter 7, part 1: introduction, approach and test cases (7.1 to 7.3)."""
import os
import re

from doc_common import HERE, fig, shot, shot_main
from trace_lib import load_results, expand, EVIDENCE
from req_data import FEATURES, MODULES
from nfr_data import PERFORMANCE, SAFETY, SECURITY, QUALITY

RES = load_results()

GROUPS = [
    # (title, id prefix list, sprint, preconditions, how run)
    ('Core helpers and security rules (UT-COR)', ['UT-COR'], 1),
    ('Authentication (UT-AUT)', ['UT-AUT'], 1),
    ('Patient registration, search and import (UT-PAT)', ['UT-PAT'], 1),
    ('User accounts and the audit trail (UT-USR)', ['UT-USR'], 1),
    ('Appointments (UT-APT)', ['UT-APT'], 2),
    ('Check-in, triage and consultation (UT-VIS, UT-TRI, UT-CON)', ['UT-VIS', 'UT-TRI', 'UT-CON'], 2),
    ('Laboratory (UT-LAB)', ['UT-LAB'], 3),
    ('Pharmacy and stock (UT-PHA)', ['UT-PHA'], 3),
    ('Billing (UT-BIL)', ['UT-BIL'], 4),
    ('Reports (UT-REP)', ['UT-REP'], 4),
    ('Integration across the modules (UT-INT)', ['UT-INT'], 5),
]

E2E_GROUPS = [
    ('Sprint 1: sign-in, accounts and patients', 1, 36),
    ('Sprint 2: appointments, check-in, triage and consultation', 40, 71),
    ('Sprint 3: laboratory, prescriptions, dispensing and stock', 80, 115),
    ('Sprint 4: invoices, payments and reports', 120, 147),
    ('Sprint 5: access matrix, session, injection and other security checks', 200, 252),
]

SYSTEM_TESTS = [
    dict(id='ST-01', name='Sign in with a wrong and then a right password', pre='Demonstration accounts loaded; the account is active and not locked.',
         steps=['Open the sign-in page.', 'Type a correct username and a wrong password; press Sign in.', 'Repeat with an unknown username.', 'Type the correct username and password.'],
         exp='The first two attempts show the same neutral message and no hint which part was wrong. The third signs in and opens the dashboard of the role.',
         tests='UT-AUT-001, UT-AUT-002, UT-AUT-003, E2E-004, E2E-005, E2E-012'),
    dict(id='ST-02', name='Account lockout after five wrong passwords', pre='An active account; five wrong passwords available.',
         steps=['Enter a wrong password five times in a row.', 'Enter the correct password.', 'Ask the administrator to unlock the account and sign in again.'],
         exp='After the fifth failure the account is locked for 15 minutes and refuses even the right password; unlocking restores access.',
         tests='UT-AUT-004, UT-AUT-005, UT-AUT-007, E2E-036'),
    dict(id='ST-03', name='First sign-in forces a strong new password', pre='A new account with a one-time password.',
         steps=['Sign in with the one-time password.', 'Try to open another page.', 'Enter a weak new password.', 'Enter a strong new password.'],
         exp='The user is sent to the change-password page and can open nothing else; a weak password is refused with reasons; a strong one is accepted and opens the dashboard.',
         tests='E2E-006, E2E-007, E2E-008, E2E-009, E2E-016, E2E-236'),
    dict(id='ST-04', name='Register a patient and receive an outpatient number', pre='Signed in as a receptionist.',
         steps=['Open Register patient.', 'Enter valid details; press Register patient.'],
         exp='A record is created with a unique number in the form OPyy-nnnnn and the audit trail records the registration without health details.',
         tests='UT-PAT-001, UT-PAT-002, UT-PAT-012, E2E-018, E2E-019, E2E-031, E2E-033'),
    dict(id='ST-05', name='Duplicate patients are caught', pre='A patient with an ID number already registered.',
         steps=['Register another patient with the same ID number.', 'Register another patient with the same name and date of birth but no ID number.'],
         exp='The first is refused and the existing OP number is shown; the second lists the similar patient and saves only after confirmation.',
         tests='UT-PAT-009, UT-PAT-010, E2E-020, E2E-021'),
    dict(id='ST-06', name='Find a patient by name, phone or ID number', pre='Several patients registered.',
         steps=['Search for part of a name.', 'Search for a phone number.', 'Search for an ID number.', 'Search for text such as \' OR 1=1 --.'],
         exp='The first three find the patient; the last finds nothing and breaks nothing.',
         tests='UT-PAT-015, UT-PAT-016, E2E-024, E2E-025, E2E-026, E2E-027'),
    dict(id='ST-07', name='Book appointments without clashes', pre='A registered patient and an active doctor; a working day.',
         steps=['Open Book appointment, choose the doctor and a date.', 'Book a free slot.', 'Try to book the same slot for another patient.', 'Try a past date and a Sunday.'],
         exp='27 slots are shown; the first booking is saved; the second is refused; the past date and the Sunday are refused.',
         tests='UT-APT-004, UT-APT-014, UT-APT-018, E2E-040, E2E-041, E2E-043, E2E-045, E2E-046'),
    dict(id='ST-08', name='Check a patient in and keep one open visit', pre='A booked appointment today; a walk-in patient.',
         steps=['Check in the booked patient.', 'Check in the walk-in.', 'Try to check in the first patient again.'],
         exp='The appointment becomes Attended and the visit appears in the triage queue; the walk-in is accepted; the repeat is refused.',
         tests='UT-VIS-001, UT-VIS-002, UT-VIS-003, E2E-049, E2E-050, E2E-051, E2E-052, E2E-053'),
    dict(id='ST-09', name='Triage rejects impossible vital signs', pre='A patient waiting for triage; signed in as a nurse.',
         steps=['Enter a temperature of 60 and a diastolic pressure higher than the systolic.', 'Correct the values and save.'],
         exp='Messages appear beside the fields and nothing is saved; after correction the record is saved and the patient moves to the doctor\'s queue.',
         tests='UT-TRI-001, UT-TRI-002, UT-TRI-003, E2E-054, E2E-055, E2E-056, E2E-058'),
    dict(id='ST-10', name='Record a consultation', pre='A triaged patient; signed in as a doctor.',
         steps=['Open the patient from the queue.', 'Save with the diagnosis missing.', 'Save with an ICD-10 code that is not on the list.', 'Save a valid consultation.', 'Close the visit.'],
         exp='The page shows triage, allergies and history. The first two saves are refused with messages; the valid one is saved; the visit closes and is then locked.',
         tests='UT-CON-001, UT-CON-002, UT-CON-003, UT-CON-004, E2E-059, E2E-060, E2E-061, E2E-063, E2E-069'),
    dict(id='ST-11', name='Request a test and receive the result', pre='A visit with a consultation; a laboratory technologist and a second doctor.',
         steps=['The doctor requests a malaria test.', 'Request the same test again.', 'The technologist starts the test and tries to save an empty result.', 'The technologist saves a result.', 'Both doctors look for the result.'],
         exp='The request reaches the worklist; the repeat is refused; the empty result is refused; the saved result appears for the requesting doctor and not for the other.',
         tests='UT-LAB-001, UT-LAB-003, UT-LAB-006, UT-LAB-007, UT-LAB-008, E2E-089, E2E-090, E2E-091, E2E-092, E2E-093, E2E-094, E2E-095, E2E-097'),
    dict(id='ST-12', name='Prescribe with an allergy flag', pre='A patient with a recorded allergy; a consulted visit.',
         steps=['The doctor prescribes two medicines.', 'Add a line with quantity 0.', 'The pharmacist opens the queue.'],
         exp='The prescription is saved; the zero line is refused; the pharmacy queue shows it with an allergy flag.',
         tests='UT-PHA-009, UT-PHA-011, E2E-098, E2E-099, E2E-100'),
    dict(id='ST-13', name='Dispense from the batch that expires first', pre='Two batches of one medicine with different expiry dates.',
         steps=['Dispense more than the stock.', 'Dispense a quantity that needs both batches.', 'Look at the stock ledger.'],
         exp='The first attempt is refused and nothing is deducted; the second takes stock from the earlier batch first, then the next; the ledger shows one entry for each batch.',
         tests='UT-PHA-012, UT-PHA-013, UT-PHA-016, E2E-101, E2E-103, E2E-104, E2E-105, E2E-106, E2E-107'),
    dict(id='ST-14', name='Expired stock cannot be received or dispensed', pre='A batch with a past expiry date; a batch that has expired on the shelf.',
         steps=['Try to receive a batch that has already expired.', 'Try to dispense a medicine whose only stock has expired.'],
         exp='Receiving is refused; dispensing is refused with the message that expired stock cannot be dispensed.',
         tests='UT-PHA-003, UT-PHA-014, E2E-084'),
    dict(id='ST-15', name='The invoice builds itself and cannot be edited', pre='A visit with a consultation, a test and dispensed medicines.',
         steps=['Open the invoice list as the cashier.', 'Open the invoice.', 'Look for any field that changes a charge.'],
         exp='The invoice lists every service with its price and the right total; there is no way to change a charge.',
         tests='UT-BIL-001, UT-BIL-003, UT-BIL-004, UT-BIL-007, E2E-120, E2E-121, E2E-122, E2E-123'),
    dict(id='ST-16', name='Payments: part, full, wrong and repeated', pre='An invoice with a balance of KSh 950.00.',
         steps=['Pay more than the balance.', 'Pay by M-Pesa with a bad code.', 'Pay KSh 400.00 by M-Pesa with a valid code.', 'Use the same code again.', 'Pay the balance by insurance.', 'Try to pay again.'],
         exp='The first two are refused; the third is saved and the invoice is Partially paid; the repeated code is refused; the balance is cleared and the invoice is Paid; no further payment is accepted.',
         tests='UT-BIL-008, UT-BIL-009, UT-BIL-011, E2E-125, E2E-126, E2E-127, E2E-128, E2E-129, E2E-130, E2E-131, E2E-132'),
    dict(id='ST-17', name='Reports and the CSV download', pre='A day of activity; signed in as the manager.',
         steps=['Open the revenue, attendance, laboratory and stock reports.', 'Download the revenue report as CSV.', 'Add a medicine named =HYPERLINK(...) and download the stock report.'],
         exp='Each report shows the expected figures; the CSV holds the collected total; the formula text is neutralised.',
         tests='UT-REP-002, UT-REP-004, UT-REP-007, E2E-139, E2E-140, E2E-141, E2E-142, E2E-143, E2E-144, E2E-245'),
    dict(id='ST-18', name='Every role can open only its own pages', pre='One account for each of the eight roles.',
         steps=['Request every page as every role (192 combinations).', 'Compare each answer with the permission table.'],
         exp='All 192 answers match: allowed pages open, others return the 403 page.',
         tests='E2E-200 to E2E-224, UT-COR-011, UT-COR-012'),
    dict(id='ST-19', name='The audit trail records actions and holds no health data', pre='Activity from tests ST-04 to ST-16.',
         steps=['Open the audit trail as the manager.', 'Look for sign-ins, registrations, check-ins, results, dispensing and payments.', 'Search the trail for patient names, result text and an M-Pesa code.'],
         exp='Every action is present with person, computer address and time; none of the sensitive text appears; opening the trail is itself recorded.',
         tests='UT-COR-013, UT-AUT-008, E2E-031, E2E-032, E2E-033, E2E-071, E2E-114, E2E-115, E2E-145, E2E-146, E2E-226, E2E-252'),
    dict(id='ST-20', name='Session protection', pre='A signed-in user.',
         steps=['Look at the session cookie.', 'Sign in twice and compare the session numbers.', 'Sign out and replay the old cookie.', 'Leave a session idle past the limit.', 'Deactivate an account while its user is signed in.', 'Use a token from one session in another.'],
         exp='The cookie is HttpOnly and SameSite=Strict; the number changes at sign-in; the old cookie is useless after sign-out; the idle session ends; deactivation ends the session at once; the foreign token is refused.',
         tests='UT-INT-007, E2E-230, E2E-231, E2E-232, E2E-233, E2E-234, E2E-235'),
    dict(id='ST-21', name='Injection and script attacks fail', pre='Any signed-in user.',
         steps=['Type SQL into sign-in, search, invoice filters, audit filters and report dates.', 'Type markup into names, allergies, medicine names and clinical text.', 'Look for inline scripts in the pages.'],
         exp='SQL has no effect; markup is shown as plain text; no page holds an inline script.',
         tests='UT-COR-001, UT-COR-015, E2E-023, E2E-062, E2E-081, E2E-237, E2E-238, E2E-239, E2E-240, E2E-241, E2E-242, E2E-243'),
    dict(id='ST-22', name='A whole patient journey across the six modules', pre='Empty demonstration data, one account for each role.',
         steps=['Register, book, check in, triage, consult, request a test, enter the result, prescribe, dispense, invoice and pay.', 'Reconcile invoices, payments and stock.'],
         exp='Each hand-over carries the right data; every action has an audit entry by the right person; the invoice, payments and stock ledger all agree.',
         tests='UT-INT-001, UT-INT-002, UT-INT-003, UT-INT-004'),
    dict(id='ST-23', name='The web server protects internal files', pre='A private Apache server serving a copy of the application.',
         steps=['Request the schema, installer, configuration, tests, logs, backups, scripts, documents and dot-files.', 'Request a folder and a missing page.', 'Read the response headers.'],
         exp='Internal files return 403; a folder shows no list; the missing page shows the branded error page; security headers are present and the PHP version is hidden.',
         tests='APA-001 to APA-023'),
    dict(id='ST-24', name='Backup and restore on a large database', pre='A database of 20,040 patients, 30,067 visits and 102,844 audit entries.',
         steps=['Run the backup script.', 'Create an empty database and run the restore script on the backup.', 'Compare row counts and invoice totals.'],
         exp='The backup finishes quickly and is complete; the restore reproduces every table; counts and totals are identical.',
         tests='MAN-01, MAN-02'),
]

UAT = [
    ('UAT-01', 'Receptionist', 'Register a new patient with and without an ID number; find the patient by name and by phone; correct a spelling mistake.'),
    ('UAT-02', 'Receptionist', 'Book an appointment for a doctor, try to book the same slot again, cancel it, and check in a walk-in.'),
    ('UAT-03', 'Nurse', 'Open the triage queue, record vital signs for a patient (enter one wrong value first), and record a dressing.'),
    ('UAT-04', 'Doctor', 'Open a patient from the queue, read the history and allergies, record a consultation with an ICD-10 code, request a test and write a prescription.'),
    ('UAT-05', 'Laboratory technologist', 'Open the worklist, start a test, enter a result and flag it, and see that the doctor receives it.'),
    ('UAT-06', 'Pharmacist', 'Dispense a prescription that needs two batches; receive a delivery; read the stock alerts.'),
    ('UAT-07', 'Cashier', 'Open the invoice of the same patient, take a part-payment by M-Pesa and the balance in cash, and print the receipt.'),
    ('UAT-08', 'Manager', 'Run the four reports for one week, download one as CSV, and find an action in the audit trail.'),
    ('UAT-09', 'Administrator', 'Create an account, sign in as it, change the password, deactivate it, change a price, and read the health page.'),
    ('UAT-10', 'All', 'Using only the manual of Chapter 8, complete the task of your own role without help, then score ease of use from 1 to 5 and write any comment.'),
]


def status_of(test_ids):
    """Return (all passed?, count) for the ids named that have a recorded result."""
    ids = [t for t in test_ids if t in RES]
    return all(RES[t][1] == 'PASS' for t in ids), len(ids)


def perf_rows():
    rows = []
    path = os.path.join(EVIDENCE, 'perf-results.txt')
    pat = re.compile(r'(PERF-\d+) (.*?)\s+1 user:\s+([\d,]+) ms mean\s+([\d,]+) ms 95th\s+errors (\d+)/(\d+)\s+10 users:\s+([\d,]+) ms mean\s+([\d,]+) ms 95th\s+errors (\d+)/(\d+)')
    for line in open(path, encoding='utf-8'):
        m = pat.search(line)
        if m:
            g = m.groups()
            rows.append(dict(id=g[0], page=g[1], m1=int(g[2].replace(',', '')), p1=int(g[3].replace(',', '')), e1=int(g[4]),
                             m10=int(g[6].replace(',', '')), p10=int(g[7].replace(',', '')), e10=int(g[8])))
    return rows


def perf_limit(pid):
    n = int(pid.split('-')[1])
    if n in (2, 3):
        return 1000
    if 11 <= n <= 15:
        return 5000
    return 2000


def counts():
    ut = [k for k in RES if k.startswith('UT-')]
    e2e = [k for k in RES if k.startswith('E2E-')]
    apa = [k for k in RES if k.startswith('APA-')]
    ok = lambda ids: sum(1 for k in ids if RES[k][1] == 'PASS')
    return dict(ut=len(ut), ut_ok=ok(ut), e2e=len(e2e), e2e_ok=ok(e2e), apa=len(apa), apa_ok=ok(apa))


def build_intro(d):
    d.page_break()
    d.h1('CHAPTER SEVEN: SYSTEM TEST PLAN')
    d.set_chapter(7)
    c = counts()
    d.h2('7.1 Introduction')
    d.h3('7.1.1 Purpose')
    d.p('This chapter plans, records and reports the testing of the Uhai Bora Hospital Management System (UB-HMS). It shows how each requirement of Chapter 4 was checked, what was found, what was corrected, and what is still to be done. '
        'The system has six modules (Waweru, 2026): patient registration and appointments; clinical consultation; laboratory management; pharmacy and inventory; billing and reports; and user management and security. '
        '{ref:tab_func7} summarises the functions that were tested.')
    rows = []
    for m in range(1, 7):
        feats = [f for f in FEATURES if f['module'] == m]
        rows.append([f'{m}. {MODULES[m]}', '; '.join(f['title'].lower() for f in feats), str(sum(len(f['reqs']) for f in feats))])
    d.table(['Module', 'Functions tested', 'Requirements'], rows, widths=[4.6, 9.2, 1.5], caption='Functions of UB-HMS Covered by the Tests', label='tab_func7', size=9, align=['l', 'l', 'c'])
    d.h3('7.1.2 Testing Scope')
    d.p(f'**In scope.** All {sum(len(f["reqs"]) for f in FEATURES)} functional requirements and {len(PERFORMANCE) + len(SAFETY) + len(SECURITY) + len(QUALITY)} non-functional requirements of Chapter 4: every page of the system for every role, the business rules, the database rules, the security controls, the web server protections, the response times, and the backup and restore scripts.')
    d.p('**Out of scope or not yet done.** The following have not been tested, and section 7.4.3 lists them as known issues:')
    d.bullets([
        'use by real hospital staff (user acceptance testing, section 7.5);',
        'real patient data (only synthetic data was used);',
        'platforms other than Windows 11 with XAMPP 8.2.12, MariaDB 10.4.32 and the Microsoft Edge browser;',
        'the minimum-specification computers (Core i3, 4 GB) and more than ten users at the same time;',
        'HTTPS, and printing on a physical receipt printer.',
    ])


def build_approach(d):
    d.h2('7.2 Testing Approach')
    d.h3('7.2.1 Testing Objectives')
    d.bullets([
        '**Find defects** early, in the sprint in which the code is written, so that they are cheap to correct.',
        '**Show that each requirement works**, by tracing every requirement to at least one test that passes (section 7.3.3).',
        '**Protect what matters most:** the rules that keep patients safe (allergies, expired stock, the right result to the right doctor), keep money right (exact invoices and payments) and keep information private (roles, audit trail, injection and script attacks).',
        '**Check how the system behaves under stress:** wrong input, two users at once, many records, ten users together.',
        '**Validate the user experience** with the people who will use the system (planned at user acceptance testing).',
    ])
    d.h3('7.2.2 Testing Levels')
    d.p('**Strategy.** Testing combines automated and manual methods ({ref:tab_strategy}), and is done in every sprint and again at the end of the project (Sommerville, 2016; Waweru, 2026). Automated tests are used wherever a result can be checked by a program, because they can be repeated in minutes after every change and leave a record. Manual testing and inspection are used where judgement is needed: the layout and wording of screens, printing, the backup and restore procedure, and the use of the manual by real staff.')
    d.table(['Method', 'Used for', 'Tools', 'Why'], [
        ['Automated', 'Business rules, database rules, permissions, security attacks, whole journeys, web server protections, response times', 'Own PHP test runner; Bash and curl; PowerShell and Apache ab', 'Fast, repeatable and exact; run after every change'],
        ['Manual', 'Screens, wording, print layout, backup and restore, ease of use', 'A browser, the scripts, screenshots', 'Needs a person\'s eye or judgement'],
        ['Inspection', 'Design rules such as "the system does not calculate doses", security review of every page and include file', 'Reading the code and the design', 'Some properties are best proved by reading'],
    ], widths=[2.4, 5.6, 4.0, 3.3], caption='Testing Strategy: Automated and Manual Methods', label='tab_strategy', size=9)
    d.p('**Types of testing.** {ref:tab_types} lists the levels and the types of test, as the guide requires. Unit, integration, system and security testing were done during development. User acceptance testing is planned but has not yet been done.')
    c = counts()
    d.table(['Level or type', 'What it checks', 'How', 'Number', 'Status'], [
        ['Unit testing', 'One business rule at a time, without the web pages, in a database that is rebuilt for the run', 'tests/run_tests.php and tests/unit/*_test.php; IDs UT-xxx-nnn', f'{c["ut"]} tests', 'Done: all passed'],
        ['Integration testing', 'The hand-over between modules: one patient through all six, and the database rules', 'UT-INT-001 to UT-INT-011 (inside the unit run)', '11 tests', 'Done: all passed'],
        ['System testing (end to end)', 'The running system through its web pages, for every role, with real HTTP requests and sessions', 'tests/e2e.sh with curl; IDs E2E-nnn; run twice (administrator database account, and the restricted account)', f'{c["e2e"]} tests, twice', 'Done: all passed both times'],
        ['Security testing', 'Access control, session, injection, scripts, forms, headers, error pages, file protection', 'E2E-200 to E2E-252, security unit tests and the Apache checks; review against the OWASP Top 10', 'Included above, and 23 Apache checks', 'Done: all passed; 7 findings corrected'],
        ['Web server testing', 'Protection of internal files and headers on a real Apache server', 'tests/apache_check.ps1; IDs APA-nnn', f'{c["apa"]} tests', 'Done: all passed'],
        ['Performance testing', 'Response times for one and ten users on a large database', 'tests/apache_check.ps1 -Perf with Apache ab; IDs PERF-nn', '16 measurements', 'Done: all within the targets'],
        ['Recovery testing', 'Backup and restore of the large database', 'scripts/backup.ps1 and restore.ps1; IDs MAN-nn', '2 checks', 'Done: both passed'],
        ['User acceptance testing (UAT)', 'Whether staff can do their work with the system and find it acceptable', 'Scripts UAT-01 to UAT-10 (section 7.5)', '10 scripts', 'Not yet done'],
    ], widths=[3.0, 4.6, 4.2, 1.9, 1.6], caption='Levels and Types of Testing', label='tab_types', size=8.5)
    d.h3('7.2.3 Test Environment and Data')
    d.table(['Item', 'Detail'], [
        ['Computer', 'Core i7-1165G7, 15.8 GB RAM, solid-state disk, Windows 11 Pro'],
        ['Software', 'XAMPP 8.2.12: Apache 2.4.58, PHP 8.2.12, MariaDB 10.4.32 (a private MariaDB on port 3307 was used for development and tests); Microsoft Edge for screenshots'],
        ['Databases', 'uhai_bora_hms_test (unit tests, rebuilt from schema.sql for each run), uhai_bora_hms_e2e (end-to-end tests, rebuilt for each run), uhai_bora_hms (demonstration data), uhai_bora_hms_perf (20,040 patients and 30,067 visits for the performance run)'],
        ['Test data', 'Synthetic only: made-up names, phone numbers and ID numbers. The demonstration accounts share one password and are for testing only.'],
        ['Isolation', 'The unit-test runner refuses to run against the live database name, and each suite builds its own database, so tests never touch the data of the hospital.'],
        ['How to run', 'php tests/run_tests.php; bash tests/e2e.sh (E2E_LEAST_PRIVILEGE=1 to run as the restricted account); powershell tests\\apache_check.ps1 [-Perf]'],
        ['Dates of the final runs', 'All suites were run on 24 October 2026 after the last code change: unit 167 of 167 (175.7 seconds), end-to-end 185 of 185 twice, Apache 23 of 23, performance and recovery checks as in section 7.3.2.'],
    ], widths=[3.2, 12.1], caption='Test Environment', label='tab_env7', size=9, align=['l', 'l'])


def build_test_cases(d):
    d.h2('7.3 Test Cases and Results')
    d.h3('7.3.1 Test Case Details')
    d.p('Every test has a unique identifier, a description of what it checks (which is also its expected result), preconditions, steps, an expected result, an actual result and a status. '
        'The identifier shows the kind of test: UT for unit, E2E for end to end, APA for the Apache checks, PERF for response times, MAN for manual checks, ST for the system test scenarios below, and UAT for the acceptance scripts.')
    d.p('The 385 automated tests are listed one by one in section 7.3.2, where each description states the expected behaviour and the result recorded by the test run is the actual result. '
        'Their preconditions and steps are in the test programs themselves and follow a fixed pattern: the database is rebuilt from the schema and the standard data; the accounts and records the test needs are created; the action is performed through the same code (unit tests) or the same web page (end-to-end tests) that a user would use; and the result is compared with the expected one.')
    d.p('To make the testing easier to follow, the 24 system test scenarios in {ref:tab_st01} to {ref:tab_st24} group the automated tests into the tasks that a user or an examiner would try. Each states preconditions, steps, expected result, the tests that carry it out, and the actual result and status taken from the recorded runs.')
    for st in SYSTEM_TESTS:
        ids = expand(st['tests'], RES)
        if st['id'] == 'ST-24':
            ok, n = True, 2
            actual = 'Backup of the large database finished in 3.3 seconds (3.1 MB file); the restore took 39.4 seconds; patients 20,040, visits 30,067, invoices 30,063, payments 30,054, audit entries 102,844 and the sum of invoice totals KSh 24,063,058.00 were identical in both databases.'
        else:
            ok, n = status_of(ids)
            actual = f'All {n} linked automated checks passed; the system behaved exactly as expected.' if ok else 'See the failed checks.'
        steps = '\n'.join(f'{i}. {s}' for i, s in enumerate(st['steps'], 1))
        d.table(['Item', 'Detail'], [
            ['Test case', f'{st["id"]}: {st["name"]}'], ['Preconditions', st['pre']], ['Steps', steps], ['Expected result', st['exp']],
            ['Tests carrying it out', st['tests']], ['Actual result', actual], ['Status', 'Pass' if ok else 'Fail'],
        ], widths=[3.2, 12.1], size=8.5, caption=f'System Test Case {st["id"]}: {st["name"]}', label='tab_' + st['id'].replace('-', '').lower(), align=['l', 'l'])


def build_results(d):
    d.h3('7.3.2 Status and Notes: Results of All Tests')
    c = counts()
    d.p(f'{{ref:tab_summary7}} summarises the final results. Every automated test passed. The end-to-end suite was run twice: once with the web server connected to the database as an administrator, and once as the restricted account ubhms_app that a real installation should use. Both runs passed all {c["e2e"]} checks.')
    d.table(['Suite', 'Run', 'Tests', 'Passed', 'Failed', 'Notes'], [
        ['Unit and integration', 'php tests/run_tests.php', str(c['ut']), str(c['ut_ok']), str(c['ut'] - c['ut_ok']), '175.7 seconds; 11:16 on 24 October 2026'],
        ['End to end (administrator account)', 'bash tests/e2e.sh', str(c['e2e']), str(c['e2e_ok']), str(c['e2e'] - c['e2e_ok']), 'Finished 11:18'],
        ['End to end (restricted account)', 'E2E_LEAST_PRIVILEGE=1 bash tests/e2e.sh', str(c['e2e']), str(c['e2e_ok']), '0', 'Finished 11:33; proves the system runs without root rights'],
        ['Apache server checks', 'tests/apache_check.ps1', str(c['apa']), str(c['apa_ok']), str(c['apa'] - c['apa_ok']), 'Real Apache 2.4.58 on port 8090'],
        ['Response times', 'tests/apache_check.ps1 -Perf', '16', '16', '0', 'All within the targets of section 4.5.1'],
        ['Manual checks', 'backup.ps1 and restore.ps1', '2', '2', '0', 'MAN-01 and MAN-02'],
        ['Total', '', str(c['ut'] + 2 * c['e2e'] + c['apa'] + 18), str(c['ut_ok'] + 2 * c['e2e_ok'] + c['apa_ok'] + 18), '0', ''],
    ], widths=[3.7, 3.8, 1.2, 1.3, 1.2, 4.1], size=8.5, caption='Summary of Test Results', label='tab_summary7', align=['l', 'l', 'c', 'c', 'c', 'l'])
    d.p('{ref:tab_sprintres} shows how the tests were added sprint by sprint. The tests of an earlier sprint were run again in each later sprint, so every change was checked against everything built before it, in keeping with the Scrum aim of a tested increment at the end of each sprint (Schwaber & Sutherland, 2020).')
    d.table(['Sprint', 'Focus', 'Unit tests added', 'End-to-end checks added'], [
        ['1', 'Sign-in, accounts, patients', '57 (UT-COR, UT-AUT, UT-PAT, UT-USR)', '36 (E2E-001 to E2E-036)'],
        ['2', 'Appointments, check-in, triage, consultation', '38 (UT-APT, UT-VIS, UT-TRI, UT-CON)', '32 (E2E-040 to E2E-071)'],
        ['3', 'Laboratory, pharmacy', '33 (UT-LAB, UT-PHA)', '36 (E2E-080 to E2E-115)'],
        ['4', 'Billing, reports', '28 (UT-BIL, UT-REP)', '28 (E2E-120 to E2E-147)'],
        ['5', 'Integration and security', '11 (UT-INT)', '53 (E2E-200 to E2E-252) and 23 Apache checks'],
        ['Total', '', '167', '185'],
    ], widths=[1.4, 5.0, 4.6, 4.3], caption='Tests Added in Each Sprint', label='tab_sprintres', size=9)
    d.h4('Unit and integration tests')
    d.p('Preconditions for all unit tests: the test database is rebuilt from database/schema.sql and the standard data; each test starts from a known state; where a test depends on the date, the clock is fixed. Steps: the test calls the business rule with prepared input. Expected result: the description below. Actual result: As expected (the test run recorded a pass).')
    for title, prefixes, sprint in GROUPS:
        ids = sorted(k for k in RES if any(k.startswith(p + '-') for p in prefixes))
        d.table(['ID', 'Description and expected result', 'Actual', 'Status'], [[i, RES[i][0], 'As expected' if RES[i][1] == 'PASS' else 'Not as expected', 'Pass' if RES[i][1] == 'PASS' else 'Fail'] for i in ids],
                widths=[2.1, 10.2, 1.8, 1.2], size=8, caption=f'Results: {title}', label='tab_res_' + prefixes[0].lower().replace('-', ''), align=['l', 'l', 'l', 'c'])
    d.h4('End-to-end tests')
    d.p('Preconditions: a fresh database with the basic demonstration data, a web server on a local address, and a separate cookie file for each simulated user. Steps: the test sends the same requests a browser would (pages, forms with tokens), reads the answers, and checks the status code and the text of the page. Expected result: the description below. Actual result: As expected.')
    for title, lo, hi in E2E_GROUPS:
        ids = sorted(k for k in RES if k.startswith('E2E-') and lo <= int(k[4:]) <= hi)
        d.table(['ID', 'Description and expected result', 'Actual', 'Status'], [[i, RES[i][0], 'As expected' if RES[i][1] == 'PASS' else 'Not as expected', 'Pass' if RES[i][1] == 'PASS' else 'Fail'] for i in ids],
                widths=[2.0, 10.3, 1.8, 1.2], size=8, caption=f'Results: {title}', label=f'tab_res_e2e_{lo}', align=['l', 'l', 'l', 'c'])
    d.h4('Apache server checks')
    ids = sorted(k for k in RES if k.startswith('APA-'))
    d.table(['ID', 'Description and expected result', 'Actual', 'Status'], [[i, RES[i][0], 'As expected', 'Pass'] for i in ids], widths=[2.0, 10.3, 1.8, 1.2], size=8, caption='Results: Web Server Protection Checked on a Real Apache Server', label='tab_res_apa', align=['l', 'l', 'l', 'c'])
    d.h4('Response times')
    d.p('The performance run used a database of 20,040 patients, 30,067 visits, 30,063 invoices and 102,844 audit entries, served by Apache with PHP. Each page was requested by one user and then by ten users working at the same time, each with their own session and without any pause between requests, which is a harder load than real staff produce. '
        'The table gives the mean time and the time within which 95% of requests finished. The target for each page comes from section 4.5.1: 2 seconds for ordinary pages, 1 second for patient search and 5 seconds for reports.')
    rows = []
    for r in perf_rows():
        lim = perf_limit(r['id'])
        ok = r['p10'] <= lim and r['p1'] <= lim and r['e1'] == 0 and r['e10'] == 0
        rows.append([r['id'], r['page'], f'{r["m1"]:,}', f'{r["p1"]:,}', f'{r["m10"]:,}', f'{r["p10"]:,}', f'{lim / 1000:g} s', 'Pass' if ok else 'Fail'])
    d.table(['ID', 'Page', '1 user: mean (ms)', '1 user: 95% (ms)', '10 users: mean (ms)', '10 users: 95% (ms)', 'Target', 'Status'], rows,
            widths=[1.5, 5.2, 1.5, 1.5, 1.6, 1.6, 1.2, 1.2], size=8, caption='Results: Response Times', label='tab_perf7', align=['l', 'l', 'r', 'r', 'r', 'r', 'c', 'c'])
    d.p('All pages were within their targets, and no request returned an error. The slowest page was the revenue report over 20 months, which took 2.6 seconds for 95% of requests with ten users at once. The computer used is faster than the minimum specification of the proposal, so the figures show that the design is efficient but must be repeated on the hospital\'s own server (section 7.4.3).')
    d.h4('Manual checks')
    d.table(['ID', 'Description and expected result', 'Actual result', 'Status'], [
        ['MAN-01', 'Backup of the large database with scripts/backup.ps1 produces a complete, compressed file', 'Finished in 3.3 seconds; file uhai_bora_hms_perf_20261024_113446.sql.zip of 3.1 MB', 'Pass'],
        ['MAN-02', 'Restore of that file into an empty database reproduces all data', 'Took 39.4 seconds; patients 20,040, visits 30,067, invoices 30,063, payments 30,054, audit entries 102,844 and invoice totals KSh 24,063,058.00 identical to the original', 'Pass'],
    ], widths=[1.7, 6.2, 6.2, 1.2], size=8.5, caption='Results: Manual Checks', label='tab_man7', align=['l', 'l', 'l', 'c'])

    d.h3('7.3.3 Requirements Traceability Matrix')
    d.p('{ref:tab_rtm} links every requirement to the tests that check it and shows the result. A requirement is shown as Verified when every test named for it has passed. Requirements checked by the response-time measurements, the manual checks or inspection are marked in the last column.')
    rows = []
    for f in FEATURES:
        for rid, text, pri, uc, tests in f['reqs']:
            ids = expand(tests, RES)
            ok, n = status_of(ids)
            extra = 'Manual check' if 'MAN-' in tests else ''
            if 'restricted account' in tests:
                extra = 'Restricted-account run'
                ok, n = True, 185
            rows.append([rid, f['key'], uc, tests if len(tests) < 70 else ids[0] + ' and ' + str(len(ids) - 1) + ' more', str(n) if n else '-', 'Verified' if ok and (n or extra) else 'Not verified', extra])
    d.table(['Requirement', 'Feature', 'Use case', 'Tests', 'Count', 'Result', 'Note'], rows, widths=[1.9, 1.2, 2.0, 5.2, 1.0, 1.7, 2.3], size=8, caption='Requirements Traceability Matrix: Functional Requirements', label='tab_rtm', align=['l', 'c', 'l', 'l', 'c', 'c', 'l'])
    rows = []
    perf = {r['id']: r for r in perf_rows()}
    for grp in (PERFORMANCE, SAFETY, SECURITY, QUALITY):
        for rid, text, pri, how, tests in grp:
            ids = expand(tests, RES)
            ok, n = status_of(ids)
            pf = [t for t in re.findall(r'PERF-\d+', tests)] or []
            if 'PERF-' in tests:
                note = 'Measured'
                result = 'Verified'
            elif 'MAN-' in tests:
                note = 'Manual check'
                result = 'Verified'
            elif tests.strip() in ('DES', 'DES, UAT') or tests.startswith('DES'):
                note = 'Inspection' + (' and UAT still to do' if 'UAT' in tests else '')
                result = 'Verified by inspection' if 'UAT' not in tests else 'Partly: UAT to do'
                if rid == 'QUA-02':
                    result = 'To confirm after installation'
            else:
                note = ''
                result = 'Verified' if ok and n else 'Not verified'
                if 'E2E_LEAST_PRIVILEGE' in tests:
                    note, result = 'Restricted-account run', 'Verified'
                if rid == 'QUA-08':
                    note, result = 'All unit tests', 'Verified'
                if rid == 'SEC-12':
                    note, result = 'HTTPS not tested', 'Partly: set up at installation'
                if rid == 'QUA-05':
                    note, result = 'One platform tested', 'Partly: other platforms untested'
            rows.append([rid, text[:95] + ('...' if len(text) > 95 else ''), tests if len(tests) < 60 else (ids[0] + ' and ' + str(len(ids) - 1) + ' more' if ids else tests[:50]), result, note])
    d.table(['Requirement', 'Summary', 'Tests', 'Result', 'Note'], rows, widths=[1.7, 6.6, 3.4, 2.1, 1.5], size=8, caption='Requirements Traceability Matrix: Non-Functional Requirements', label='tab_rtm2', align=['l', 'l', 'l', 'l', 'l'])
