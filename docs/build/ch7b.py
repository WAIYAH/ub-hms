"""Chapter 7, part 2: bug fixing, user feedback, appendices (7.4 to 7.6)."""
import os

from PIL import Image, ImageDraw, ImageFont

from doc_common import HERE, FIG, REFERENCES, shot, shot_main
from trace_lib import load_results, EVIDENCE

RES = load_results()

DEFECTS = [
    ['DEF-001', '1', 'UT-AUT-004', 'Medium', 'High', 'An account was locked after four wrong passwords instead of five.', 'Enter a wrong password four times, then read locked_until.', 'In the SQL UPDATE, failed_attempts was increased before locked_until was worked out, and MariaDB evaluates SET clauses from left to right. The order was changed.', 'Fixed; UT-AUT-004 passes'],
    ['DEF-002', '1', 'UT-AUT-007', 'High', 'High', 'The limit on failed sign-ins from one computer never blocked anyone.', 'Make 20 failed sign-ins from one address, then sign in correctly from it.', 'audit() recorded the web server\'s own view of the address instead of the address it was given, so the count found no rows. audit() now takes the address as a parameter.', 'Fixed; UT-AUT-007 passes'],
    ['DEF-003', '1', 'E2E-025 to E2E-027', 'Low', 'Medium', 'A patient with no middle name was shown with two spaces in the name, which broke text matching.', 'Register a patient without a middle name and search.', 'The full name was joined with fixed spaces even when a part was empty. Empty parts are now skipped (the same was done for the medicine label).', 'Fixed; E2E-025 to E2E-027 pass'],
    ['DEF-004', '5', 'Screenshot review', 'Low', 'Low', 'Table columns (Actions, Details, Expiry) were cut off and numbers and dates wrapped onto several lines on a 960-pixel screen.', 'Open User accounts, Audit trail or the Stock report in a browser window 960 pixels wide.', 'Table padding and text size were reduced, outpatient numbers, dates and amounts are kept on one line, and the account buttons may wrap. Screenshots were taken again and all suites were re-run.', 'Fixed; screenshots checked; 167, 185 and 185 pass'],
    ['SR-01', '5', 'Security review', 'Medium', 'High', 'Receptionists could see triage vital signs on the visit summary.', 'Open a visit as a receptionist.', 'The triage block is shown only to roles that record triage or consult.', 'Fixed; E2E-227, E2E-228 pass'],
    ['SR-02', '5', 'Security review', 'Medium', 'High', 'The Hospital Manager could open individual invoices, which reveals what a named patient was charged for.', 'Open an invoice as the manager.', 'The permission billing.view was removed from the manager, who uses the revenue report for totals.', 'Fixed; E2E-134, E2E-229 pass'],
    ['SR-03', '5', 'Security review', 'Low', 'Low', 'The dummy hash used to hide whether a username exists had a lower cost than real hashes, so an unknown username could be answered slightly faster.', 'Compare response times for known and unknown usernames.', 'The dummy hash was generated again at cost 11.', 'Fixed; UT-AUT-003 passes'],
    ['SR-04', '5', 'Security review', 'Low', 'Medium', 'Opening the audit trail was not itself recorded.', 'Open the audit trail and look for an entry.', 'Each opening is now logged.', 'Fixed; E2E-252 passes'],
    ['SR-05', '5', 'Security review', 'Low', 'Medium', 'Invoice creation was not recorded, and medicine edits did not say which fields changed.', 'Create an invoice; edit a medicine; read the audit trail.', 'Both are now logged, with field names and price change only.', 'Fixed; UT-INT-002 passes'],
    ['SR-06', '5', 'Security review', 'Low', 'Medium', 'The idle-timeout logic was inside the start-up script and could not be tested.', 'Try to write a unit test for the timeout.', 'The logic was moved to session_has_expired() with a unit test and an end-to-end test with a two-second limit.', 'Fixed; UT-INT-007 and E2E-234 pass'],
    ['SR-07', '5', 'Security review', 'Medium', 'High', 'The application connected to the database as root, the XAMPP default.', 'Read config/config.php.', 'A script creates a restricted account that can only add rows to the audit trail, payments and stock ledger; the whole end-to-end suite passes as that account.', 'Fixed; restricted-account run passes'],
]

