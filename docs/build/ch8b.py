"""Chapter 8, part 2: advanced features, maintenance, troubleshooting, FAQ, glossary and index (8.4 to 8.8)."""
from doc_common import REFERENCES, GLOSSARY, ref_keys
from ch8a import task, pic


def build_advanced(d):
    d.h2('8.4 Advanced Features and Customisation')
    d.h3('8.4.1 Advanced Tools')
    d.p('**Importing patients from a file (receptionist).** Use this to move patients from the paper register or an old spreadsheet into the system without typing each one. Do it in these steps:')
    d.numbered([
        'In a spreadsheet program make a table with the column headings below on the first row, one patient on each row after it.',
        'Save the file as **CSV** (comma-separated values). Keep it below 1 MB and 500 rows; for more patients make several files.',
        'Follow task R7 in section 8.3.2. The system checks every row and shows a preview before it saves anything.',
    ])
    d.table(['Column heading', 'Required?', 'What to write', 'Example'], [
        ['first_name', 'Yes', 'First name', 'Wanjiru'],
        ['middle_name', 'No', 'Middle name', 'Akinyi'],
        ['last_name', 'Yes', 'Last name', 'Kamau'],
        ['sex', 'Yes', 'Female or Male', 'Female'],
        ['date_of_birth', 'Yes', 'yyyy-mm-dd or dd/mm/yyyy', '2019-04-23 or 23/04/2019'],
        ['national_id', 'No', 'ID, passport or birth certificate number, 5 to 20 letters or numbers', '12345678'],
        ['phone', 'No', 'Kenyan mobile number', '0712 345 678'],
        ['residence', 'No', 'Estate or town', 'Kasarani'],
        ['allergies', 'No', 'Known allergies', 'penicillin'],
        ['next_of_kin_name', 'No', 'Name of next of kin', 'Otieno Kamau'],
        ['next_of_kin_phone', 'No', 'Mobile number of next of kin', '0722 111 222'],
        ['next_of_kin_relation', 'No', 'Relationship', 'Brother'],
    ], widths=[3.6, 1.8, 6.4, 3.5], size=9, caption='Columns of the Patient Import File', label='tab_csvcols')
    pic(d, '20-patient-import', 'The page for importing patients', 'fig_m_import', h=10)
    d.note('Keep the original paper register. Check about one patient in twenty on the screen against the paper, to be sure the file was copied correctly. Rows for patients who look already registered are skipped, and the old file number of a patient is not stored.')
    d.p('**Exporting data (manager and pharmacist).** Every report has a **Download CSV** button. The file opens in a spreadsheet program, where you can sort it, chart it or keep it. Cells that start with =, +, - or @ are changed so that the spreadsheet shows them as text and does not run them as a formula.')
    d.p('**Filters and printing.** Most lists have filters at the top, for example by date, doctor and status in the diary, by status and balance in the invoice list, or by person and action in the audit trail. Press **Print** on invoices, receipts and reports to print them without the menu.')
    d.h3('8.4.2 Customisation')
    d.p('**User preferences.** Each user can change only their own password (the **Password** button). The menu and the dashboard are fixed for each role, so that everyone in the same role sees the same system and training stays simple.')
    d.p('**Administrator settings.** The administrator can change the following. The first two are on screen. The others are in the file config\\config.local.php on the server, which only a technician should change; the system uses the standard values until they are set.')
    d.table(['Setting', 'Where', 'Standard value'], [
        ['Accounts and roles', 'Administration > User accounts', 'Eight roles'],
        ['Prices of services and tests', 'Administration > Price list', 'As entered at installation'],
        ['Prices, reorder levels and active medicines', 'Pharmacy > Medicine list (pharmacist)', '-'],
        ['Hospital name and place', 'config.local.php: hospital, location', 'Uhai Bora Hospital, Nairobi County, Kenya'],
        ['Clinic days, opening and closing times, length of a slot, how far ahead to book', 'config.local.php: clinic', 'Monday to Saturday, 08:00 to 17:00, 20 minutes, 90 days'],
        ['Warning period before expiry', 'config.local.php: stock', '90 days'],
        ['Password length, lockout and timeouts', 'config.local.php: security and session', '10 characters; lock after 5 failures for 15 minutes; sign out after 30 idle minutes or 8 hours'],
        ['Database connection', 'config.local.php or environment variables UBHMS_DB_*', 'Local server, database uhai_bora_hms'],
    ], widths=[6.0, 5.0, 4.3], size=9, caption='Settings the Administrator Can Change', label='tab_settings')


