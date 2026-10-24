"""Chapter 4, part 1: introduction, overall description and the system features (sections 4.1 to 4.3.2)."""
from doc_common import fig
from req_data import FEATURES, MODULES, OBJECTIVES

W = 15.29


def build_intro(d):
    d.page_break()
    d.h1('CHAPTER FOUR: SYSTEM REQUIREMENTS SPECIFICATION')
    d.set_chapter(4)

    d.h2('4.1 Introduction')
    d.h3('4.1.1 Purpose')
    d.p('This chapter is the System Requirements Specification (SRS) of the Uhai Bora Hospital Management System (UB-HMS), release 1.0. '
        'It states what the system must do and how well it must do it, so that the developer, the supervisor, the hospital and the testers all work from one description of the finished product. '
        'It does not say how the system is built. That is the work of the System Design Specification in Chapter 5.')
    d.p('The product specified here is the whole of UB-HMS: the six modules named in the approved proposal (Waweru, 2026), listed in {ref:tab_modules}. '
        'Nothing in the system is left out of this chapter, and nothing is specified that the system does not do. Release 1.0 is the version produced by the five sprints described in Chapter 6.')
    d.table(['Module', 'What it does', 'Objective'], [
        ['1. Patient registration and appointments', 'Registers each patient once with a unique outpatient number, finds and corrects records, and books appointments without clashes.', '1'],
        ['2. Clinical consultation', 'Moves the patient from check-in to triage and to the doctor, and records vital signs, consultations, diagnoses and procedures.', '2'],
        ['3. Laboratory management', 'Sends test requests to the laboratory and returns each result to the doctor who requested it.', '2'],
        ['4. Pharmacy and inventory', 'Shows prescriptions in the pharmacy, takes stock off the shelf when medicines are given, and warns about low stock and expiry.', '3'],
        ['5. Billing and reports', 'Builds itemised invoices from the services recorded, takes payments, and produces management reports.', '4'],
        ['6. User management and security', 'Personal accounts, role-based access, protected passwords and an audit trail.', '5'],
    ], widths=[4.4, 8.9, 2.0], caption='The Six Modules of UB-HMS and the Objectives They Serve', label='tab_modules', align=['l', 'l', 'c'])
    d.p('This chapter follows the faculty\'s guide for the SRS, which is based on the IEEE recommended practice for software requirements specifications (IEEE, 1998) and on the template of Wiegers and Beatty (2013). '
        'The guide numbers two of its headings "3"; in this chapter the sections are numbered one after another inside Chapter 4.')

    d.h3('4.1.2 Intended Audience and Reading Suggestions')
    d.p('Different readers need different parts of this chapter. {ref:tab_audience} lists the readers and suggests where each should begin.')
    d.table(['Reader', 'What the reader needs', 'Suggested reading order'], [
        ['Project supervisor and examiners', 'To judge whether the requirements are complete, clear and traceable to the objectives.', '4.1.3, 4.3.5, 4.3.2, 4.5'],
        ['Hospital management', 'To confirm that the system will do what the hospital needs and to see what it will not do.', '4.1.3, 4.2.1, 4.3.3, 4.3.4'],
        ['Staff who will use the system', 'To check that their own work is described correctly.', '4.2.1, 4.3.3, 4.3.4'],
        ['Developers and maintainers', 'The exact behaviour to build and the rules that must hold.', '4.3.2, 4.4, 4.5, 4.6'],
        ['Testers', 'Statements that can be checked, with the tests that check them.', '4.3.2 (requirement tables), 4.5, Chapter 7'],
        ['Trainers and manual writers', 'The tasks each role performs and the rules users will meet.', '4.2.1, 4.3.3, then Chapter 8'],
    ], widths=[3.6, 7.2, 4.5], caption='Readers of This Chapter and Where to Begin', label='tab_audience')
    d.p('The chapter is organised as follows. Section 4.2 describes the product, its users and its setting. '
        'Section 4.3 gives the functional requirements: the system features with numbered requirements, the use cases, the user stories and the link from every requirement to the five objectives. '
        'Section 4.4 describes the interfaces with users, hardware, other software and the network. Section 4.5 sets the non-functional requirements for performance, safety, security and quality. '
        'Section 4.6 covers other requirements, such as data and legal rules, section 4.7 lists the references, and Appendix A defines the terms and abbreviations.')

    d.h3('4.1.3 Project Scope')
    d.p('UB-HMS is a web-based system that staff of Uhai Bora Hospital, a medium-sized private hospital in Nairobi County, open in a web browser on the hospital network. '
        'It keeps the hospital\'s patient, clinical, laboratory, pharmacy and billing information in one database that every department shares, and each user sees only what their role needs. '
        'Its purpose is to improve the speed, accuracy and security of information management at the hospital, which is the main objective of the project (Waweru, 2026).')
    d.p('The proposal named five problems in the hospital\'s paper-based way of working. {ref:tab_problems} shows how each is answered, so that the scope of the system can be read as a response to those problems and not as a list of features.')
    d.table(['Problem in the proposal (section 1.2)', 'How UB-HMS answers it', 'Objective', 'Features'], [
        ['i. Slow record retrieval and appointment clashes', 'One OP number per patient, a search by name, number, ID or phone, and a diary in which the same doctor cannot be booked twice for a slot.', '1', 'F01 to F04'],
        ['ii. Delays and losses in clinical and laboratory information', 'The consultation, test request and result are linked in one record; results return to the requesting doctor without paper.', '2', 'F05 to F10'],
        ['iii. Inaccurate pharmacy stock records', 'Dispensing and stock change in the same step; batches with expiry dates; low-stock and expiry alerts.', '3', 'F11 to F13'],
        ['iv. Billing errors and slow reporting', 'Invoices built automatically from the services recorded; payments with receipts; four management reports on demand.', '4', 'F14 to F16'],
        ['v. Weak protection of patient information', 'Personal accounts, role-based access, protected passwords, and an audit trail of who did what.', '5', 'F17 to F20'],
    ], widths=[4.2, 6.6, 2.0, 2.5], caption='How the System Answers the Five Problems of the Proposal', label='tab_problems', align=['l', 'l', 'c', 'l'])
    d.p('The scope has limits that were fixed in the proposal (section 1.7) and are repeated here so that no reader expects more than the system gives:')
    d.bullets([
        '**Geographical:** one hospital, Uhai Bora Hospital, and its local network.',
        '**Functional:** the six modules in {ref:tab_modules}. The hospital\'s outpatient flow is covered from registration to payment.',
        '**Exclusions:** the system does not diagnose patients or calculate doses. It also excludes payroll, human resources, procurement, ward management, theatre, radiology, mobile applications and connection to national systems.',
        '**Data:** development and testing used synthetic (made-up) data only, because patient information is confidential.',
    ])
    d.p('The system supports the hospital\'s wider goals of shorter waiting times, fewer lost results and missed charges, accurate stock, and compliance with the Health Act, 2017, the Data Protection Act, 2019 and the Digital Health Act, 2023 (Republic of Kenya, 2017, 2019, 2023).')