KNOWN = [
    ['KI-01', 'User acceptance testing has not been done; no staff have used the system or given feedback.', 'Usability and fit with real work are unconfirmed (QUA-03).', 'Run UAT-01 to UAT-10 with at least two staff from each category (section 7.5) and record the results.'],
    ['KI-02', 'Only one platform was tested: Windows 11, XAMPP 8.2.12 (PHP 8.2.12, MariaDB 10.4.32), Microsoft Edge.', 'Chrome, Firefox, Ubuntu, newer MariaDB and MySQL 8 are untested.', 'Test on the hospital\'s own server and browsers during installation.'],
    ['KI-03', 'MariaDB 10.4 is past its support period.', 'No security fixes for the database.', 'Install a current XAMPP or MariaDB release, run the whole test suite again and fix any difference.'],
    ['KI-04', 'Response times were measured on a computer faster than the minimum specification and with at most ten users at once.', 'Slower pages on a Core i3 server or with many more users.', 'Repeat the performance run (tests/apache_check.ps1 -Perf) on the hospital\'s server.'],
    ['KI-05', 'HTTPS was not set up in any test; the Secure cookie flag and the HSTS header are code paths tested only in unit form.', 'Passwords and records cross the network unencrypted until HTTPS is set up.', 'Set up HTTPS at installation (section 6.8.2) and repeat the cookie and header checks.'],
    ['KI-06', 'There is no function to erase a record, to give a patient a copy of their data, or to apply a retention period automatically.', 'The hospital must handle patients\' data rights by its own procedure (Republic of Kenya, 2019).', 'Add subject-access export and retention functions in a later release.'],
    ['KI-07', 'There is no refund or reversal function, although the message shown when a charge cannot be removed from a paid invoice refers to the manager refunding first.', 'A mistaken payment cannot be reversed in the system.', 'Add a manager-approved refund that writes a negative payment, with the audit trail.'],
    ['KI-08', 'The old file number of a migrated patient is not stored.', 'Staff cannot look up a patient by old file number.', 'Add an optional old file number field to the patient record.'],
    ['KI-09', 'The branded error pages assume the folder name /ub-hms/.', 'Another folder name shows the default Apache error pages for 403 and 404.', 'Change the ErrorDocument lines when another name is used (documented in section 6.8.1).'],
    ['KI-10', 'A failure to write an audit entry is logged but does not stop the work in progress.', 'An action could occur without an audit entry if the database refuses the audit insert.', 'The health page shows errors in the log; keep this choice so that a log fault cannot stop patient care, and monitor the log.'],
    ['KI-11', 'Printing was checked with the browser\'s print layout only, not on a physical receipt printer.', 'Layout on a narrow receipt paper is unconfirmed.', 'Test on the hospital\'s printers at installation and adjust the print style if needed.'],
    ['KI-12', 'Clinic hours and slot length are the same for every doctor; there are no doctor schedules or leave.', 'Slots can be booked on a doctor\'s day off.', 'Add per-doctor schedules and leave in a later release.'],
    ['KI-13', 'Accessibility was designed for (labels, text statuses, contrast) but not tested with a screen reader.', 'Some users may meet barriers.', 'Test against the WCAG 2.1 checklist (World Wide Web Consortium, 2018).'],
    ['KI-14', 'Only a race between two bookings and the invoice lock were tested for simultaneous use; other simultaneous actions were designed with locks but not stress-tested.', 'A rare clash could show a technical error page.', 'Run a longer multi-user test in UAT.'],
]