def build_maintenance(d):
    d.h2('8.5 Maintenance and Updates')
    d.h3('8.5.1 Routine Maintenance')
    d.p('A system that holds the hospital\'s records needs regular care. {ref:tab_routine} is the checklist for the administrator. Each check takes a few minutes.')
    d.table(['When', 'What to do', 'Where'], [
        ['Every morning', 'Open System health. Check that the latest backup is OK (less than 26 hours old) and that there is no Failed item. Read the number of locked accounts and failed sign-ins.', 'Administration > System health'],
        ['Every week', 'Copy the newest backup file to the external hard disk and keep the disk away from the server. Look at the audit trail for refused requests and failed sign-ins.', 'storage\\backups; Audit trail'],
        ['Every month', 'Review the list of accounts: deactivate people who have left, check that every role is right. Check free disk space. Make a trial restore of the newest backup on another computer.', 'User accounts; System health'],
        ['Every three months', 'Check that Windows, XAMPP and the browsers are up to date. Look at the response of the busiest pages. Review who can use which role.', 'Server'],
        ['When staff change', 'Create or deactivate the account the same day.', 'User accounts'],
        ['Before any change to the system', 'Make a backup, and write down what you change.', 'Section 8.5.3'],
    ], widths=[3.0, 9.0, 3.3], size=9, caption='Routine Maintenance Checklist', label='tab_routine')
    d.h3('8.5.2 Updating the System')
    d.p('Updates give new features or corrections. Only the administrator or a technician does them, outside clinic hours. The version of the system is in the file README.md and the changes are in the project history (Git).')
    d.numbered([
        'Tell the staff when the system will be unavailable and ask them to sign out.',
        'Make a backup (section 8.5.3) and copy it to another computer.',
        'Keep a copy of the folder config (your config.local.php holds the database account) and of the folder storage.',
        'Copy the new version of the system into C:\\xampp\\htdocs\\ub-hms, replacing the old files. Do not delete config\\config.local.php or the folder storage.',
        'Do not run database\\install.php. It deletes all tables. If the update changes the database, the update notes give the exact commands to run.',
        'Open the system, sign in as the administrator, and open System health. Check that nothing shows Failed.',
        'Ask one person from each department to try their main task, then tell the staff that the system is ready.',
    ])
    d.note('Warning. database\\install.php drops all the tables and creates them again. Never run it on a system that holds real records. It refuses to run over an existing database unless the word --force is added, and nobody should add it on a live system.')
    d.h3('8.5.3 Backup and Restore')
    d.p('**Back up the data.** The system makes a backup every night by itself if the scheduled task has been set up. To make one by hand:')
    d.numbered([
        'Open PowerShell on the server and go to the folder of the system: cd C:\\xampp\\htdocs\\ub-hms',
        'Run: powershell -ExecutionPolicy Bypass -File scripts\\backup.ps1',
        'The script writes a file such as uhai_bora_hms_20261215_220000.sql.zip in the folder storage\\backups, checks that it is complete, and deletes backups older than 30 days. It prints a line that begins "backup ok".',
        'Copy the new file to the external hard disk.',
    ])
    d.p('**Set up the nightly backup.** Create a task in Windows Task Scheduler that runs every day at 22:00 with the command shown in the header of scripts\\backup.ps1. Run it once by hand, and check on the System health page that "Latest backup" is OK.')
    d.p('**Restore the data.** Do this only when data has been lost or damaged. Everything entered after the backup will be lost.')
    d.numbered([
        'Ask all staff to sign out and stop using the system.',
        'Open PowerShell in the folder of the system.',
        'Run: powershell -ExecutionPolicy Bypass -File scripts\\restore.ps1 -BackupFile storage\\backups\\uhai_bora_hms_20261215_220000.sql.zip (use the name of your file).',
        'The script first makes a safety backup of the current data. Then it asks you to type the name of the database (uhai_bora_hms) to confirm. Type it and press Enter.',
        'Wait for the message "Restore complete". Open the system, check the System health page, and look at a few records.',
        'Tell the staff what time the data was restored to, so that they can enter again what was done after it.',
    ])
    d.p('In a test on a large database of 20,040 patients and 30,067 visits, a backup took 3.3 seconds and a restore 39 seconds, and every record came back exactly (Chapter 7).')


