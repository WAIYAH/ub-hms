"""Chapter 5, part 1: purpose, system overview, architecture and software design (5.1 to 5.4)."""
from doc_common import fig, landscape_figure

PSEUDO = {}
PSEUDO['flow_register'] = '''MODULE 1.1 REGISTER PATIENT
  READ the registration form
  errors <- CHECK names, sex, date of birth, phone number, ID number
  IF errors exist THEN SHOW messages beside the fields; STOP
  IF national_id is given AND a patient already has it THEN
      REFUSE; SHOW that patient's OP number; STOP
  similar <- patients with the same name AND date of birth
  IF similar exist AND not confirmed THEN SHOW them; ASK to confirm; STOP
  BEGIN TRANSACTION
      number <- (counter for this year) + 1
      op_number <- "OP" + yy + "-" + number padded to 5 digits
      SAVE the patient with op_number
      WRITE audit entry (no health details)
  COMMIT
  SHOW the record and its OP number'''
PSEUDO['flow_book'] = '''MODULE 1.6 BOOK APPOINTMENT
  READ patient, doctor, date, time, reason
  CHECK patient and active doctor exist
  CHECK date is today or later, within 90 days, and a working day
  CHECK time is on the 20-minute grid, and not already past
  IF any check fails THEN SHOW the reason beside the field; STOP
  BEGIN TRANSACTION
      LOCK the doctor's row
      IF the doctor, or the patient, already has a booking at that time THEN
          ROLLBACK; ASK for another slot; STOP
      SAVE appointment with status "Booked"   // unique slot key is the last guard
      WRITE audit entry
  COMMIT
  SHOW the diary for that day'''
PSEUDO['flow_checkin'] = '''MODULE 2.1 CHECK IN, MODULE 2.3 RECORD TRIAGE
  CHECK IN
    IF the patient has an open visit THEN REFUSE; SHOW it; STOP
    IF a booked appointment exists today THEN mark it "Attended"
    ELSE record a walk-in
    OPEN a visit (stage: waiting for triage)
  TRIAGE
    READ temperature, pulse, blood pressure, breathing rate, oxygen saturation,
         weight, height, notes
    FOR each value entered
        IF it is outside its allowed range THEN add a message for the field
    IF blood pressure has one value only, or upper <= lower THEN add a message
    IF any message exists THEN SHOW them and ASK again; STOP
    SAVE triage; WRITE audit entry       // stage becomes: waiting for doctor'''
PSEUDO['flow_consult'] = '''MODULE 2.4 RECORD CONSULTATION, MODULE 2.7 CLOSE VISIT
  READ complaint, examination, diagnosis, ICD-10 code, plan, consultation type
  IF complaint, diagnosis or type is missing OR the ICD-10 code is not listed THEN
      SHOW messages; ASK again; STOP
  IF a consultation exists AND it was recorded by another doctor THEN REFUSE; STOP
  SAVE the consultation with this doctor
  ADD the consultation fee to the invoice
  WRITE audit entry
  // tests, prescriptions and procedures are recorded on their own pages
  WHEN the doctor presses "Close visit":
      IF no consultation exists THEN REFUSE; STOP
      SET visit status "Closed"; WRITE audit entry'''
PSEUDO['flow_lab'] = '''MODULE 3.1 REQUEST TESTS, MODULE 3.4 ENTER RESULT
  REQUEST
    READ the tests chosen
    IF the visit is not open OR has no consultation THEN REFUSE; STOP
    BEGIN TRANSACTION
        FOR each test chosen
            IF the same test is still pending THEN ROLLBACK; NAME the test; STOP
            SAVE request with the visit and the requesting doctor
        ADD each test price to the invoice
    COMMIT
  RESULT
    technologist STARTS the test            // status "In progress"
    READ result text and range flag
    IF the result is empty OR the request is cancelled THEN REFUSE; STOP
    SAVE result, who entered it and when; SET status "Completed"
    SHOW the result to the requesting doctor only'''