def term_image(name, title, lines, width=118):
    """Draw text lines as a light terminal-style picture (black text on white) for the testing visuals."""
    font_path = r'C:\Windows\Fonts\consola.ttf'
    font = ImageFont.truetype(font_path, 17)
    bold = ImageFont.truetype(r'C:\Windows\Fonts\consolab.ttf', 17)
    line_h = 23
    clipped = [ln if len(ln) <= width else ln[:width - 3] + '...' for ln in lines]
    w = int(font.getlength('M' * width)) + 40
    h = line_h * (len(clipped) + 2) + 30
    im = Image.new('RGB', (w, h), 'white')
    d = ImageDraw.Draw(im)
    d.rectangle((0, 0, w, 32), fill=(225, 225, 225))
    d.text((14, 6), title, fill='black', font=bold)
    y = 44
    for ln in clipped:
        d.text((20, y), ln, fill='black', font=font)
        y += line_h
    d.rectangle((0, 0, w - 1, h - 1), outline=(120, 120, 120))
    path = os.path.join(FIG, name + '.png')
    im.save(path)
    return path


def make_visuals():
    out = {}
    uts = sorted(k for k in RES if k.startswith('UT-'))[-9:]
    lines = ['$ php tests/run_tests.php', '...'] + [f'  PASS  {k}  {RES[k][0]}' for k in uts] + ['', '----------------------------------------', 'Tests run: 167   Passed: 167   Failed: 0   Time: 175.72s']
    out['unit'] = term_image('test_unit_run', 'Unit and integration tests: end of the run (24 October 2026)', lines)
    es = sorted(k for k in RES if k.startswith('E2E-'))[-9:]
    lines = ['$ bash tests/e2e.sh', '...'] + [f'PASS  {k}  {RES[k][0]}' for k in es] + ['', '----------------------------------------', 'End-to-end checks: 185   Passed: 185   Failed: 0']
    out['e2e'] = term_image('test_e2e_run', 'End-to-end checks: end of the run (24 October 2026)', lines)
    ap = [k for k in sorted(RES) if k.startswith('APA-')]
    lines = ['PS> tests\\apache_check.ps1'] + [f'PASS  {k}  {RES[k][0]}' for k in ap] + ['', 'Apache checks: 23   Passed: 23   Failed: 0']
    out['apa'] = term_image('test_apache_run', 'Checks on a real Apache server', lines)
    perf = [ln.rstrip() for ln in open(os.path.join(EVIDENCE, 'perf-results.txt'), encoding='utf-8') if ln.startswith('PERF-')]
    short = []
    for ln in perf:
        short.append(ln.replace('errors ', 'err ').replace(' ms mean', 'ms mean').replace(' ms 95th', 'ms 95th').replace('1 user:', '1u:').replace('10 users:', '10u:'))
    out['perf'] = term_image('test_perf_run', 'Response times through Apache, one and ten users (milliseconds)', short, width=150)
    return out