ISSUES = [
    ['I cannot sign in', 'Wrong username or password; Caps Lock on; the account is locked or deactivated', 'Check the username (no spaces) and Caps Lock. Wait 15 minutes if the account is locked, or ask the administrator to unlock it. If it has been deactivated, ask the administrator.'],
    ['I forgot my password', 'There is no self-service reset', 'Ask the administrator to press Reset password. You will get a new one-time password and must choose your own at the next sign-in.'],
    ['The system sent me to "Change password"', 'You use a one-time password for the first time, or it was just reset', 'Choose a new password with at least 10 characters, with a capital letter, a small letter and a number.'],
    ['A page says "not allowed" (403)', 'Your role does not hold the permission for that page', 'Use the pages in your menu. If your work needs it, ask the manager to ask the administrator to change your role. The attempt is recorded.'],
    ['A page says "the form has expired" (419)', 'The page was open for a long time, or the session ended', 'Go back, reload the page, sign in again if asked, and repeat the action.'],
    ['I was signed out suddenly', 'No activity for 30 minutes, or 8 hours since you signed in, or your account was deactivated', 'Sign in again. Your unsaved work was not saved; start the form again.'],
    ['I cannot find a patient', 'Spelling; the patient is not registered', 'Search with a shorter piece of the name, the phone or the ID number. If there is no match, register the patient.'],
    ['The system says the patient may already be registered', 'A record with the same ID number, or the same name and date of birth, exists', 'Open the record shown. Register a new one only if it is a different person, and tick the confirmation.'],
    ['I cannot book a time slot', 'The slot is taken, past, on a Sunday, or more than 90 days ahead', 'Choose another free slot. The page shows only free slots as choices.'],
    ['I cannot check a patient in', 'The patient already has an open visit, or the booking is cancelled or for another day', 'Open the visit shown in the message, or book a new appointment.'],
    ['The triage form does not save', 'A value is outside the allowed range, or blood pressure has only one value', 'Read the message beside the field and correct it.'],
    ['I cannot save the consultation', 'Complaint, diagnosis or type is empty, or the ICD-10 code is not in the list', 'Fill the missing field, or leave the code blank.'],
    ['I cannot close the visit', 'No consultation has been recorded yet', 'Record the consultation first.'],
    ['A test result is not shown', 'The laboratory has not entered it yet, or another doctor requested the test', 'Look at the status on the consultation page. Only the doctor who requested a test sees its result there.'],
    ['I cannot dispense', 'More than is left on the prescription, or not enough unexpired stock', 'Read the message: it names the line and the quantity available. Give a smaller quantity, or receive stock, and try again. Nothing is deducted when the dispensing fails.'],
    ['Stock shows less than the shelf', 'Expired batches are not counted; an adjustment is missing', 'Look at Batches. Write off expired stock with an adjustment and a reason.'],
    ['There is no invoice for a visit', 'Nothing billable has been recorded yet', 'An invoice appears as soon as a consultation, test, procedure or medicine is recorded.'],
    ['I want to correct an amount on an invoice', 'Charges cannot be edited', 'Correct the source: cancel the test, or ask the pharmacist about the quantity. The invoice follows. After a payment, ask the manager.'],
    ['The payment is refused', 'Amount above the balance, or the reference is not valid', 'Read the message under the field. M-Pesa needs the 10-character code and each code can be used once.'],
    ['A report shows no data', 'The dates are wrong, or nothing happened in that period', 'Check From and To, and press Show report.'],
    ['The page is slow', 'Many users, a large report, or a problem on the server', 'Wait a moment and try again. For a large report, choose a shorter period. Tell the administrator if it continues.'],
    ['A page is plain, with no colours, or the buttons do not work', 'The stylesheet or script did not load; an old browser', 'Reload with Ctrl+F5. Use a current browser. Tell the administrator if it continues.'],
    ['I see "Something went wrong" with a reference', 'An unexpected fault on the server', 'Write down the reference (six letters and numbers) and tell the administrator. Your work before the error is safe.'],
]