PSEUDO['flow_dispense'] = '''MODULE 4.4 DISPENSE
  READ the quantity to give for each line of the prescription
  BEGIN TRANSACTION
      LOCK the prescription lines
      FOR each line that has a quantity
          IF quantity > (prescribed - given) THEN ROLLBACK; SHOW the line; STOP
          batches <- unexpired batches, earliest expiry first (locked)
          IF total in batches < quantity THEN ROLLBACK; SHOW the line; STOP
          remaining <- quantity
          FOR each batch in order WHILE remaining > 0
              take <- smaller of remaining and the batch quantity
              REDUCE the batch by take           // never below 0
              WRITE a negative stock ledger entry
              remaining <- remaining - take
          ADD quantity to the line's given amount
      SET prescription status "Dispensed" or "Partially dispensed"
      ADD the medicines given to the invoice
      WRITE audit entry
  COMMIT
  SHOW what was given and from which batch'''
PSEUDO['flow_invoice'] = '''MODULE 5.1 BUILD INVOICE   (runs after every billable action)
  BEGIN TRANSACTION
      LOCK the visit's invoice
      charges <- consultation fee, tests not cancelled, medicines given, procedures
      IF no invoice exists AND charges is empty THEN COMMIT; STOP
      IF no invoice exists THEN CREATE invoice "INV-yyyy-nnnnnn"; WRITE audit entry
      FOR each charge
          IF it is not on the invoice THEN ADD it
          ELSE IF a payment has been taken THEN update the quantity only
          ELSE update description, quantity and price
      DELETE lines whose source no longer exists
      total <- sum of the lines
      IF total < amount already paid THEN ROLLBACK; REFUSE; STOP
      SET status "Unpaid", "Partially paid" or "Paid"
  COMMIT'''
PSEUDO['flow_payment'] = '''MODULE 5.2 TAKE PAYMENT
  READ method, amount, reference
  BEGIN TRANSACTION
      LOCK the invoice
      IF method is not one of cash, M-Pesa, card, insurance THEN error
      IF amount <= 0 OR amount > balance THEN error
      IF method = M-Pesa AND (reference not 10 characters OR used before) THEN error
      IF method = card AND reference is not 4 to 20 letters or digits THEN error
      IF method = insurance AND reference is not a valid claim number THEN error
      IF any error THEN ROLLBACK; SHOW the reasons beside the fields; STOP
      SAVE payment
      amount_paid <- amount_paid + amount
      SET status "Partially paid" or "Paid"
      WRITE audit entry (amount and method only)
  COMMIT
  SHOW receipt "RCT-yyyy-nnnnnn"'''
PSEUDO['flow_signin'] = '''MODULE 6.1 SIGN IN
  READ username and password and the form token
  IF the token is wrong THEN REFUSE (419); STOP
  IF this computer has 20 or more failures in 15 minutes THEN
      SHOW "too many attempts"; STOP
  account <- find username
  ok <- CHECK password against the account's hash  // dummy hash if name unknown
  IF account is locked THEN SHOW "temporarily locked"; STOP     // even if ok
  IF NOT ok THEN
      COUNT the failure; IF failures = 5 THEN lock for 15 minutes
      WRITE audit entry; SHOW one neutral message; STOP
  IF account is not active THEN SHOW "deactivated"; STOP
  RESET failures; RECORD time of sign-in
  START a new session id and a new form token; WRITE audit entry
  IF must change password THEN go to the change-password page
  ELSE open the dashboard with the menu of the user's role'''
PSEUDO['flow_access'] = '''MODULE 6.3 CHECK PERMISSIONS   (runs at the start of every page)
  IF not signed in THEN go to the sign-in page; STOP
  IF idle > 30 minutes OR signed in > 8 hours THEN END session; WRITE audit; STOP
  IF the account is no longer active THEN END session; STOP
  IF must change password AND this is not the password page THEN go there; STOP
  IF the user's role does not hold the page's permission THEN
      WRITE audit "access_denied"; SHOW the 403 page; STOP
  IF the page changes data THEN
      IF the request is not a POST OR the token is not valid for this session THEN
          WRITE audit; REFUSE (419); STOP
  RUN the page'''

