"""Chapter 4, part 3: interfaces, non-functional and other requirements, references, glossary (4.4 to 4.7, Appendix A)."""
from doc_common import GLOSSARY, REFERENCES
from nfr_data import PERFORMANCE, SAFETY, SECURITY, QUALITY


def nfr_table(d, rows, caption, label):
    d.table(['ID', 'Requirement', 'Priority', 'How it is checked', 'Evidence'],
            [[r[0], r[1], r[2], r[3], r[4]] for r in rows], widths=[1.4, 6.4, 1.3, 3.4, 2.8], size=8.5,
            caption=caption, label=label, align=['l', 'l', 'c', 'l', 'l'])


def build_interfaces(d):
    d.h2('4.4 External Interface Requirements')
    d.h3('4.4.1 User Interfaces')
    d.p('Staff use UB-HMS through a web browser. The interface has the same layout on every page, so that a user who has learned one screen can use the others (Nielsen, 1994; Shneiderman et al., 2016). The detailed designs and screenshots are in section 5.6. The requirements are:')
    d.bullets([
        '**Layout.** A header shows the name of the system, the user\'s name and role, and the Password and Sign out buttons. A menu on the left lists only the pages the role may open. The work is done in the main area, and a footer states that the system is confidential.',
        '**Standard buttons.** The main action of a form is a solid button (for example Save or Register patient); secondary actions are outlined buttons; dangerous actions are red and ask for confirmation. Invoices, receipts and reports have a Print button, and reports have Download CSV.',
        '**Forms.** Every field has a visible label. Required fields are marked. A hint under a field shows the expected form, for example a phone number as 0712 345 678.',
        '**Error messages.** When a form is wrong, the system keeps what the user typed, shows a list of the problems at the top and a message beside each wrong field, in plain words that say how to put it right.',
        '**Messages.** A green message confirms a saved action, an amber message warns, and a red message reports a refusal. Statuses such as Paid or Low are written in words as well as coloured.',
        '**Keyboard.** All functions can be used with the keyboard. The search box has the focus when the search page opens.',
        '**Screen size.** Pages fit a normal office screen and also a narrow screen of 390 pixels, where the menu folds behind a button. Printed pages show no menu.',
        '**Language and format.** English; dates as 24 Oct 2026; money as KSh 1,000.00; times in 24-hour form.',
    ])
    d.h3('4.4.2 Hardware Interfaces')
    d.p('UB-HMS needs no special hardware. Staff computers need a screen, keyboard and mouse. Invoices, receipts and reports are printed through the browser to any office printer, and receipts can use a receipt printer that the operating system offers as an ordinary printer. '
        'The server needs a wired network connection and a UPS. Barcode readers, fingerprint readers and laboratory instruments are not connected, because they are outside the scope; results are typed in by the technologist.')
    d.h3('4.4.3 Software Interfaces')
    d.table(['Software', 'Version used', 'Purpose and data exchanged'], [
        ['Apache HTTP Server', '2.4.58 (XAMPP)', 'Receives requests from browsers and returns pages. Its .htaccess rules refuse internal files and switch off directory listing.'],
        ['PHP', '8.2.12', 'Runs the application. Uses PDO (MySQL driver), mbstring, session and hash functions only.'],
        ['MariaDB', '10.4.32 (XAMPP)', 'Stores all data in 22 InnoDB tables. The application sends SQL through PDO using prepared statements.'],
        ['Web browser', 'Edge tested; Chrome and Firefox expected', 'Shows pages. Sends forms and keeps the session cookie.'],
        ['Operating system', 'Windows 11 Pro (tested)', 'Runs the server; Task Scheduler runs the nightly backup.'],
        ['PowerShell and mysqldump', 'PowerShell 5.1 or later', 'The backup and restore scripts write and read the database dump file.'],
        ['Spreadsheet program', 'Any', 'Opens CSV files exported from reports and supplies CSV files for patient import.'],
    ], widths=[3.4, 3.4, 8.5], caption='Software Interfaces', label='tab_sw')
    d.p('Data comes into the system from staff (forms) and from CSV files (patient import). Data goes out as pages, printouts and CSV downloads. No other program reads or writes the database, and the system calls no external service.')
    d.h3('4.4.4 Communications Interfaces')
    d.bullets([
        'Browsers and the server communicate with HTTP on the hospital\'s wired network; the server should offer HTTPS where the network is not private, because HTTPS stops other users of the network from reading passwords and records (Republic of Kenya, 2019, section 41). The system works over either, and the health page warns when a page was served without HTTPS.',
        'Each request is a normal page request or a form POST. Sessions use a cookie that is HttpOnly and SameSite=Strict.',
        'The database accepts connections from the server computer only. Text is encoded as UTF-8 throughout, and times are in Africa/Nairobi.',
        'The system sends no e-mail or text messages and needs no internet connection.',
    ])