MESSAGES = [
    ['The username or password is not correct.', 'Sign-in failed. The same message is given whichever part was wrong.', 'Check and try again.'],
    ['This account is temporarily locked after too many wrong passwords.', 'Five wrong passwords were typed.', 'Wait 15 minutes or ask the administrator.'],
    ['Too many failed attempts from this computer.', 'Twenty failed sign-ins came from this computer in 15 minutes.', 'Wait 15 minutes.'],
    ['This account has been deactivated.', 'The administrator switched the account off.', 'Ask the administrator.'],
    ['Please choose a new password before you continue.', 'You must change a one-time password.', 'Use the Change password page.'],
    ['Your account does not have permission to open this page.', 'Your role does not hold the permission.', 'Use your own menu.'],
    ['Your session form has expired.', 'The security token of the form is missing or old.', 'Go back, reload and repeat.'],
    ['That time is already booked for this doctor. Choose another slot.', 'Someone else holds the slot.', 'Choose another slot.'],
    ['This patient already has an appointment at that time with another doctor.', 'A patient cannot be in two places.', 'Choose another time.'],
    ['You cannot book a date that has already passed. / The clinic is closed on that day. / That time has already passed.', 'The date or time is not allowed.', 'Choose a working day and a future time.'],
    ['A patient with this ID number is already registered.', 'The ID number is used by another record.', 'Open that record.'],
    ['Enter a Kenyan mobile number such as 0712 345 678.', 'The phone number does not look like a Kenyan mobile.', 'Type it in one of the usual forms.'],
    ['The date of birth cannot be in the future.', 'The date is after today.', 'Correct the date.'],
    ['Enter both blood pressure values, or neither. / The upper blood pressure must be higher than the lower one.', 'Blood pressure is incomplete or reversed.', 'Correct the two values.'],
    ['That ICD-10 code is not in the hospital list.', 'The code is not one of the hospital\'s codes.', 'Pick a code from the list or leave it blank.'],
    ['Enter the 10-character M-Pesa confirmation code.', 'The M-Pesa code is not 10 letters or digits.', 'Copy it from the M-Pesa message.'],
    ['This M-Pesa code has already been used for a payment.', 'The code was recorded before.', 'Check the code; ask the manager if it is a real repeat.'],
    ['The amount is more than the balance.', 'The payment is above what is owed.', 'Type the balance or a smaller amount.'],
    ['... only N in stock (expired stock cannot be dispensed).', 'There is not enough unexpired stock for a line.', 'Give less or receive stock.'],
    ['This stock has already expired and cannot be received.', 'The expiry date is in the past.', 'Check the expiry date on the pack.'],
    ['Something went wrong. Reference: XXXXXX.', 'An unexpected error. It has been written to the log.', 'Tell the administrator the reference.'],
]


def build_troubleshooting(d):
    d.h2('8.6 Troubleshooting')
    d.h3('8.6.1 Common Issues')
    d.p('Find your problem in {ref:tab_issues}, read the likely cause, and follow the advice. If it does not help, go to section 8.6.3.')
    d.table(['Problem', 'Likely cause', 'What to do'], ISSUES, widths=[4.3, 4.6, 6.4], size=8.5, caption='Common Problems and What to Do', label='tab_issues')
    d.h3('8.6.2 Error Messages')
    d.p('The system shows its messages in plain words, in red or amber boxes, or beside the field that is wrong. {ref:tab_messages} explains the most common ones. Messages never show program code, file names or database text.')
    d.table(['Message', 'What it means', 'What to do'], MESSAGES, widths=[6.3, 5.0, 4.0], size=8.5, caption='Common Messages and Their Meaning', label='tab_messages')
    pic(d, '21-access-denied', 'The "not allowed" page', 'fig_m_denied', h=8, w=11)
    d.h3('8.6.3 Support')
    d.p('First ask the person in charge of your department, or a colleague who has been trained. If the problem is not solved, tell the system administrator and give: your name and role, the page, what you were doing, the exact message, and the six-character reference if the page showed one. The administrator can find the action in the audit trail and the error in the log.')
    d.table(['Level', 'Who', 'For what'], [
        ['1', 'Department head or trained colleague', 'How to do a task; the manual'],
        ['2', 'System administrator', 'Accounts, locked accounts, passwords, prices, backups, errors with a reference'],
        ['3', 'The developer, Susan Wanjiku Waweru, through the KCA University project office', 'Faults in the system itself and changes'],
    ], widths=[1.4, 6.5, 7.4], size=9, caption='Who to Ask for Help', label='tab_support', align=['c', 'l', 'l'])
    d.note('The contact details of the administrator and the developer are to be filled in by the hospital before use: administrator ____________________ telephone ____________________.')