FLOW_TEXT = {
    'flow_register': 'The pseudo-code and {ref:fig_flow_register} show how a patient is registered. The two checks for duplicates come before the number is issued, and the number is issued inside the same transaction as the save, so a failed save does not use up a number.',
    'flow_book': '{ref:fig_flow_book} shows booking. The first set of checks catches mistakes in the form. The second set, inside a transaction with the doctor\'s row locked, catches a clash with another booking made at the same moment. The database\'s unique slot key is the last guard, so even a faulty page could not save a double booking.',
    'flow_checkin': '{ref:fig_flow_checkin} covers the arrival of the patient and the nurse\'s triage. A patient can have only one open visit. After triage the visit moves to the doctor\'s queue.',
    'flow_consult': '{ref:fig_flow_consult} covers the doctor\'s work. Saving the consultation adds the consultation fee to the invoice. Test requests, prescriptions and procedures have their own pages (processes 3.0 and 4.0), and the visit can be closed only after a consultation exists.',
    'flow_lab': '{ref:fig_flow_lab} shows the life of a test request. A test is requested only for an open visit that has a consultation, and a result is accepted only for a request that has not been cancelled. The result is shown to the requesting doctor only.',
    'flow_dispense': '{ref:fig_flow_dispense} shows dispensing. The whole prescription is one transaction: if any line fails, nothing is deducted. Stock is taken from the batch that expires first, and expired batches are never used.',
    'flow_invoice': '{ref:fig_flow_invoice} shows how the invoice is kept up to date. The same routine runs after every billable action, and it can be run again at any time without charging twice. Once a payment has been taken, a change in the price list does not change the price agreed on the invoice.',
    'flow_payment': '{ref:fig_flow_payment} shows how a payment is taken. The invoice is locked while the payment is checked and saved, so two cashiers paying one invoice at the same time are handled one after the other.',
    'flow_signin': '{ref:fig_flow_signin} shows the sign-in checks in order. The same neutral message is given for a wrong password and an unknown username, so that nobody can find out which usernames exist, and a locked account refuses even the right password.',
    'flow_access': '{ref:fig_flow_access} shows what happens at the start of every page after the user has signed in. Access never depends on hiding a link: the role is checked on the server for every request.',
}

FLOW_CAPTION = {
    'flow_register': 'Flowchart of module 1.1, Register patient',
    'flow_book': 'Flowchart of module 1.6, Book appointment',
    'flow_checkin': 'Flowchart of modules 2.1 and 2.3, Check in and record triage',
    'flow_consult': 'Flowchart of modules 2.4 and 2.7, Record consultation and close visit',
    'flow_lab': 'Flowchart of modules 3.1 and 3.4, Request tests and enter results',
    'flow_dispense': 'Flowchart of module 4.4, Dispense a prescription',
    'flow_invoice': 'Flowchart of module 5.1, Build the invoice',
    'flow_payment': 'Flowchart of module 5.2, Take a payment',
    'flow_signin': 'Flowchart of module 6.1, Sign in',
    'flow_access': 'Flowchart of module 6.3, Check permissions on every page',
}


def flow_block(d, key, title):
    d.h4(title)
    d.code(PSEUDO[key])
    d.p(FLOW_TEXT[key])
    d.figure(fig(key), FLOW_CAPTION[key], label='fig_' + key)