def build_overall(d):
    d.h2('4.2 Overall Description')
    d.h3('4.2.1 User Classes and Characteristics')
    d.p('Eight user classes use UB-HMS. Each has one role in the system, and the role decides which pages the user can open. '
        'The staff numbers come from the target population in the proposal (Table 3.1); the system administrator is a person the hospital designates from its management or IT support. '
        'The columns for frequency and skill are the developer\'s working assumptions, taken from the proposal\'s description of each staff category. '
        'They have not yet been confirmed with the staff and are to be checked during user acceptance testing.')
    d.table(['User class', 'Staff', 'Main functions in UB-HMS', 'Use and skill (assumed)', 'Privilege'], [
        ['Receptionist / records clerk', '6', 'Register and search patients, import records, book and change appointments, check patients in', 'All day; basic computer skills; the first and busiest screens', 'Patient and appointment data; sees a visit but not its clinical record'],
        ['Nurse', '12', 'See the triage queue, record vital signs and procedures', 'All day, between patients; basic skills', 'Clinical data of patients in triage; no consultation or billing pages'],
        ['Doctor / clinical officer', '8', 'See the doctor\'s queue and patient history, record consultations, request tests, prescribe, close visits, read results', 'All day; basic skills; needs very fast access to history', 'Full clinical record of patients; may view stock; cannot dispense or bill'],
        ['Laboratory technologist', '4', 'See the worklist, start tests, enter results', 'All day; comfortable with instruments and forms', 'Test requests and results only'],
        ['Pharmacist', '4', 'See prescriptions, dispense, receive stock, adjust stock, keep the medicine list, view the stock report', 'All day; careful with quantities and expiry dates', 'Pharmacy data and the stock report only'],
        ['Cashier / billing clerk', '5', 'See invoices, take payments, print receipts, see own collections', 'All day; used to cash handling and mobile money', 'Invoices and payments only; cannot edit a charge'],
        ['Hospital manager', '3', 'Read the four reports and the audit trail, see stock levels and alerts', 'A few times a day or week; moderate skills', 'Reports and audit trail; no individual patient or invoice pages'],
        ['System administrator', '1 or 2', 'Create and manage accounts, keep the price list, check system health and backups', 'Occasional; the most technical user', 'Accounts, prices, audit trail and health only; no patient records'],
    ], widths=[2.6, 1.3, 4.7, 3.6, 3.7], size=9, caption='User Classes and Their Characteristics', label='tab_users', align=['l', 'c', 'l', 'l', 'l'])
    d.p('Receptionists, nurses, doctors and cashiers are the favoured classes. They use the system all day and a slow or confusing screen would make patients wait, so the design puts their speed and ease of use first. '
        'Managers and the administrator use it less often, so a few extra clicks matter less for them. Patients do not use the system: their information is stored in it, but they never sign in.')

    d.h3('4.2.2 Operating Environment')
    d.p('UB-HMS runs on one server computer in the hospital, and staff reach it from their own computers through a web browser on the wired hospital network. {ref:tab_env} states what is needed and what was actually used for development and testing. '
        'The minimum and recommended specifications are those of the proposal (Table 3.5).')
    d.table(['Item', 'Minimum', 'Recommended', 'Used in this project'], [
        ['Server computer', 'Core i3, 4 GB RAM, 128 GB storage', 'Core i5, 8 GB RAM, with a UPS', 'Core i7-1165G7, 15.8 GB RAM, solid-state disk'],
        ['Server operating system', 'Windows 10 or 11, or Ubuntu Linux', 'Windows 11', 'Windows 11 Pro'],
        ['Server software', 'XAMPP with Apache 2.4, PHP 8.1 and MariaDB 10.4', 'The same, kept up to date', 'XAMPP 8.2.12: Apache 2.4.58, PHP 8.2.12, MariaDB 10.4.32'],
        ['Staff computers', 'Core i3, 4 GB RAM, any current browser', 'Core i5, 8 GB RAM', 'Microsoft Edge (automated browser)'],
        ['Browser', 'Chrome, Edge or Firefox, current version, JavaScript on', 'The same', 'Microsoft Edge'],
        ['Network', 'Wired local area network', 'Wired LAN with a switch for each floor', 'The server\'s own network address (localhost)'],
        ['Printers', 'Office printer', 'Office printer and a receipt printer', 'Print layout checked with the browser\'s print preview'],
        ['Backup storage', 'Flash disk', 'External hard disk, copied weekly', 'Local folder'],
    ], widths=[3.0, 4.2, 3.6, 4.5], size=9, caption='Operating Environment', label='tab_env')
    d.p('The software must coexist with other programs on the server, since XAMPP also runs other sites on the same machine. UB-HMS therefore lives in its own folder and its own database, and uses no extra PHP libraries beyond those that XAMPP installs (PDO for MySQL, mbstring and the standard session and hash functions).')

    d.h3('4.2.3 Design and Implementation Constraints')
    d.bullets([
        '**Law and regulation.** The Health Act, 2017 requires health information to be confidential. The Data Protection Act, 2019 treats health data as sensitive and requires data protection by design and by default. The Digital Health Act, 2023 sets rules for digital health systems (Republic of Kenya, 2017, 2019, 2023). These laws decide the security and audit requirements in sections 4.3.2 and 4.5.',
        '**Technology.** The proposal fixes the tools: HTML5, CSS3 and JavaScript in the browser, PHP on the server and MySQL (MariaDB) for storage, running on XAMPP, written in Visual Studio Code with Git for version control. No cloud service or paid software may be needed.',
        '**Hardware and network.** The system must work on ordinary office computers and a wired local network, without internet access, because the hospital cannot rely on a steady connection.',
        '**Time and money.** Development ran in five two-week sprints and was self-funded, with a budget of KSh 38,500 (Waweru, 2026). The scope was kept to what these allow.',
        '**Data.** No real patient information may be used. All development and testing data is synthetic.',
        '**Security conventions.** The design follows the OWASP Top 10 (OWASP Foundation, 2021): prepared statements, escaped output, tokens on forms, hashed passwords, and least privilege.',
        '**Maintenance by the hospital.** The code must be readable and organised so that a hospital technician or a later student can maintain it: one file for each module\'s rules, short comments and an automated test suite.',
        '**Language and units.** All screens are in English. Money is in Kenya shillings (KSh), dates are shown as 24 Oct 2026, and the time zone is Africa/Nairobi (UTC+3).',
    ])

    d.h3('4.2.4 User Documentation')
    d.p('{ref:tab_docs} lists the documentation delivered with the software. All documents are in English and are supplied as Word and PDF files, with a printed copy for each department.')
    d.table(['Document', 'For', 'Where'], [
        ['System User Manual: step-by-step instructions for each role, with screenshots, troubleshooting and a list of questions and answers', 'All staff', 'Chapter 8'],
        ['Training plan and changeover plan', 'Management, trainers', 'Chapter 6'],
        ['Installation and deployment guide, backup and restore instructions', 'Administrator, technician', 'Chapter 6 and the README file'],
        ['Test plan and test results', 'Supervisor, management, maintainers', 'Chapter 7'],
        ['Messages and hints on every screen: a hint under each field, and an error message beside any field that is wrong', 'All staff', 'Inside the system'],
        ['Security notes for the administrator (SECURITY.md) and the security review', 'Administrator, supervisor', 'Repository'],
    ], widths=[8.6, 3.8, 2.9], caption='User Documentation Delivered with UB-HMS', label='tab_docs')

    d.h3('4.2.5 Assumptions and Dependencies')
    d.p('The requirements rest on the assumptions and dependencies in {ref:tab_assume}. They are assumptions, not known facts. If one proves wrong, the effect shown in the last column follows, and the requirement concerned must be reviewed.')
    d.table(['No.', 'Assumption or dependency', 'If it is wrong'], [
        ['A-01', 'The hospital provides a server computer with a UPS and a wired network that reaches every department.', 'Staff cannot reach the system; Chapter 6 lists the hardware to buy.'],
        ['A-02', 'Staff have basic computer skills and can learn their screens in one to two hours of training.', 'More training is needed; the training plan in Chapter 6 allows for it.'],
        ['A-03', 'The clinic works Monday to Saturday, 08:00 to 17:00, and a 20-minute appointment is long enough for most patients.', 'Opening hours and slot length can be changed in one configuration file (REQ-012).'],
        ['A-04', 'The hospital will use the outpatient number format OPyy-nnnnn for all patients, old and new.', 'The format would have to be changed in the registration rules.'],
        ['A-05', 'Management supplies the price list and the opening stock before the system is used.', 'Invoices and stock would be wrong; the administrator and pharmacist enter them before changeover.'],
        ['A-06', 'The cashier enters M-Pesa confirmation codes, card approval codes and insurance claim numbers by hand; claims to insurers are made outside the system.', 'Direct links to M-Pesa or insurers would be a new requirement.'],
        ['A-07', 'Only outpatients are served. Admission to wards is not part of the system.', 'Ward management would be a new module.'],
        ['D-01', 'XAMPP (Apache, PHP, MariaDB) is installed and kept running on the server. MariaDB 10.4, which XAMPP 8.2.12 includes, has passed its end of support, so the hospital should move to a current version.', 'The system would stop; a newer version has not been tested.'],
        ['D-02', 'A current browser is installed on each staff computer.', 'Pages may not display correctly in old browsers.'],
        ['D-03', 'The power supply is kept steady by the UPS and a nightly backup is made and copied off the server.', 'Data could be lost; the health page warns when the backup is old.'],
        ['D-04', 'Staff representatives and the supervisor are available for user acceptance testing and training.', 'The system would go live untested by its users.'],
    ], widths=[1.3, 8.4, 5.6], size=9, caption='Assumptions and Dependencies', label='tab_assume')
    d.p('Interviews, observation and a document review were planned in the proposal (section 3.2) to confirm requirements with a purposive sample of 24 staff. '
        'The requirements in this chapter come from the problem statement, the objectives and the user stories the proposal\'s method calls for. '
        'No interview findings are quoted, and nothing in this chapter should be read as the opinion of a named group of staff. Where an item above is an assumption about users, it is marked so that user acceptance testing can confirm or correct it.')