def build_nonfunctional(d):
    d.h2('4.5 Other Nonfunctional Requirements')
    d.p('Non-functional requirements say how well the system must work. Each has a tag (PER, SAF, SEC or QUA), a priority and a way of checking it, and where possible a figure that can be measured. '
        'The last column names the tests or measurements that provide the evidence; their results are in Chapter 7.')
    d.h3('4.5.1 Performance Requirements')
    d.p('Staff will not wait for a screen while a patient stands at the desk, so the targets are given in time to serve a page. The figures were measured through a real Apache server with ten separate sessions at once, on a database holding 20,000 patients, 30,000 visits, 30,000 invoices and 100,000 audit entries (a volume the hospital would reach after several months of use). '
        'The development computer is faster than the minimum specification, so the measured times show the design is efficient but must be confirmed on the hospital\'s own server.')
    nfr_table(d, PERFORMANCE, 'Performance Requirements', 'tab_per')
    d.h3('4.5.2 Safety Requirements')
    d.p('A hospital system can harm a patient indirectly, by showing wrong information or by letting something unsafe happen. The requirements in {ref:tab_saf} reduce that risk. They do not replace the judgement of clinicians. The system only records and reminds.')
    nfr_table(d, SAFETY, 'Safety Requirements', 'tab_saf')
    d.h3('4.5.3 Security Requirements')
    d.p('Security protects the confidentiality, integrity and availability of patient information. The requirements in {ref:tab_sec} answer the OWASP Top 10 (OWASP Foundation, 2021), and {ref:tab_law} shows how they meet the law. Passwords are stored with the bcrypt method (Provos & Mazières, 1999; OWASP Foundation, n.d.) through the PHP function password_hash (The PHP Group, n.d.).')
    nfr_table(d, SECURITY, 'Security Requirements', 'tab_sec')
    d.table(['Law', 'What it requires', 'How UB-HMS responds'], [
        ['Health Act, 2017', 'Information about a patient\'s health and treatment must be kept confidential.', 'Personal accounts, role-based access (REQ-072), clinical data hidden from non-clinical roles (REQ-024), audit trail (REQ-076).'],
        ['Data Protection Act, 2019, s. 25', 'Data must be processed lawfully, be accurate, be limited to what is needed and not be kept longer than necessary.', 'Validation of every field and duplicate checks keep data accurate (REQ-003, REQ-004); only data the clinical work needs is collected; the audit trail holds no health data (REQ-077). Retention periods are for the hospital to set (section 4.6.2).'],
        ['Data Protection Act, 2019, s. 41', 'Data protection by design and by default.', 'Each role starts with the least access; passwords are hashed; first sign-in forces a new password; accounts lock; headers and tokens protect every page (SEC-01 to SEC-09).'],
        ['Data Protection Act, 2019, s. 43', 'A breach must be reported to the Data Commissioner within 72 hours.', 'The audit trail, failed sign-in counts and refused requests on the health page help the hospital discover and investigate a breach. Reporting is a hospital procedure; the system does not send reports.'],
        ['Data Protection Act, 2019, s. 46', 'Health data may be processed only by health care providers or people bound by a duty of confidentiality.', 'Only staff with personal accounts can reach patient data, and each sees only their role\'s part (SEC-02, SEC-10).'],
        ['Digital Health Act, 2023', 'Sets rules for digital health systems and national health information.', 'The system uses ICD-10 codes and CSV files so data can be exchanged later (QUA-06). Connection to national systems is outside the scope.'],
    ], widths=[3.2, 5.4, 6.7], size=8.5, caption='How the Security Requirements Meet the Law (Republic of Kenya, 2017, 2019, 2023)', label='tab_law')
    d.h3('4.5.4 Software Quality Attributes')
    d.p('Where attributes compete, the order of preference is: correctness and security first, then reliability, then ease of use, then speed. A hospital would rather a page took a second longer than show a wrong balance or a record to the wrong person. Ease of use is preferred to ease of learning, because staff use the system every day.')
    nfr_table(d, QUALITY, 'Software Quality Attributes', 'tab_qua')