def build_purpose_overview(d):
    d.page_break()
    d.h1('CHAPTER FIVE: SYSTEM DESIGN SPECIFICATION')
    d.set_chapter(5)
    d.h2('5.1 Purpose and Scope')
    d.p('This chapter is the System Design Specification (SDS) of UB-HMS, release 1.0. Chapter 4 said what the system must do; this chapter says how it does it, in enough detail that a competent developer could write the source code from it and a database administrator could create the database. '
        'It covers the whole system, the six modules, and follows the faculty\'s guide for the design document: the system overview with data flow diagrams, the architecture, the software design with pseudo-code and flowcharts, the file and database design, the human-machine interface, the integrity controls, the project references and a glossary.')
    d.p('The design answers the requirements of Chapter 4 and is checked against them by the tests of Chapter 7. The design decisions that matter most are:')
    d.bullets([
        'one shared database for all departments, so that information is entered once;',
        'business rules written once on the server, with the database enforcing the most important rules a second time;',
        'one permission table that decides what every role may do, checked on every request;',
        'an audit trail written by the system itself, outside the control of the user.',
    ])

    d.h2('5.2 System Overview')
    d.p('In everyday words, UB-HMS follows the patient through the hospital. At the desk the receptionist registers the patient once and the system gives the patient an outpatient number, such as OP26-00042. If the patient has booked, the receptionist checks the patient in against the booking; if not, the patient is checked in as a walk-in. '
        'The nurse sees the patient in the triage queue and records the vital signs. The doctor then sees the patient in the doctor\'s queue, together with the triage, the allergy warning and the earlier visits, records the consultation, and may ask for tests and write a prescription. '
        'The laboratory sees each test request on its worklist and enters the result, which goes back to the doctor who asked for it. The pharmacist sees the prescription, gives the medicines, and the system takes them off the shelf, starting with the batch that expires first. '
        'Everything done for the patient is added to one invoice without anyone copying it, and the cashier takes payment and prints a receipt. Management can read reports at any time. {ref:fig_journey} shows this journey.')
    d.figure(fig('patient_journey'), 'The journey of a patient through UB-HMS', label='fig_journey', width_cm=11.5)
    d.p('{ref:fig_journey} reads from top to bottom. The solid arrows are the patient\'s path through the departments: reception, triage, consultation, and then to the laboratory and the pharmacy, with the laboratory\'s result returning to the doctor. '
        'The dashed arrows show the charges that flow from consultation, laboratory and pharmacy to the cashier without being copied by hand. The last box shows that the same records feed the management reports.')

    landscape_figure(d, '5.2.1 Level 0 Data Flow Diagram (Context Diagram)',
        'A data flow diagram shows how information moves. The level 0 diagram, also called the context diagram, shows the whole system as one process and the people outside it (Dennis et al., 2015). '
        'In {ref:fig_dfd0}, the circle is UB-HMS, the boxes are the eight kinds of user, and each arrow is a flow of information with its name. The flows into the circle are what the staff give the system; the flows out are what the system gives back.',
        fig('dfd_context'), 'Level 0 data flow diagram (context diagram) of UB-HMS', 'fig_dfd0')
    d.p('{ref:fig_dfd0} is explained as follows:')
    d.bullets([
        '**Receptionist.** Gives patient details, bookings and check-in; receives the outpatient number, the diary and the queues.',
        '**Nurse.** Gives vital signs and procedures done; receives the triage queue and allergy alerts.',
        '**Doctor.** Gives the consultation, test requests and prescriptions; receives the patient history, the test results and the stock available.',
        '**Laboratory technologist.** Receives the test worklist; gives the test results.',
        '**Pharmacist.** Receives prescriptions and stock alerts; gives dispensing records, stock received and the medicine list.',
        '**Cashier.** Receives invoices and receipts; gives payments.',
        '**Hospital manager.** Asks for reports; receives the management reports and the audit trail.',
        '**System administrator.** Gives accounts, prices and backup actions; receives the audit trail and the system health report.',
    ])

    landscape_figure(d, '5.2.2 Level 1 Data Flow Diagram',
        'The level 1 diagram opens the single circle into the six main processes, which are the six modules of the system, and shows the nine data stores they use. '
        'In {ref:fig_dfd1} the people are on the left, the processes in the middle and the data stores (open rectangles) on the right. An arrow with a head at both ends means that information goes both ways, for example the cashier gives payments and receives invoices.',
        fig('dfd_level1'), 'Level 1 data flow diagram of UB-HMS', 'fig_dfd1', max_h=11.2)
    d.table(['Process', 'What it does', 'Reads and writes (data stores)'], [
        ['1.0 Register patients and book appointments', 'Registers patients, issues OP numbers, searches and corrects records, imports records, books and changes appointments.', 'D1 Patients; D2 Appointments'],
        ['2.0 Record clinical consultation', 'Checks patients in, shows the queues, records triage, consultations and procedures, shows history and allergies, closes the visit.', 'D1, D2 (marks Attended), D3 Visits and clinical records'],
        ['3.0 Manage laboratory tests', 'Takes the doctor\'s test requests, shows the worklist, records results and returns them to the requesting doctor.', 'D4 Laboratory requests and results'],
        ['4.0 Manage pharmacy and stock', 'Takes prescriptions, shows the pharmacy queue, dispenses from batches, receives and adjusts stock, raises alerts.', 'D5 Prescriptions; D6 Medicines and stock'],
        ['5.0 Bill and report', 'Builds invoices from D3, D4 and D5, takes payments, prints receipts and produces the four reports.', 'D7 Invoices and payments; reads D1 to D5'],
        ['6.0 Manage users and security', 'Signs users in and out, manages accounts and prices, checks permissions, writes and shows the audit trail.', 'D8 Users and roles; D9 Audit trail'],
    ], widths=[4.0, 7.2, 4.1], caption='The Six Processes of the Level 1 Data Flow Diagram', label='tab_proc', size=9)
    d.p('{ref:fig_dfd1} is explained by following the main flows:')
    d.bullets([
        '**Process 1.0** receives details and bookings from the receptionist and returns the OP number and the diary. It reads and writes the patient store (D1) and the appointment store (D2).',
        '**Process 2.0** receives the check-in from the receptionist, the vital signs from the nurse and the consultation from the doctor, and returns the triage queue, the allergy alerts and the patient history. It reads the patient details and allergies from D1, marks the appointment as attended in D2, and writes visits, triage, consultations and procedures to D3.',
        '**Process 3.0** receives test requests from process 2.0 and results from the technologist, and returns the worklist and, to the requesting doctor, the results. It uses D4.',
        '**Process 4.0** receives prescriptions from process 2.0 and the pharmacist\'s dispensing, deliveries and adjustments, and returns the pharmacy queue and stock alerts. It uses D5 and D6.',
        '**Process 5.0** reads what has been done for the visit from D3, D4 and D5, builds the invoice, takes the cashier\'s payments, and returns invoices and receipts. It also reads D1 and D2 to produce the reports for the manager. It writes D7.',
        '**Process 6.0** receives sign-ins from any staff member and account and price changes from the administrator, and returns the menu for the role, the audit trail and the health report. It uses the user store (D8) and the audit trail (D9). Every other process also writes to D9 through process 6.0.',
    ])
    d.table(['Store', 'Contents', 'Tables'], [
        ['D1 Patients', 'Registered patients and the OP number counter', 'patients, op_sequences'],
        ['D2 Appointments', 'Booked, attended, missed and cancelled appointments', 'appointments'],
        ['D3 Visits and clinical records', 'Visits, triage, consultations, procedures, and the price list and ICD-10 list they use', 'visits, triage, consultations, visit_services, services, icd10_codes'],
        ['D4 Laboratory requests and results', 'Test catalogue, requests and results', 'lab_tests, lab_requests'],
        ['D5 Prescriptions', 'Prescriptions and their lines', 'prescriptions, prescription_items'],
        ['D6 Medicines and stock', 'Medicine list, batches and the stock ledger', 'medicines, medicine_batches, stock_movements'],
        ['D7 Invoices and payments', 'Invoices, their lines and payments', 'invoices, invoice_items, payments'],
        ['D8 Users and roles', 'Staff accounts and the eight roles', 'users, roles'],
        ['D9 Audit trail', 'The record of who did what', 'audit_log'],
    ], widths=[4.3, 6.2, 4.8], caption='The Data Stores of the Level 1 Diagram and the Tables That Hold Them', label='tab_stores', size=9)