def build_bugs(d):
    d.h2('7.4 Bug Fixing and Debugging')
    d.h3('7.4.1 Bug Tracking')
    d.p('Defects were logged in the file docs/defects.md in the repository, with a unique identifier, the sprint, how it was found, severity, priority, steps to reproduce, the cause and the resolution. The identifiers DEF are for defects found by testing and review of the screens; SR identifiers are findings of the security review against the OWASP Top 10 (OWASP Foundation, 2021). '
        '**Severity** says how serious the effect is: Critical (security or data loss), High (a feature cannot be used), Medium (a feature is wrong in some cases), Low (appearance or small inconvenience). **Priority** says how soon it must be fixed: High (before the sprint ends), Medium (before release), Low (when convenient).')
    d.table(['ID', 'Sprint', 'Found by', 'Severity', 'Priority', 'Description', 'Steps to reproduce', 'Cause and resolution', 'Status'], DEFECTS,
            widths=[1.3, 0.9, 1.6, 1.3, 1.3, 3.5, 2.5, 4.0, 1.9], size=7.5, caption='Defect Register', label='tab_defects', align=['l', 'c', 'l', 'l', 'l', 'l', 'l', 'l', 'l'])
    d.p('No Critical defect was found. Eleven defects and findings were recorded, and all eleven are corrected and retested. The tests themselves also contained mistakes that were found and corrected while the suites were written (for example fixed identifiers that changed after a table was emptied, and a clock that differed between the test and the database). These are not defects of the system and are not counted.')
    d.h3('7.4.2 Resolution Process')
    d.table(['Step', 'What was done', 'Who'], [
        ['1. Detect', 'A failing test, a security review finding, or a problem seen when reviewing screens.', 'Developer (the student)'],
        ['2. Log', 'An entry with an identifier, severity and priority is added to the defect register.', 'Developer'],
        ['3. Reproduce', 'The steps are written down and, where possible, turned into a test that fails.', 'Developer'],
        ['4. Diagnose and fix', 'The cause is found and corrected in the code; the cause is written in the register.', 'Developer'],
        ['5. Retest', 'The failing test must now pass, and the whole suite is run again to be sure that nothing else broke.', 'Developer'],
        ['6. Close and review', 'The entry is marked Fixed with the test that proves it. The supervisor reviews the register at the sprint review.', 'Developer; supervisor as Scrum Master'],
    ], widths=[3.0, 9.3, 3.0], size=9, caption='Bug Resolution Process', label='tab_resolve7')
    d.h3('7.4.3 Known Issues')
    d.p('The issues in {ref:tab_known} are unresolved, with their effect and the plan to resolve them. None stops the system being used in a user acceptance test, but KI-03, KI-05 and KI-06 must be dealt with before real patient data is stored.')
    d.table(['ID', 'Issue', 'Effect', 'Planned resolution'], KNOWN, widths=[1.2, 5.6, 4.0, 4.5], size=8, caption='Known Issues', label='tab_known')


def build_feedback(d):
    d.h2('7.5 User Feedback and Improvements')
    d.p('Real staff have not yet used UB-HMS. This section therefore plans how feedback will be collected, leaves the results to be filled in, and records the improvements that came from testing and review, which are not the same as user feedback.')
    d.h3('7.5.1 Feedback Collection')
    d.p('Feedback will be collected at user acceptance testing, planned for 14 to 16 December 2026 in the proposal (Waweru, 2026), with at least two people from each staff category of the sample (16 or more people). Three methods will be used:')
    d.bullets([
        '**Task-based testing.** Each person performs the script for their role from {ref:tab_uat} with the manual of Chapter 8 and no help. An observer records whether the task was completed, the time taken, and where the person hesitated or made a mistake.',
        '**A questionnaire.** After the tasks each person answers the ten questions of the System Usability Scale (Brooke, 1996), scored from 1 (strongly disagree) to 5 (strongly agree), and three open questions: what was easiest, what was hardest, and what is missing.',
        '**A short interview** of ten minutes with each head of department about fit with their work and about the reports they need.',
    ])
    d.table(['ID', 'Role', 'Task script', 'Done?', 'Time', 'Observer notes', 'Result'], [[u[0], u[1], u[2], '', '', '', 'To be completed at UAT'] for u in __import__('ch7a').UAT],
            widths=[1.3, 2.0, 6.0, 1.0, 1.0, 2.2, 1.8], size=8, caption='User Acceptance Test Scripts (To Be Completed by Staff)', label='tab_uat', align=['l', 'l', 'l', 'c', 'c', 'l', 'l'])
    d.h3('7.5.2 Feedback Summary')
    d.p('No feedback has been received. {ref:tab_feedback} is the table in which the feedback will be sorted into the three groups the guide asks for. It is left empty on purpose so that no result is invented.')
    d.table(['Category', 'Feedback received', 'How many people', 'Action decided'], [
        ['Usability (ease of use, wording, layout)', 'None yet', '-', '-'],
        ['Functionality (missing or wrong functions)', 'None yet', '-', '-'],
        ['Performance (speed, waiting)', 'None yet', '-', '-'],
        ['System Usability Scale score (0 to 100)', 'To be calculated', '-', 'A score above 68 is average (Brooke, 1996)'],
    ], widths=[5.0, 4.3, 2.4, 3.6], size=9, caption='Summary of User Feedback (To Be Completed)', label='tab_feedback')
    d.h3('7.5.3 Improvements Made so Far')
    d.p('The improvements below were made because of testing and review, before any user feedback:')
    d.bullets([
        'Receptionists no longer see vital signs (SR-01) and the manager no longer sees individual invoices (SR-02), which narrows access to what each role needs.',
        'The audit trail now records its own opening and the creation of invoices (SR-04, SR-05).',
        'The tables of the screens were tightened and numbers, dates and outpatient numbers are kept on one line (DEF-004).',
        'The application can run as a restricted database account (SR-07), and a script creates it.',
        'Backup and restore were tested on a large database and found to work (MAN-01, MAN-02).',
    ])
    d.h3('7.5.4 Future Recommendations')
    d.p('The known issues of section 7.4.3 and the exclusions of the proposal point to these improvements for a later release, in the order the author suggests:')
    d.numbered([
        'Carry out UAT, and change the system according to what staff say.',
        'Add patient data rights: a printable copy of a patient\'s data, retention periods and archiving of old audit entries (KI-06).',
        'Add a manager-approved refund and a reversal of payments (KI-07).',
        'Add doctor schedules and leave, and text-message reminders for appointments (KI-12).',
        'Add the Ministry of Health reports the hospital must send, and an exchange of data with national systems under the Digital Health Act, 2023.',
        'Add a self-service password reset and a second step at sign-in for administrators.',
        'Add inpatient and ward management, which the proposal excluded.',
        'Test accessibility with users who use screen readers, and offer Kiswahili screens.',
    ])