def build_features(d):
    d.h2('4.3 Functional Requirements')
    d.h3('4.3.1 How the Requirements Were Written')
    d.p('A functional requirement is something the system must do for its users. Each requirement in this chapter has a tag of the form REQ-nnn, is written as a sentence that begins "The system shall", and can be checked by a test (Wiegers & Beatty, 2013). '
        'Requirements also say how the system must answer wrong input, because a hospital system is judged by what it does when a user makes a mistake.')
    d.p('The requirements are grouped into {n} system features (F01 to F{n}), arranged by the six modules of the proposal. '
        'Each feature has a priority of High, Medium or Low. High means the hospital cannot work without it, Medium means the work is slower or riskier without it, and Low means it is a convenience. '
        'For every requirement the tables give the priority, the use case it belongs to (section 4.3.3) and the tests that check it. '
        'The unit tests (UT-), the end-to-end tests (E2E-) and the other checks named in the last column are described in Chapter 7. A test id such as "E2E-200 to E2E-224" means every test in that range.'.replace('{n}', str(len(FEATURES))))

    d.h3('4.3.2 System Features')
    d.p('The features follow. Each has a short description with its priority, the stimulus and response sequences that show how a user and the system exchange actions, and a table of requirements.')
    current = None
    n_total = 0
    for i, f in enumerate(FEATURES, 1):
        if f['module'] != current:
            current = f['module']
            d.h4(f'Module {current}: {MODULES[current]}')
        d.h4(f'4.3.2.{i} {f["title"]} ({f["key"]})')
        objs = ', '.join(str(o) for o in f['objectives'])
        d.p(f'**Description and priority.** {f["description"]} Priority: **{f["priority"]}**. Objective{"s" if len(f["objectives"]) > 1 else ""}: {objs}.', keep_next=True)
        d.p('**Stimulus and response sequences.**', keep_next=True)
        d.bullets([f'*Stimulus:* {s} *Response:* {r}' for s, r in f['stimulus']])
        rows = [[rid, text, pri, uc, tests] for rid, text, pri, uc, tests in f['reqs']]
        n_total += len(rows)
        d.table(['ID', 'Requirement', 'Priority', 'Use case', 'Verified by'], rows, widths=[1.5, 7.0, 1.3, 1.5, 4.0], size=8.5,
                caption=f'Functional Requirements for {f["title"]}', label='tab_' + f['key'], align=['l', 'l', 'c', 'c', 'l'])
