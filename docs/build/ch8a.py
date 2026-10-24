"""Chapter 8, part 1: introduction, getting started, navigation and the core tasks of each role (8.1 to 8.3)."""
from doc_common import fig, shot, shot_main

W = 13.0


def pic(d, name, caption, label=None, crop=True, w=W, h=10.0):
    d.figure(shot_main(name) if crop else shot(name), caption, label=label, width_cm=w, max_height_cm=h)


def task(d, code, title, steps, intro=None, tips=None):
    d.p(f'**Task {code}: {title}**', keep_next=True)
    if intro:
        d.p(intro)
    d.numbered(steps)
    if tips:
        d.note('Note. ' + tips)


def build_intro(d):
    d.page_break()
    d.h1('CHAPTER EIGHT: SYSTEM USER MANUAL')
    d.set_chapter(8)
    d.h2('8.1 Introduction')
    d.h3('8.1.1 System Overview')
    d.p('The Uhai Bora Hospital Management System, called UB-HMS, is the computer system of Uhai Bora Hospital in Nairobi. It replaces the paper files and registers that the departments used before. '
        'A patient is registered once at the reception and then followed through triage, the doctor, the laboratory, the pharmacy and the cashier. Each department sees what it needs, nobody has to copy information from one paper to another, and the system works out the bill from the services given.')
    d.p('The system has six parts, called modules: (1) patient registration and appointments, (2) clinical consultation, (3) laboratory, (4) pharmacy and stock, (5) billing and reports, and (6) users and security. You use only the parts that belong to your work, because the menu shows only what your role may do.')
    d.h3('8.1.2 Target Users')
    d.p('This manual is for everyone who uses UB-HMS. {ref:tab_who} shows which part to read. You do not need to read the whole manual: read 8.2 (getting started), then the part for your role in section 8.3, and 8.6 if you meet a problem.')
    d.table(['If you are a...', 'Read', 'You will learn how to'], [
        ['Receptionist or records clerk', '8.2, 8.3 (Reception), 8.4.1', 'Register and find patients, book appointments, check patients in, import records'],
        ['Nurse', '8.2, 8.3 (Nurse)', 'Use the triage queue, record vital signs and procedures'],
        ['Doctor or clinical officer', '8.2, 8.3 (Doctor)', 'Read a patient\'s history, record a consultation, request tests, prescribe, read results, close a visit'],
        ['Laboratory technologist', '8.2, 8.3 (Laboratory)', 'Use the worklist and enter results'],
        ['Pharmacist', '8.2, 8.3 (Pharmacy)', 'Dispense, receive and adjust stock, keep the medicine list, read alerts'],
        ['Cashier or billing clerk', '8.2, 8.3 (Cashier)', 'Open invoices, take payments, print receipts, see your collections'],
        ['Hospital manager', '8.2, 8.3 (Manager)', 'Read the four reports, download them, search the audit trail'],
        ['System administrator', '8.2 to 8.5', 'Manage accounts and prices, check system health, make and restore backups, update the system'],
    ], widths=[4.0, 4.0, 7.3], caption='Which Part of the Manual to Read', label='tab_who', size=9)
    d.h3('8.1.3 System Requirements')
    d.p('Staff computers need only a current web browser: Google Chrome, Microsoft Edge or Mozilla Firefox. No program has to be installed, and no internet connection is needed, because the system runs on the hospital\'s own server and is used through the hospital network. A computer with 4 GB of memory is enough (Waweru, 2026). The system was tested with Microsoft Edge.')
    d.table(['Item', 'What is needed'], [
        ['Your computer', 'Core i3 or better, 4 GB RAM, a current browser with JavaScript on, a connection to the hospital network'],
        ['Printing', 'Any office printer; a receipt printer at the cashier. Print from the browser with the Print button on the page.'],
        ['Server (for the administrator)', 'Windows 10 or 11 with XAMPP 8.2 (Apache, PHP, MariaDB), a UPS, and a nightly backup; see section 8.2.1 and Chapter 6'],
        ['Your account', 'A username and a one-time password from the administrator'],
    ], widths=[4.0, 11.3], caption='What You Need to Use UB-HMS', label='tab_need', size=9, align=['l', 'l'])
    d.note('Privacy reminder. Patient information is confidential. Sign in only with your own account, never share your password, sign out when you leave the computer, and open only the records your work needs. Everything you do is recorded in the audit trail, with your name, the computer and the time.')