def build_architecture(d):
    landscape_figure(d, '5.3 System Architecture',
        'The architecture is the way the parts of the system are arranged. UB-HMS is a three-tier web application, which is the common arrangement for hospital systems built on PHP and MySQL (Shaikh et al., 2022; Waweru, 2026): a presentation tier in the browser, an application tier on the server and a data tier in the database. '
        '{ref:fig_arch} shows the three tiers and what lives in each.',
        fig('architecture'), 'Architecture of UB-HMS: three tiers', 'fig_arch', level='h2')
    d.table(['Tier or part', 'Components', 'Responsibility'], [
        ['Presentation tier (staff computers)', 'Web browser; HTML5 pages; one CSS3 stylesheet; a small JavaScript file', 'Shows the pages, collects what staff type, and asks for confirmation before dangerous actions. It holds no business rules and no data.'],
        ['Application tier (server, Apache and PHP 8)', 'Pages (one folder for each department); security layer; business rules (one file for each module); data access layer', 'Receives each request, checks who the user is and what the role may do, applies the business rules, talks to the database and builds the page.'],
        ['Data tier (server, MariaDB)', 'Database uhai_bora_hms with 22 tables, including the audit trail', 'Stores all data, enforces keys and rules, and keeps the audit trail append-only for the application account.'],
        ['Server files', 'storage/logs (errors), storage/backups (nightly copies)', 'Application error log and backup files, outside the public web area.'],
    ], widths=[3.6, 5.9, 5.8], caption='The Parts of the Architecture', label='tab_arch', size=9)
    landscape_figure(d, '5.3.1 Software Components',
        'Inside the application tier the code is arranged so that each part has one job. {ref:fig_comp} shows the arrangement. A page in a department folder checks the permission, calls the rules in the file for its module, and shows the result; it contains no SQL. '
        'The rules call the data access layer, which uses PDO prepared statements, and the shared code supplies sessions, security headers, forms and the page frame.',
        fig('components'), 'Software components of the application tier', 'fig_comp')
    d.table(['Part', 'Files', 'Job'], [
        ['Core', 'bootstrap.php, core.php, db.php, helpers.php, layout.php', 'Starts the session, sends security headers, handles errors, provides database helpers, form fields and the page layout.'],
        ['Security', 'auth.php, csrf.php, audit.php', 'Password rules and hashing, sign-in and lockout, the permission table, form tokens, and the audit trail.'],
        ['Module rules', 'patients.php, appointments.php, clinical.php, orders.php, laboratory.php, pharmacy.php, billing.php, reports.php, users.php, dashboard.php, health.php', 'The business rules of each module, written once and used by every page that needs them.'],
        ['Pages', 'login, logout, password, index; patients/, appointments/, visits/, lab/, pharmacy/, billing/, reports/, admin/', 'One short script for each screen: check the permission, read the input, call the rules, show the page.'],
        ['Assets', 'assets/css/app.css, assets/js/app.js', 'The look of the system and a few small browser actions.'],
        ['Operations', 'database/install.php and schema.sql, scripts/backup.ps1 and restore.ps1, tests/', 'Installing, backing up, restoring and testing.'],
    ], widths=[2.6, 6.8, 5.9], caption='Software Components and Their Jobs', label='tab_comp', size=9)
    landscape_figure(d, '5.3.2 Hardware, Network and Deployment',
        'One server computer in the hospital runs Apache, PHP and MariaDB through XAMPP, and every department reaches it over the wired local network. {ref:fig_net} shows the arrangement. '
        'The server has a UPS so that a power cut does not damage the database, and the nightly backups are copied to an external hard disk. Printers are attached to the computers of reception, pharmacy and cashier and are used through the browser.',
        fig('network'), 'Network and deployment of UB-HMS', 'fig_net')
    d.table(['Item', 'Design decision', 'Reason'], [
        ['Number of servers', 'One, for the web server and the database', 'A medium-sized hospital with ten or so users at a time does not need more (section 4.5.1); simpler to look after.'],
        ['Network', 'Wired LAN; the database accepts connections from the server only', 'Wired links proved more dependable than wireless ones in Kenyan hospitals (Muinga et al., 2020), and the database is not reachable from staff computers.'],
        ['Addresses', 'Staff open the server\'s name or address in the browser', 'No software needs installing on the staff computers.'],
        ['Protocol', 'HTTP, with HTTPS recommended', 'Encryption protects passwords and records on shared networks (section 4.4.4).'],
        ['Power and backup', 'UPS for the server; backup script every night; copy to an external disk', 'A hospital cannot lose a day of records; restore is tested (Chapter 7).'],
        ['Data resources', 'One database and two folders (logs and backups) outside the public pages', 'Keeps data away from anything a browser can request.'],
    ], widths=[3.0, 6.0, 6.3], caption='Deployment Design Decisions', label='tab_deploy', size=9)
    d.p('The users of the architecture are the eight roles of section 4.2.1, who see it only as web pages. The interfaces are the web pages and the CSV files for import and export (section 4.4). The hardware is the server and the office equipment of section 4.2.2.')