def build_other(d):
    d.h2('4.6 Other Requirements')
    d.h3('4.6.1 Database Requirements')
    d.bullets([
        'All data shall be in one MariaDB database of 22 InnoDB tables with the UTF-8 (utf8mb4) character set.',
        'Rules that must never be broken shall be enforced by the database as well as the application: primary and foreign keys, unique keys (OP number, ID number, invoice number, appointment slot), and CHECK constraints (no negative stock, no payment of zero).',
        'Standard tables (roles, services, laboratory tests, ICD-10 codes) shall validate coded fields.',
        'The application shall never delete patients, visits, consultations, invoices, payments, stock movements or audit entries.',
        'Money shall be stored as exact decimals (DECIMAL with two places), never as floating-point numbers.',
        'The database design is given in section 5.5.',
    ])
    d.h3('4.6.2 Data Retention and the Rights of Patients')
    d.p('The Data Protection Act, 2019 gives people rights over their data, including to have wrong data corrected, and requires that data is not kept longer than necessary (Republic of Kenya, 2019). '
        'UB-HMS lets a receptionist correct a patient\'s details (REQ-009). It keeps clinical, financial and audit records indefinitely, because hospital records must be kept for periods set by law and by the hospital\'s own policy. '
        'It has no function to erase a record, to produce a patient\'s complete data file, or to apply a retention period automatically. These are known gaps. They are listed in Chapter 7 and must be covered by the hospital\'s data protection procedures until a later release adds them.')
    d.h3('4.6.3 Internationalisation')
    d.p('The system is for one hospital in Kenya and has one language, English. Phone numbers are Kenyan mobile numbers stored as +254 followed by nine digits. Names may contain apostrophes, hyphens and dots, because many Kenyan names do. Amounts are in Kenya shillings.')
    d.h3('4.6.4 Installation and Reuse')
    d.p('The system shall be installed by one command that creates the database, loads the 22 tables and standard data, and creates the first administrator with a one-time password. A second command creates a least-privilege database account. '
        'Hospital name, opening hours, slot length, password rules and timeouts are held in one configuration file so that another facility can reuse the software with its own settings. Installation is described in Chapter 6.')
    d.h3('4.6.5 Licensing')
    d.p('All software used is free and open source (XAMPP, PHP, MariaDB, Apache, Visual Studio Code, Git), so there are no licence fees (Waweru, 2026). The code written for UB-HMS is the student\'s own work.')


def build_references_and_glossary(d, cited):
    d.h2('4.7 References')
    d.p('The references for Chapter 4 follow in APA 7th edition style.')
    for key in sorted(cited, key=lambda k: REFERENCES[k].lower()):
        p = d.p(REFERENCES[key], align='left')
        p.paragraph_format.left_indent = d.d.styles['Normal'].paragraph_format.left_indent
        _hanging(p)
    d.h2('Appendix A: Glossary')
    d.p('The terms and abbreviations used in Chapters 4 and 5 are defined here.')
    d.table(['Term', 'Meaning'], [[t, m] for t, m in GLOSSARY], widths=[4.3, 11.0], size=9, caption='Glossary of Terms and Abbreviations', label='tab_gloss')


def _hanging(p):
    from docx.shared import Cm
    p.paragraph_format.left_indent = Cm(1.0)
    p.paragraph_format.first_line_indent = Cm(-1.0)
    p.paragraph_format.line_spacing = 1.15