def build_getting_started(d):
    d.h2('8.2 Getting Started')
    d.h3('8.2.1 Installation and Setup')
    d.p('Staff do not install anything. The administrator installs the system once on the server, following the deployment plan in Chapter 6. In short, the administrator:')
    d.numbered([
        'installs XAMPP and starts Apache and MySQL (MariaDB) as services;',
        'copies the folder ub-hms to C:\\xampp\\htdocs\\ub-hms;',
        'runs the installer: C:\\xampp\\php\\php.exe database\\install.php --admin-user=sysadmin --admin-pass="a-strong-password" (without --demo for real use);',
        'creates the restricted database account with database\\create_app_user.php and puts its details in config\\config.local.php;',
        'schedules the nightly backup (section 8.5.3);',
        'creates an account for every member of staff (section 8.3, task A1).',
    ])
    d.p('Then each member of staff puts a shortcut to the system on the desktop: the address is given by the administrator, for example http://ub-hms/ub-hms/ (the name depends on the hospital\'s network).')
    d.h3('8.2.2 Accessing the System')
    d.p('**Task S1: Sign in for the first time**', keep_next=True)
    d.numbered([
        'Open your browser and type the address of the system, or click the shortcut. The sign-in page opens.',
        'Type the username the administrator gave you and the one-time password. Press **Sign in**.',
        'The page "Change password" opens, and you cannot go anywhere else until you have used it. In "Current password" type the one-time password. In "New password" type a password of your own and type it again in "Confirm new password".',
        'Press **Change password**. The dashboard opens.',
    ], )
    pic(d, '01-login', 'The sign-in page', 'fig_m_login', crop=False, w=10.0, h=9)
    d.p('A good password has at least 10 characters, with at least one capital letter, one small letter and one number, and does not contain your username. If it is not good enough, the page lists what is missing, as {ref:fig_m_weak} shows.')
    pic(d, '04-password-weak', 'The change-password page refusing a weak password', 'fig_m_weak', w=11.0, h=9)
    d.p('**Task S2: Sign in on other days**', keep_next=True)
    d.numbered(['Open the system, type your username and password, and press **Sign in**.', 'The dashboard of your role opens.'])
    d.p('**Task S3: Change your password**', keep_next=True)
    d.numbered(['Press **Password** at the top right of any page.', 'Type your current password, then the new one twice, and press **Change password**.'])
    d.p('**Task S4: Sign out**', keep_next=True)
    d.numbered(['Press **Sign out** at the top right. Always do this when you leave the computer. If you forget, the system signs you out by itself after 30 minutes without use.'])
    d.note('If you type the wrong password five times, your account is locked for 15 minutes, even if you then type the right one. Wait, or ask the administrator to unlock it. See section 8.6.')
    pic(d, '02-login-error', 'The message shown after a wrong password. It does not say which part was wrong.', 'fig_m_wrong', crop=False, w=10.0, h=9)
    d.h3('8.2.3 Interface Overview')
    d.p('Every page has the same four parts, shown in {ref:fig_m_layout}:')
    d.bullets([
        '**The top bar** shows the name of the system, your name and your role, the **Password** button and the **Sign out** button.',
        '**The menu** on the left lists the pages your role may use, in groups such as Patients, Appointments, Clinic, Laboratory, Pharmacy, Billing, Reports and Administration. The page you are on is highlighted. You see only what you may use.',
        '**The work area** in the middle shows the page. The first page after sign-in is the dashboard, with a welcome line, counts of the work waiting for you, and a list of "What you can do".',
        '**The footer** reminds you that the system is confidential and that all activity is recorded.',
    ])
    pic(d, '26-doctor-dashboard', 'The dashboard of a doctor, with the top bar, the menu, the work area and the footer', 'fig_m_layout', crop=False, w=14.5, h=10.5)
    d.p('Buttons follow the same rules everywhere. A solid green button is the main action of the page (Save, Register patient, Record payment). An outlined button is a second choice (Cancel, Back, Print). A red button does something that cannot easily be undone, such as Deactivate, and asks you to confirm. '
        'Words in coloured badges, such as Waiting for triage, Paid or Low, always tell you the state of a record.')
    d.p('**Forms.** A red star marks a field you must fill. If you make a mistake the page is shown again with what you typed, a list of the problems at the top and a message under each wrong field. Correct the fields and press the button again. '
        '**Narrow screens.** On a small screen the menu is behind the button with three lines at the top left; press it to open the menu.')
    pic(d, '56-mobile-menu', 'The dashboard on a small screen', 'fig_m_mobile', crop=False, w=5.5, h=10)