FAQ = [
    ('How do I reset a password?', 'You cannot do it yourself if you have forgotten it. Ask the administrator to reset it. You will receive a one-time password and must choose a new one when you sign in. If you remember your password and just want a new one, use the Password button at the top right.'),
    ('Why does the system lock my account?', 'After five wrong passwords the account is locked for 15 minutes to stop people guessing. Wait, or ask the administrator to unlock it.'),
    ('Why can I not see a menu item my colleague has?', 'Each role sees only the pages its work needs. If you are in a different role, you have a different menu. This protects patient information.'),
    ('Can I change a patient\'s OP number?', 'No. The OP number is issued once and never changes or repeats. You can correct the other details.'),
    ('The patient is registered twice. What now?', 'Do not delete anything (the system has no delete). Tell the manager, who will agree with the doctor which record to use; both records stay, and the one in use is written on the file.'),
    ('Why can I not book Sunday?', 'The clinic is closed on Sunday. The opening days and hours are set by the administrator in the configuration.'),
    ('How long is an appointment?', 'Twenty minutes. A longer visit can be booked as two slots in a row.'),
    ('Can a doctor see another doctor\'s test results?', 'A doctor sees the results of the tests he or she requested. Other doctors see the diagnoses and the history of the patient, but not the result in the list of results.'),
    ('Why did the system refuse to dispense?', 'Because there was not enough unexpired stock, or the quantity was more than what remains on the prescription. Nothing is deducted when it refuses. Read the message on the page.'),
    ('Which batch is used first?', 'The batch that expires first. Expired stock is never given.'),
    ('Why can I not change the price of a service on an invoice?', 'Charges come from the records of the departments, and prices from the price list. This protects the hospital against errors and fraud. Ask the administrator to correct the price list, and the manager to deal with an invoice that has been paid.'),
    ('Can a patient pay in parts?', 'Yes. Type a smaller amount than the balance. The invoice shows Partially paid until the balance is zero.'),
    ('What if the patient pays with two methods?', 'Record two payments on the same invoice, one for each method, and make sure that together they equal the balance.'),
    ('Can I see old paper records in the system?', 'Only patients were moved into the system from the paper registers. Old visits, results and invoices stay on paper.'),
    ('What happens when there is no electricity?', 'The server has a UPS that keeps it running for a short time. If the power fails for longer, use the paper forms, and enter the records afterwards, as the downtime procedure of Chapter 6 says.'),
    ('Who can see what I did in the system?', 'The administrator and the manager can read the audit trail, which records your actions with your name, the computer and the time, but not patient details. This is required to protect patient information.'),
]


GLOSS8 = [
    ('Allergy flag', 'A red mark on a queue or prescription showing that the patient has a known allergy.'),
    ('Audit trail', 'The record of who did what, to which record, from which computer and when.'),
    ('Batch', 'Stock of one medicine from one delivery, with one expiry date.'),
    ('Check-in', 'Opening a visit when the patient arrives.'),
    ('Dashboard', 'The first page after sign-in, showing the work waiting for your role.'),
    ('Diary', 'The list of appointments for a day and a doctor.'),
    ('Dispense', 'To give medicines against a prescription.'),
    ('ICD-10', 'The international list of diagnosis codes.'),
    ('Invoice', 'The bill for one visit, listing all the services.'),
    ('One-time password', 'A password the administrator gives you that you must change at once.'),
    ('OP number', 'The outpatient number of a patient, such as OP26-00042.'),
    ('Partially paid', 'An invoice on which part of the amount has been paid.'),
    ('Permission', 'The right to open a page or do an action; each role has a set of them.'),
    ('Prescription', 'The doctor\'s order for medicines.'),
    ('Reorder level', 'The stock level at or below which a medicine shows as Low.'),
    ('Role', 'Your job in the system, for example Nurse or Cashier.'),
    ('Slot', 'A 20-minute period in a doctor\'s day.'),
    ('Triage', 'The nurse\'s first check, with vital signs, before the doctor.'),
    ('Visit', 'One attendance of a patient, from check-in to the doctor closing it.'),
    ('Vital signs', 'Temperature, pulse, blood pressure, breathing rate, oxygen saturation, weight and height.'),
    ('Walk-in', 'A patient who comes without an appointment.'),
]