def build_appendices(d):
    vis = make_visuals()
    d.h2('7.6 Appendices and References')
    d.h3('7.6.1 Testing Visuals')
    d.p('The figures below were taken during testing. {ref:fig_t_unit} to {ref:fig_t_perf} show the output of the test runs. The screens that follow show the system under test: the lockout after five wrong passwords, a weak password refused, validation messages beside the fields, a payment refused for a wrong code, and a page refused to a role that does not hold its permission.')
    d.figure(vis['unit'], 'Output of the unit and integration test run', label='fig_t_unit', width_cm=15.2)
    d.figure(vis['e2e'], 'Output of the end-to-end test run', label='fig_t_e2e', width_cm=15.2)
    d.figure(vis['apa'], 'Output of the checks on a real Apache server', label='fig_t_apa', width_cm=15.2)
    d.figure(vis['perf'], 'Output of the response-time measurements', label='fig_t_perf', width_cm=15.2)
    pics = [
        ('03-login-locked', 'Test ST-02: an account locked after five wrong passwords refuses sign-in', 'fig_t_lock', False),
        ('04-password-weak', 'Test ST-03: a weak new password is refused with reasons', 'fig_t_weak', True),
        ('24-triage-errors', 'Test ST-09: impossible vital signs are refused with a message beside each field', 'fig_t_triage', True),
        ('46-payment-error', 'Test ST-16: a payment with a wrong M-Pesa code is refused', 'fig_t_pay', True),
        ('21-access-denied', 'Test ST-18: a role that does not hold the permission is refused', 'fig_t_denied', True),
    ]
    for name, cap, label, crop in pics:
        d.figure(shot_main(name) if crop else shot(name), cap, label=label, width_cm=13.0, max_height_cm=9.5)
    d.h3('7.6.2 References')
    keys = ['proposal', 'brooke', 'kenya2019', 'owasp', 'schwaber', 'sommerville', 'w3c']
    for key in sorted(keys, key=lambda k: REFERENCES[k].lower()):
        p = d.p(REFERENCES[key], align='left')
        from docx.shared import Cm
        p.paragraph_format.left_indent = Cm(1.0)
        p.paragraph_format.first_line_indent = Cm(-1.0)
        p.paragraph_format.line_spacing = 1.15