def build_navigation(d):
    d.h2('8.3 System Navigation and Core Functionalities')
    d.h3('8.3.1 Navigation')
    d.p('Use the menu on the left to go to a page. {ref:tab_menu} shows the menu of each role. A page that is not in your menu is not for your role, and if you type its address the system shows a "not allowed" page and records the attempt.')
    d.table(['Menu group and page', 'Recep-tion', 'Nurse', 'Doctor', 'Lab', 'Pharm-acist', 'Cashier', 'Manager', 'Admin'], [
        ['Patients: Search patients', '●', '●', '●', '', '', '', '', ''],
        ['Patients: Register patient, Import patients', '●', '', '', '', '', '', '', ''],
        ['Appointments: Appointment diary', '●', '●', '●', '', '', '', '', ''],
        ['Appointments: Book appointment, Check-in', '●', '', '', '', '', '', '', ''],
        ['Clinic: Triage queue', '', '●', '', '', '', '', '', ''],
        ['Clinic: Consultation queue', '', '', '●', '', '', '', '', ''],
        ['Clinic: All open visits', '●', '●', '●', '', '', '', '', ''],
        ['Laboratory: Laboratory worklist', '', '', '', '●', '', '', '', ''],
        ['Laboratory: My test results', '', '', '●', '', '', '', '', ''],
        ['Pharmacy: Prescriptions', '', '', '', '', '●', '', '', ''],
        ['Pharmacy: Medicine stock, Batches', '', '', '●', '', '●', '', '●', ''],
        ['Pharmacy: Receive stock, Medicine list', '', '', '', '', '●', '', '', ''],
        ['Billing: Invoices', '', '', '', '', '', '●', '', ''],
        ['Reports: Attendance, Revenue, Laboratory workload', '', '', '', '', '', '', '●', ''],
        ['Reports: Stock', '', '', '', '', '●', '', '●', ''],
        ['Administration: User accounts, Price list, System health', '', '', '', '', '', '', '', '●'],
        ['Administration: Audit trail', '', '', '', '', '', '', '●', '●'],
    ], widths=[6.0, 1.1, 1.1, 1.1, 1.0, 1.2, 1.2, 1.3, 1.2], size=8, caption='The Menu of Each Role', label='tab_menu', align=['l'] + ['c'] * 8)
    d.p('Besides the menu, pages link to each other along the work: from a diary entry to Check in, from a queue to the next step, from an invoice to its payment form. Each page also has a heading that tells you where you are. The Dashboard link at the top of the menu always takes you home.')
    d.h3('8.3.2 Core Features')
    d.p('The tasks of each role follow. Each task says where to start, what to do step by step, and what you should see. Pictures show the real screens with made-up patients. Tasks are numbered with the first letter of the role: R for reception, N for nurse, D for doctor, L for laboratory, P for pharmacy, C for cashier, M for manager and A for administrator.')

    # ---------------------------------------------------------------- reception
    d.h4('Receptionist and records clerk')
    task(d, 'R1', 'Register a new patient', [
        'Open **Patients > Search patients** and search first, to make sure the patient is not already registered (task R2).',
        'Open **Patients > Register patient**.',
        'Under "Personal details" fill in the first name, last name, sex and date of birth (the star marks required fields). Add the middle name if there is one. If the date of birth is only a guess, tick "Date of birth is an estimate".',
        'Type the ID, passport or birth certificate number. Leave it blank for a child who has none.',
        'Type the mobile phone number, for example 0712 345 678, and the residence (estate or town).',
        'Type any known allergies in "Known allergies", for example "penicillin". Doctors, nurses and the pharmacist will see this.',
        'Under "Next of kin" type the name, relationship and phone.',
        'Press **Register patient**.',
    ], intro='Use this when a patient comes for the first time. A patient is registered once, and keeps the same outpatient (OP) number for life.',
        tips='The system gives the patient an OP number in the form OP26-00042 (the year, then a serial number). Write it on the patient\'s card or file. It never changes.')
    pic(d, '13-patient-register', 'The registration form', 'fig_m_reg', h=10.5)
    d.p('If something is wrong, the page shows the form again with a message beside each wrong field (see {ref:fig_m_regerr}). If a patient with the same ID number is already registered, the system refuses and shows that patient\'s OP number: open that record instead. '
        'If a patient with the same name and date of birth exists, the page lists the similar patients. If you open one and it is the same person, use that record. If it is a different person, tick "I have checked these records and this is a different person" and press **Register patient** again.')
    pic(d, '14-patient-register-errors', 'The form with messages beside the wrong fields', 'fig_m_regerr', h=10.5)
    task(d, 'R2', 'Find a patient', [
        'Open **Patients > Search patients**.',
        'Type part of the name, the OP number, the ID number or the phone number in the search box, and press **Search**.',
        'Find the patient in the list (25 to a page) and press the name or OP number to open the record.',
    ], tips='Type only part of a name if you are not sure of the spelling. The search treats everything you type as ordinary text.')
    pic(d, '15-patient-record-reception', 'A patient record as the receptionist sees it', 'fig_m_rec', h=10)
    task(d, 'R3', 'Correct a patient\'s details', [
        'Find and open the patient (task R2).',
        'Press **Correct details**.',
        'Change what is wrong and press **Save changes**.',
    ], tips='The OP number cannot be changed. An ID number that belongs to another patient is refused. The system records which fields were changed.')
    task(d, 'R4', 'Book an appointment', [
        'Open **Appointments > Book appointment**, or press **Book appointment** on the patient\'s record.',
        'Choose the patient (search if needed), the doctor and the date, and press **Show free times**.',
        'The page shows the day\'s time slots of 20 minutes, from 08:00 to 16:40. Free slots can be chosen; taken and past slots cannot.',
        'Choose a free slot, add the reason if you know it, and press **Book appointment**.',
        'The diary for that day opens and shows the booking.',
    ], intro='Appointments are made from Monday to Saturday, from today up to 90 days ahead.',
        tips='If someone else has taken the slot a moment before you, the system says "That time is already booked for this doctor. Choose another slot." A doctor can never be booked twice for the same time.')
    pic(d, '17-book-appointment', 'Booking an appointment', 'fig_m_book', h=10)
    task(d, 'R5', 'Look at the diary, cancel or mark an appointment as missed', [
        'Open **Appointments > Appointment diary**.',
        'Choose the date, the doctor and, if you wish, the status, and press **Show**.',
        'To cancel a booking before its time, press **Cancel** on its row and confirm. The slot becomes free.',
        'After the time of an appointment has passed and the patient did not come, press **Mark missed**. (The system also marks overdue bookings as missed by itself.)',
    ])
    pic(d, '16-appointment-diary', 'The appointment diary', 'fig_m_diary', h=9)
    task(d, 'R6', 'Check in a patient who has come', [
        'Open **Appointments > Check-in**.',
        'If the patient has a booking today, it is listed: press **Check in** on that row. For a walk-in, search for the patient and press **Choose**.',
        'Choose the doctor the patient will see (it may be left to "next available"), add the reason, and press **Check in**.',
        'The patient now appears in the nurse\'s triage queue.',
    ], tips='A patient can have only one open visit. If the patient already has one, the system refuses and shows it.')
    pic(d, '18-checkin', 'Checking in a patient', 'fig_m_checkin', h=9)
    pic(d, '19-open-visits', 'All open visits, with the stage of each patient', 'fig_m_visits', h=10)
    task(d, 'R7', 'Register new patients from the paper register or a spreadsheet', [
        'Prepare the CSV file as described in section 8.4.1.',
        'Open **Patients > Import patients**, choose the file and press **Check the file**.',
        'Read the preview. Each row is marked Valid, Duplicate or Error, with a note. Duplicate rows (the same ID number, or the same name and date of birth) will be skipped. Nothing has been saved yet.',
        'Correct the file if there are errors and check it again. When the preview is right, press **Register N patients** (N is the number of valid rows). Only the valid rows are registered.',
    ])

    # ---------------------------------------------------------------- nurse
    d.h4('Nurse')
    task(d, 'N1', 'See who is waiting for triage', [
        'Sign in. The dashboard shows how many patients are waiting.',
        'Open **Clinic > Triage queue**. Patients are listed in the order they arrived, with the waiting time. A red "Allergy" flag shows a patient with a known allergy.',
    ])
    pic(d, '23-triage-queue', 'The triage queue', 'fig_m_tq', h=8)
    task(d, 'N2', 'Record the vital signs', [
        'Press **Triage** on the patient\'s row.',
        'Type the temperature, pulse, blood pressure (upper and lower value), breathing rate, oxygen saturation, weight and height. You may leave a measurement out, but give at least one measurement or a note.',
        'Type any note, then press **Save triage**.',
        'The patient moves to the doctor\'s queue.',
    ], tips='The system refuses values that cannot be right, for example a temperature of 60 or a lower blood pressure above the upper one. It shows a message beside the field; correct it and save again. To correct a saved record, open it again and press **Save corrections**.')
    pic(d, '25-triage-form', 'The triage form', 'fig_m_triage', h=10)
    pic(d, '24-triage-errors', 'Messages beside impossible values', 'fig_m_trierr', h=10)
    task(d, 'N3', 'Record a procedure', [
        'On the triage page, find the box "Record a procedure".',
        'Choose the service (for example Wound dressing) and the quantity (1 to 20), and press **Add**.',
    ], tips='The procedure goes onto the patient\'s invoice automatically. Do not tell the cashier separately.')

    # ---------------------------------------------------------------- doctor
    d.h4('Doctor and clinical officer')
    task(d, 'D1', 'See your waiting patients', [
        'Sign in. The dashboard shows the patients waiting for you and the test results ready.',
        'Open **Clinic > Consultation queue**. You see the patients assigned to you and those not yet assigned.',
        'Press **Open consultation** on a patient.',
    ])
    pic(d, '27-consult-queue', 'The consultation queue', 'fig_m_cq', h=8)
    task(d, 'D2', 'Read the history and record the consultation', [
        'At the top of the consultation page read the triage, the allergy warning and the earlier visits (diagnoses, tests and medicines, newest first).',
        'Under the consultation form choose the consultation type, and type the complaint, the examination findings and the diagnosis in words.',
        'If you wish, choose an ICD-10 code from the list. If you type a code that is not in the hospital list, the system refuses it; leave it blank or pick one from the list.',
        'Type the plan and press **Save consultation**.',
    ], tips='The consultation fee is added to the invoice when you save. Only you can change a consultation you recorded; press **Save corrections** to change it.')
    pic(d, '28-consult-top', 'The consultation page: triage, allergies, history and the consultation form', 'fig_m_consult', h=11)
    task(d, 'D3', 'Request laboratory tests', [
        'After saving the consultation, find "Request tests" on the same page.',
        'Tick one or more tests and, if you wish, type why the test is needed.',
        'Press **Send to laboratory**. The test appears at once on the laboratory\'s worklist, and its price is added to the invoice.',
        'To withdraw a request that the laboratory has not started, press **Cancel** on its row.',
    ], tips='A test that is still waiting for the same visit cannot be requested twice.')
    task(d, 'D4', 'Prescribe medicines', [
        'On the consultation page find "Prescription".',
        'For each medicine choose it from the list and type the dose (for example 1 tablet), how often (three times a day), for how long (5 days) and the total quantity.',
        'Press **Add** for more lines, then **Send to pharmacy**.',
    ], tips='If the patient has a recorded allergy that matches the name of a medicine, the system shows a reminder. The system does not check doses: the pharmacist gives exactly what you write.')
    pic(d, '29-consult-orders', 'Requesting tests and prescribing on the consultation page', 'fig_m_orders', h=11)
    task(d, 'D5', 'Read the results of your tests', [
        'Open **Laboratory > My test results**, or look at the consultation page of the patient.',
        'Results appear when the laboratory has entered them. Results outside the usual range are marked.',
    ], tips='You see the results of the tests you requested. Another doctor does not see them there, but sees the diagnoses in the patient\'s history.')
    pic(d, '31-my-test-results', 'The results of the tests a doctor requested', 'fig_m_myres', h=9)
    task(d, 'D6', 'Close the visit', [
        'When you have finished with the patient, press **Close visit** at the bottom of the consultation page and confirm.',
    ], tips='A visit can be closed only after a consultation has been recorded. After closing, the record is locked.')

    # ---------------------------------------------------------------- laboratory
    d.h4('Laboratory technologist')
    task(d, 'L1', 'See the worklist', [
        'Open **Laboratory > Laboratory worklist**. Tests waiting and in progress are listed, oldest first, with the patient, the test, the doctor who asked and the clinical notes.',
        'Press **Completed** to see the finished tests.',
    ])
    pic(d, '34-lab-worklist', 'The laboratory worklist', 'fig_m_lab', h=8)
    task(d, 'L2', 'Enter a result', [
        'Press **Start** on the test when you begin. Its status changes to In progress.',
        'Press **Enter result**. The page shows the patient, the test and the usual range.',
        'Type the result (with its unit, for example Hb 11.2 g/dL), choose the flag (within the range, outside the range, or not stated), and press **Save result**.',
        'The result is sent to the doctor who requested the test.',
    ], tips='A result cannot be empty, and cannot be entered for a request the doctor has cancelled. If you made a mistake, open the result again and correct it; the correction is recorded.')
    pic(d, '35-lab-result-form', 'Entering a result', 'fig_m_labres', h=9)

    # ---------------------------------------------------------------- pharmacy
    d.h4('Pharmacist')
    task(d, 'P1', 'Dispense a prescription', [
        'Open **Pharmacy > Prescriptions**. Prescriptions waiting are listed, oldest first; a red flag shows an allergy.',
        'Press **Dispense** on a prescription.',
        'For each line read what is still to be given and the stock available. Type the quantity you are giving (it may be less than the prescribed quantity).',
        'Press **Confirm and update stock**.',
        'The page shows what was given and from which batch. The stock is reduced and the medicines are added to the invoice.',
    ], tips='Stock is taken from the batch that expires first, and never from expired stock. If you ask for more than is in stock or more than remains on the prescription, nothing is given and the page tells you which line is wrong. If you give only part, the rest stays in the queue.')
    pic(d, '37-pharmacy-queue', 'The prescription queue', 'fig_m_pq', h=8)
    pic(d, '38-dispense', 'Dispensing a prescription', 'fig_m_disp', h=9)
    task(d, 'P2', 'Receive new stock', [
        'Open **Pharmacy > Receive stock**.',
        'Choose the medicine (add it first to the Medicine list if it is not there).',
        'Type the batch number printed on the pack, the expiry date and the quantity received.',
        'Press **Receive stock**.',
    ], tips='Stock that has already expired cannot be received. Receiving more of an existing batch adds to it only if the expiry date is the same.')
    pic(d, '41-receive-stock', 'Receiving stock', 'fig_m_recv', h=8)
    task(d, 'P3', 'Look at stock, batches and alerts', [
        'Open **Pharmacy > Medicine stock** to see the usable stock of each medicine and its status: OK, Low (at or below the reorder level) or Out.',
        'Open **Pharmacy > Batches** to see each batch with its expiry state (Expiring soon within 90 days, or Expired) and the ledger of every movement.',
        'The dashboard also lists medicines to reorder and batches to remove from the shelf.',
    ])
    pic(d, '39-stock', 'Medicine stock with status and nearest expiry', 'fig_m_stock', h=9)
    pic(d, '40-batches', 'Batches and their expiry state', 'fig_m_batches', h=9)
    task(d, 'P4', 'Adjust stock', [
        'Open **Pharmacy > Batches** and find the batch.',
        'In the Adjust column choose **Remove** (for a loss) or **Add**, type the quantity, choose the reason from the list, and press **Save**.',
    ], tips='A reason is required, and the stock cannot go below zero. The change is recorded in the ledger with your name.')
    task(d, 'P5', 'Keep the medicine list', [
        'Open **Pharmacy > Medicine list**.',
        'Press **Add medicine** to add a new one, or **Edit** to change a price, the reorder level or whether it can be prescribed.',
        'Fill in the name, form, strength, unit, price per unit and reorder level, and press **Save**.',
    ])
    pic(d, '42-medicine-list', 'The medicine list', 'fig_m_medlist', h=9)

    # ---------------------------------------------------------------- cashier
    d.h4('Cashier and billing clerk')
    task(d, 'C1', 'Find an invoice', [
        'Open **Billing > Invoices**. Invoices that still have a balance are listed first, with today\'s collections at the top.',
        'To search, type the invoice number, OP number or name, choose the status or dates, and press **Show**. Untick "Only invoices with a balance" to see paid invoices.',
        'Press the invoice number to open it.',
    ], tips='You cannot change the services or prices on an invoice. They come from what the doctors, nurses, laboratory and pharmacy recorded.')
    pic(d, '45-invoice-list', 'The invoice list with today\'s collections', 'fig_m_inv', h=9)
    task(d, 'C2', 'Take a payment', [
        'Open the invoice and look at the services, the total and the balance.',
        'Under "Take a payment" choose the payment method: Cash, M-Pesa, Card or Insurance.',
        'Type the amount. It is filled with the balance; change it for a part-payment. It cannot be more than the balance.',
        'For M-Pesa type the 10-character confirmation code from the message (for example QJK4H2M9XP). For Card type the approval code from the slip. For Insurance type the insurer\'s claim or authorisation number. For Cash leave the reference blank.',
        'Press **Record payment**. The receipt opens.',
    ], tips='An M-Pesa code can be used only once. After a part-payment the invoice shows Partially paid and the balance. When the balance is zero it shows Paid and accepts no more payments.')
    pic(d, '47-invoice-partial', 'An invoice with a part-payment and the payment form', 'fig_m_pay', h=11)
    pic(d, '46-payment-error', 'A payment refused because the M-Pesa code is not valid', 'fig_m_payerr', h=10)
    task(d, 'C3', 'Print an invoice or a receipt', [
        'On the invoice press **Print invoice**, or on the receipt press **Print receipt**.',
        'Choose the printer in the browser\'s print window and press Print. The printed page has no menu.',
    ])
    pic(d, '48-receipt', 'A receipt', 'fig_m_rcpt', crop=False, w=10.5, h=9)
    task(d, 'C4', 'Check your collections', [
        'Open **Billing > Invoices**. The box "My collections today" shows what you took by method (Cash, M-Pesa, Card, Insurance) and the total. Use it to balance at the end of the day.',
    ])

    # ---------------------------------------------------------------- manager
    d.h4('Hospital manager')
    task(d, 'M1', 'Read a report', [
        'Open **Reports** and choose Attendance, Revenue, Stock or Laboratory workload.',
        'Choose the From and To dates (the report opens for this month) and press **Show report**.',
        'Read the tiles at the top and the tables below. Press **Print** to print the page, or **Download CSV** to get a spreadsheet file.',
    ], tips='The Attendance report shows appointments attended, missed and cancelled, the attendance rate, visits, walk-ins and new patients. The Revenue report separates the amount billed from the amount collected, by day, payment method and type of service. The Stock report shows stock levels, value, movements and expiry alerts. The Laboratory report shows tests requested, completed and pending, and how long results take.')
    pic(d, '50-manager-dashboard', 'The manager\'s dashboard', 'fig_m_mgr', crop=False, w=14.5, h=10)
    pic(d, '52-report-revenue', 'The revenue report', 'fig_m_rev', h=10)
    pic(d, '51-report-attendance', 'The attendance report', 'fig_m_att', h=10)
    task(d, 'M2', 'Search the audit trail', [
        'Open **Administration > Audit trail**.',
        'Choose the person, the action or the type of record, and the dates, and press **Filter**.',
        'Read the entries: date and time, person, action, record, short details and the computer address.',
    ], tips='The audit trail never shows patient names, results or payment codes. Opening the audit trail is itself recorded.')
    pic(d, '08-admin-audit', 'The audit trail', 'fig_m_audit', h=10)

    # ---------------------------------------------------------------- administrator
    d.h4('System administrator')
    task(d, 'A1', 'Create an account', [
        'Open **Administration > User accounts** and press **Add user**.',
        'Type the full name and a username (3 to 40 small letters, numbers, dots, dashes or underscores), choose the role, and type the phone number if wished.',
        'Press **Create account**. The page shows a one-time password **once**. Write it down and give it to the person; it is not shown again.',
    ], tips='The person must change the one-time password at the first sign-in. Give each person one account and one role. Never create shared accounts.')
    pic(d, '06-admin-users', 'The list of user accounts', 'fig_m_users', h=10)
    pic(d, '07-admin-user-form', 'Editing an account', 'fig_m_user', h=9)
    task(d, 'A2', 'Unlock, reset or deactivate an account', [
        'On the user list press **Unlock** next to a locked account.',
        'To give a new one-time password to someone who forgot theirs, press **Edit** and then **Reset password**. The old password stops working at once.',
        'When a person leaves, press **Deactivate** and confirm. The person cannot sign in, and any open session ends immediately. Press **Activate** to restore the account.',
    ], tips='The system will not let you deactivate your own account or the last active administrator.')
    task(d, 'A3', 'Change a price', [
        'Open **Administration > Price list**.',
        'Type the new price in the box on the row of the service or test (tick or untick "In use" if it should be offered or not) and press **Save** on that row. The old and the new price are recorded.',
    ], tips='A price change applies to new charges. An invoice on which a payment has been taken keeps its agreed prices.')
    pic(d, '09-admin-prices', 'The price list', 'fig_m_prices', h=9)
    task(d, 'A4', 'Check system health', [
        'Open **Administration > System health**.',
        'Read each check. OK is good, Warning needs attention soon, Failed needs action now. Look especially at "Latest backup", "Data integrity" and "Demonstration accounts".',
    ])
    pic(d, '10-admin-health', 'The system health page', 'fig_m_health', h=10)