def build_software_design(d):
    d.h2('5.4 Software Design')
    d.p('A software module is the smallest unit of the design. UB-HMS has six processes (the level 1 diagram), and each is divided into modules numbered 1.1, 1.2 and so on, as the structured chart in section 5.4.7 shows. '
        'This section gives, for each process, the pseudo-code of its main modules and the flowchart that represents it. The pseudo-code is written in structured English: keywords such as IF, FOR and BEGIN TRANSACTION show the logic, and the words between them say what happens (Sommerville, 2016). '
        'The flowcharts use the usual shapes: rounded boxes for start and end, rectangles for steps, diamonds for decisions and slanted boxes for input and output.')
    d.h3('5.4.1 Design Conventions')
    d.bullets([
        '**One transaction for one business action.** Anything that changes several records (booking, dispensing, payment, test request, invoice update) runs inside a database transaction, so it is saved completely or not at all.',
        '**Check first, then act.** Input is checked on the server before anything is saved; the checks in the browser are only a convenience.',
        '**Prepared statements only.** No page builds SQL from typed text.',
        '**Errors are values, not surprises.** Each rule returns its errors by field name, and the page shows them beside the fields.',
        '**Audit every important action.** The rule that performs the action writes the audit entry, so that no page can forget it.',
    ])
    d.h3('5.4.2 Process 1.0: Register Patients and Book Appointments')
    flow_block(d, 'flow_register', '5.4.2.1 Register patient (module 1.1)')
    flow_block(d, 'flow_book', '5.4.2.2 Book appointment (module 1.6)')
    d.p('The other modules of process 1.0 are simple. Module 1.4 (search and correct) matches the text typed against the OP number, ID number, phone and names, treating every character as plain text, and pages the results 25 at a time. '
        'Module 1.5 (import) reads a CSV file of up to 500 rows, classifies each row without saving, and registers only the valid rows after confirmation, using module 1.1 for each. '
        'Module 1.7 changes an appointment from Booked to Cancelled or Missed, and releases the slot by emptying its slot key.')
    d.h3('5.4.3 Process 2.0: Record Clinical Consultation')
    flow_block(d, 'flow_checkin', '5.4.3.1 Check in and record triage (modules 2.1 and 2.3)')
    flow_block(d, 'flow_consult', '5.4.3.2 Record consultation and close visit (modules 2.4 and 2.7)')
    d.p('Module 2.2 builds the queues: it lists open visits with their stage (waiting for triage, waiting for the doctor, with the doctor), oldest first, with the waiting time, and shows a doctor only the patients assigned to that doctor and those not yet assigned. '
        'Module 2.5 records a procedure with a quantity from 1 to 20. Module 2.6 lists a patient\'s earlier visits, newest first, and shows the diagnosis, tests and medicines only to clinical roles.')
    d.h3('5.4.4 Process 3.0: Manage Laboratory Tests')
    flow_block(d, 'flow_lab', '5.4.4.1 Request tests and enter results (modules 3.1 and 3.4)')
    d.p('Module 3.2 lists open requests oldest first with the patient, the test, the requesting doctor and the notes. Module 3.3 marks a request In progress. Module 3.5 shows a result to the requesting doctor on the My test results page and on the consultation page; the query is limited to the doctor\'s own requests, so another doctor cannot see it there.')
    d.h3('5.4.5 Process 4.0: Manage Pharmacy and Stock')
    flow_block(d, 'flow_dispense', '5.4.5.1 Dispense a prescription (module 4.4)')
    d.p('Module 4.2 receives stock: it checks the batch number, the quantity and that the expiry date has not passed, creates or adds to the batch, and writes a positive ledger entry. '
        'Module 4.5 adjusts stock with a reason and refuses a result below zero. Module 4.6 works out the alerts: a medicine is Low when its usable stock is at or below the reorder level and Out when it is zero; a batch is Expiring within 90 days of its expiry date and Expired after it. Usable stock never counts expired batches.')
    d.h3('5.4.6 Process 5.0: Bill and Report')
    flow_block(d, 'flow_invoice', '5.4.6.1 Build the invoice (module 5.1)')
    flow_block(d, 'flow_payment', '5.4.6.2 Take a payment (module 5.2)')
    d.p('Modules 5.4 to 5.7 produce the four reports. Each takes a start and an end date (this month by default), runs read-only summary queries on the records, and returns totals that the page shows and that module 5.8 writes as a CSV file. '
        'In the CSV file any cell that begins with =, +, - or @ gets a leading apostrophe, so that a spreadsheet program shows it as text and does not run it.')
    d.h3('5.4.7 Process 6.0: Manage Users and Security')
    flow_block(d, 'flow_signin', '5.4.7.1 Sign in (module 6.1)')
    flow_block(d, 'flow_access', '5.4.7.2 Check permissions (module 6.3)')
    d.p('Module 6.2 creates and changes accounts, refuses duplicate usernames, and will not deactivate the last active administrator or the administrator\'s own account. Module 6.4 writes the audit entries. Module 6.5 changes prices and writes the old and new price. '
        'Module 6.6 runs the health checks. These modules are short and follow the same pattern: check, act inside a transaction, write the audit entry.')
    landscape_figure(d, '5.4.8 Structured Chart',
        'The structured chart in {ref:fig_tree} shows the breakdown of the whole system into its lowest manageable parts. Each box is a module, and the tree shows that the system is made of six processes, each of which is made of from five to eight modules. '
        'The numbering is the same as in the pseudo-code and the flowcharts.',
        fig('structured_chart'), 'Structured chart of UB-HMS', 'fig_tree')