INDEX = [
    ('Allergies', '8.3.2 R1, N1, D2, D4, P1'), ('Appointments, booking', '8.3.2 R4'), ('Appointments, cancel or missed', '8.3.2 R5'), ('Audit trail', '8.3.2 M2; 8.5.1'),
    ('Backup', '8.5.3'), ('Batches', '8.3.2 P3, P4'), ('Changeover', 'Chapter 6, 6.8.5'), ('Check-in', '8.3.2 R6'), ('Close a visit', '8.3.2 D6'),
    ('Consultation', '8.3.2 D2'), ('CSV, export', '8.4.1'), ('CSV, import', '8.3.2 R7; 8.4.1'), ('Dashboard', '8.2.3'), ('Dispensing', '8.3.2 P1'),
    ('Error messages', '8.6.2'), ('FAQ', '8.7'), ('Health, system', '8.3.2 A4; 8.5.1'), ('Import patients', '8.3.2 R7; 8.4.1'), ('Invoices', '8.3.2 C1'),
    ('Laboratory results', '8.3.2 L2, D5'), ('Laboratory tests, request', '8.3.2 D3'), ('Lockout', '8.2.2; 8.6.1'), ('Medicine list', '8.3.2 P5'), ('Menu', '8.3.1'),
    ('Navigation', '8.3.1'), ('OP number', '8.3.2 R1'), ('Password, change', '8.2.2 S1, S3'), ('Password, forgotten', '8.6.1; 8.7'), ('Payments', '8.3.2 C2'),
    ('Prescribing', '8.3.2 D4'), ('Prices', '8.3.2 A3'), ('Printing', '8.3.2 C3; 8.4.1'), ('Procedures', '8.3.2 N3'), ('Receipts', '8.3.2 C3'),
    ('Receiving stock', '8.3.2 P2'), ('Registering a patient', '8.3.2 R1'), ('Reports', '8.3.2 M1'), ('Restore', '8.5.3'), ('Roles', '8.1.2; 8.3.1'),
    ('Searching for a patient', '8.3.2 R2'), ('Sign in, sign out', '8.2.2'), ('Stock adjustment', '8.3.2 P4'), ('Stock alerts', '8.3.2 P3'), ('Training', 'Chapter 6, 6.8.4'),
    ('Triage', '8.3.2 N1, N2'), ('Updating the system', '8.5.2'), ('User accounts', '8.3.2 A1, A2'), ('Vital signs', '8.3.2 N2'),
]


def build_faq_glossary(d):
    d.h2('8.7 FAQ (Frequently Asked Questions)')
    for i, (q, a) in enumerate(FAQ, 1):
        d.h3(f'8.7.{i} {q}')
        d.p(a)
    d.h2('8.8 Glossary and Index')
    d.h3('8.8.1 Glossary')
    d.table(['Term', 'Meaning'], [[t, m] for t, m in GLOSS8], widths=[4.0, 11.3], size=9, caption='Glossary of Terms Used in the Manual', label='tab_gloss8')
    d.h3('8.8.2 Index')
    d.p('The index lists topics in alphabetical order and shows the section, or the task code (for example R1) in section 8.3.2, where the topic is explained.')
    half = (len(INDEX) + 1) // 2
    rows = []
    for i in range(half):
        a = INDEX[i]
        b = INDEX[i + half] if i + half < len(INDEX) else ('', '')
        rows.append([a[0], a[1], b[0], b[1]])
    d.table(['Topic', 'Where', 'Topic', 'Where'], rows, widths=[3.9, 3.7, 3.9, 3.8], size=8.5, caption='Index of Topics', label='tab_index')
    d.h3('8.8.3 References')
    for key in sorted(ref_keys(['proposal']), key=lambda k: REFERENCES[k].lower()):
        p = d.p(REFERENCES[key], align='left')
        from docx.shared import Cm
        p.paragraph_format.left_indent = Cm(1.0)
        p.paragraph_format.first_line_indent = Cm(-1.0)
        p.paragraph_format.line_spacing = 1.15
