"""Chapter 4, part 2: use cases, user stories, traceability (4.3.3 to 4.3.5)."""
import re

from doc_common import fig
from req_data import FEATURES, MODULES, OBJECTIVES
from usecases_data import MODULES as UC_MODULES, USE_CASES
from trace_lib import expand, load_results
from nfr_data import PERFORMANCE, SAFETY, SECURITY, QUALITY

UC_KEY = {1: 'uc_m1', 2: 'uc_m2', 3: 'uc_m3', 4: 'uc_m4', 5: 'uc_m5', 6: 'uc_m6'}

MODULE_TEXT = {
    1: ('The receptionist is the main actor of Module 1. Doctors and nurses appear on the right because they read the diary and search for patients but do not change them. '
        'Two use cases include a smaller one: registering a patient always checks for duplicates, and booking an appointment always checks that the slot is free. '
        'The dashed arrows labelled include show this, and the included steps cannot be skipped.'),
    2: ('Module 2 follows the patient from the desk to the doctor. The receptionist checks the patient in, the nurse records the triage, and the doctor records the consultation and closes the visit. '
        'Recording a consultation includes showing earlier visits and allergies, so the doctor always sees the history first. Receptionists and nurses can also see the queues, but only the doctor can read the full history.'),
    3: ('In Module 3 the doctor asks and the technologist answers. A request always adds the test price to the invoice (an included use case), and the result goes back only to the doctor who made the request. '
        'The doctor may cancel a request before the laboratory starts it.'),
    4: ('Module 4 has three kinds of user. The doctor prescribes and may look at stock but not change it. The pharmacist dispenses and keeps the stock. The manager looks at availability and alerts. '
        'Dispensing always deducts stock and adds the medicines to the invoice in one step, shown as an included use case.'),
    5: ('Module 5 is where money is handled. The cashier sees invoices and takes payments, and every payment includes checks of the reference and the balance. '
        'The manager reads the four reports and can download them; the pharmacist reads the stock report. The cashier cannot read reports and the manager cannot read individual invoices.'),
    6: ('Module 6 is the foundation. Every staff member signs in, changes their own password and signs out. Only the administrator manages accounts, resets passwords, keeps prices and checks system health. '
        'The administrator and the manager can both read the audit trail, which records the actions of everyone, including their own.'),
}


def build_use_cases(d):
    d.h3('4.3.3 Use Cases')
    d.p('A use case describes one goal that a user achieves with the system (Dennis et al., 2015). The system has 45 use cases, numbered UC-01 to UC-45, shown in six diagrams, one for each module. '
        'In each diagram the large rectangle is the system, the ovals are use cases, the stick figures are actors (the staff roles), a plain line joins an actor to a use case the actor can perform, '
        'and a dashed arrow marked "include" joins a use case to a smaller one that it always uses. The eight actors are the eight roles of the system; "Any staff member" means every role.')
    for m in range(1, 7):
        key = UC_KEY[m]
        d.figure(fig(key), f'Use case diagram for Module {m}: {MODULES[m].lower()}', label=f'fig_{key}')
        d.p(f'{{ref:fig_{key}}} shows the use cases of Module {m}. ' + MODULE_TEXT[m], keep_next=False)
        rows = []
        for uc in USE_CASES[key]:
            if uc[0]:
                rows.append([uc[0], uc[1], ', '.join(uc[2]), uc[3], ', '.join(str(o) for o in uc[4])])
        d.table(['ID', 'Use case', 'Actors', 'What the actor achieves', 'Obj.'], rows, widths=[1.3, 3.2, 3.2, 6.8, 1.0], size=8.5,
                caption=f'Use Cases of Module {m}: {MODULES[m]}', label=f'tab_uc{m}', align=['l', 'l', 'l', 'l', 'c'])

    d.h4('4.3.3.1 Detailed Use Case Specifications')
    d.p('The eight use cases that carry the most risk or the most value are specified in full below. They cover every module and show the main flow, the alternative flows that handle errors, and the rules that apply. '
        'The other use cases are described by their requirements in section 4.3.2.')
    for spec in DETAIL:
        rows = [
            ['Use case', f'{spec["id"]}: {spec["name"]}'],
            ['Actors', spec['actors']],
            ['Objective and priority', f'Objective {spec["obj"]}; {spec["priority"]} priority'],
            ['Trigger', spec['trigger']],
            ['Preconditions', spec['pre']],
            ['Main flow', '\n'.join(f'{i}. {s}' for i, s in enumerate(spec['main'], 1))],
            ['Alternative flows', '\n'.join(spec['alt'])],
            ['Postconditions', spec['post']],
            ['Business rules', spec['rules']],
            ['Requirements', spec['reqs']],
        ]
        d.table(['Item', 'Description'], rows, widths=[3.0, 12.3], size=9, caption=f'Specification of {spec["id"]} {spec["name"]}',
                label='tab_spec_' + spec['id'].replace('-', '').lower(), align=['l', 'l'])


DETAIL = [
    dict(id='UC-01', name='Sign in', actors='Any staff member', obj=5, priority='High',
         trigger='The user opens the sign-in page of UB-HMS.',
         pre='The user has an active account. The user knows the username and password.',
         main=['The user types the username and password and presses Sign in.',
               'The system checks the security token of the form and checks that the computer has not made 20 failed attempts in the last 15 minutes.',
               'The system finds the account and compares the password with the stored hash.',
               'The system checks that the account is not locked and is active.',
               'The system sets the failed-attempt count to zero, records the time of sign-in, issues a new session and a new security token, and writes an audit entry.',
               'The system opens the dashboard for the user\'s role, or the change-password page if the user must choose a new password.'],
         alt=['A1. Wrong password or unknown username: the system shows one message ("The username or password is not correct") for both, counts the failure, and locks the account for 15 minutes at the fifth failure.',
              'A2. Account locked: the system says it is temporarily locked, even if the password is right.',
              'A3. Account deactivated: the system says it has been deactivated.',
              'A4. Computer blocked: the system says there have been too many attempts.',
              'A5. Security token missing or wrong: the system refuses with a 419 page.'],
         post='The user is signed in with a new session, or nothing has changed. Every attempt is in the audit trail.',
         rules='Passwords are never shown or stored in plain form. The session ends after 30 minutes without activity or 8 hours in total.',
         reqs='REQ-063 to REQ-068, REQ-072'),
    dict(id='UC-09', name='Register patient', actors='Receptionist', obj=1, priority='High',
         trigger='A new patient comes to the reception desk.',
         pre='The receptionist is signed in and holds the permission to register patients.',
         main=['The receptionist opens Register patient and enters the patient\'s names, sex, date of birth and, where available, ID number, phone, residence, allergies and next of kin.',
               'The receptionist presses Register patient.',
               'The system checks every field.',
               'The system looks for another patient with the same ID number, and for the same name and date of birth.',
               'In one transaction the system adds 1 to this year\'s counter, builds the number OPyy-nnnnn, saves the patient and writes an audit entry.',
               'The system shows the patient\'s record with the new OP number.'],
         alt=['A1. A field is wrong: the system keeps what was typed and shows a message beside each wrong field.',
              'A2. The ID number is already registered: the system refuses and shows the existing OP number.',
              'A3. A patient with the same name and date of birth exists: the system lists them and saves only after the receptionist ticks the confirmation.'],
         post='A new patient exists with a unique OP number, and the audit trail shows who registered the patient.',
         rules='An OP number is never changed or reused, and a failed registration does not use up a number. The audit entry holds no health details.',
         reqs='REQ-001 to REQ-006'),
    dict(id='UC-13', name='Book appointment', actors='Receptionist', obj=1, priority='High',
         trigger='A patient asks for an appointment with a doctor.',
         pre='The patient is registered. The doctor\'s account is active.',
         main=['The receptionist chooses the patient (by search), the doctor and the date.',
               'The system shows the 27 slots of the day, each free, taken or past.',
               'The receptionist chooses a free slot, enters the reason if known, and presses Book.',
               'The system checks that the date is a working day, today or later and within 90 days, and that the time is on the 20-minute grid and has not passed.',
               'The system begins a transaction, locks the doctor\'s row, and checks that neither the doctor nor the patient has a booking at that time.',
               'The system saves the appointment as Booked, writes an audit entry and commits.',
               'The system shows the diary for that day.'],
         alt=['A1. A check fails: the system shows the reason beside the field and saves nothing.',
              'A2. Someone took the slot a moment earlier: the system rolls back and asks for another slot. If two bookings arrive at the same instant, the database\'s unique slot key lets only one succeed.'],
         post='The appointment is Booked and its slot is held. No other booking can take it.',
         rules='A slot is held while the appointment is Booked or Attended and is released when it is Cancelled or Missed.',
         reqs='REQ-012 to REQ-015'),
    dict(id='UC-20', name='Record consultation', actors='Doctor', obj=2, priority='High',
         trigger='The doctor calls the next patient from the queue.',
         pre='The patient has an open visit. The doctor holds the permission to record consultations.',
         main=['The doctor opens the patient from the doctor\'s queue.',
               'The system shows the triage, the allergy warning and the earlier visits.',
               'The doctor enters the complaint, the examination, the diagnosis (with an ICD-10 code if wanted), the plan and the type of consultation.',
               'The doctor presses Save.',
               'The system validates the form, saves the consultation, records the doctor, moves the visit to With doctor and adds the consultation fee to the invoice (creating the invoice if this is the first charge).',
               'The system writes an audit entry and shows the consultation page, from which the doctor can request tests, prescribe and record procedures.'],
         alt=['A1. Complaint, diagnosis or type is missing, or the ICD-10 code is not on the hospital list: the system shows a message beside the field.',
              'A2. The consultation was recorded by another doctor: the system refuses the change.',
              'A3. The visit is closed: the record is locked and cannot be changed.'],
         post='The visit holds the consultation. The invoice contains the consultation fee.',
         rules='Only the doctor who recorded a consultation can change it. A visit can be closed only after a consultation.',
         reqs='REQ-021 to REQ-024'),
    dict(id='UC-23', name='Request laboratory tests', actors='Doctor', obj=2, priority='High',
         trigger='The doctor decides that tests are needed.',
         pre='The visit is open and has a consultation.',
         main=['On the consultation page the doctor ticks one or more tests and may add a reason.',
               'The doctor presses Send to laboratory.',
               'The system checks that the visit is open, has a consultation, and has no pending request for the same test.',
               'In one transaction the system saves one request for each test, tied to the visit and to the requesting doctor, and adds each test price to the invoice.',
               'The system shows the requests as Requested. They appear at once on the laboratory worklist.'],
         alt=['A1. The same test is already pending: the system refuses the whole request and names the test.',
              'A2. A test in the list is unknown: the system saves nothing.',
              'A3. The doctor cancels a request before the laboratory starts it: the request becomes Cancelled and its charge is removed from the invoice.'],
         post='The laboratory can see the request. The invoice includes the tests.',
         rules='A result will return only to the doctor who made the request.',
         reqs='REQ-026 to REQ-028'),
    dict(id='UC-32', name='Dispense a prescription', actors='Pharmacist', obj=3, priority='High',
         trigger='A patient brings the prescription to the pharmacy, or the pharmacist takes the next one from the queue.',
         pre='The prescription is Pending or Partially dispensed. The pharmacist holds the permission to dispense.',
         main=['The pharmacist opens the prescription from the queue.',
               'The system shows each line with the amount still to give and the usable stock, and any allergy reminder.',
               'The pharmacist enters the quantity to give for each line and presses Dispense.',
               'The system begins a transaction and locks the prescription lines.',
               'For each line the system checks that the quantity does not exceed what remains, lists the unexpired batches with the earliest expiry first, and takes the quantity from them in turn, writing a negative entry in the stock ledger for each batch used.',
               'The system sets the prescription to Dispensed or Partially dispensed, adds the medicines given to the invoice, writes an audit entry and commits.',
               'The system shows what was given and from which batch.'],
         alt=['A1. A quantity is larger than what remains, or there is not enough unexpired stock: the system rolls back all lines, deducts nothing and shows which line failed and why.',
              'A2. No quantity is entered: the system asks for at least one.'],
         post='Stock, ledger, prescription and invoice all agree.',
         rules='Expired stock is never given. A batch balance can never go below zero, even if the application is bypassed.',
         reqs='REQ-041 to REQ-045'),
    dict(id='UC-38', name='Take a payment', actors='Cashier', obj=4, priority='High',
         trigger='A patient comes to pay an invoice.',
         pre='The invoice has a balance. The cashier holds the permission to take payments.',
         main=['The cashier opens the invoice and chooses the payment method.',
               'The cashier enters the amount and, for M-Pesa, card or insurance, the reference.',
               'The cashier presses Record payment.',
               'The system begins a transaction and locks the invoice, so that two cashiers paying it at once are handled one after the other.',
               'The system checks the amount (above zero and not above the balance) and the reference for the method.',
               'The system saves the payment, adds it to the amount paid, sets the status (Partially paid or Paid), writes an audit entry with the amount and method, and commits.',
               'The system shows the receipt RCT-yyyy-nnnnnn.'],
         alt=['A1. The amount is too high or not valid: the system shows the reason beside the field and saves nothing.',
              'A2. The M-Pesa code does not have 10 letters or digits, or was used for an earlier payment: the system refuses it.',
              'A3. The invoice is already paid: the system shows no payment form.'],
         post='The invoice shows the new balance. The receipt can be printed.',
         rules='Money is held to the cent. The reference is not copied into the audit trail.',
         reqs='REQ-050 to REQ-055'),
    dict(id='UC-06', name='View the audit trail', actors='System administrator, Hospital manager', obj=5, priority='High',
         trigger='The administrator or the manager wants to know who did something.',
         pre='The user holds the permission to view the audit trail.',
         main=['The user opens Audit trail.',
               'The user may choose a person, an action, a type of record and a date range, and presses Filter.',
               'The system writes an audit entry that the trail was opened.',
               'The system shows the matching entries, newest first, 25 to a page, each with date and time, person, action, record, short details and computer address.'],
         alt=['A1. The user does not hold the permission: the system shows the 403 page and records the attempt.',
              'A2. A filter holds odd text, for example SQL: the system treats it as plain text and finds nothing.'],
         post='Nothing in the trail has changed except the new entry about the viewing.',
         rules='Entries cannot be changed or deleted. They hold identifiers and short descriptions only, never names, results or reference codes.',
         reqs='REQ-074, REQ-076 to REQ-079'),
]


STORIES = [
    # id, role, want, so that, objective, priority, sprint, requirements
    ('US-01', 'receptionist', 'register a new patient once and get an outpatient number', 'every department finds the same record', '1', 'High', '1', 'REQ-001, REQ-002'),
    ('US-02', 'receptionist', 'be warned when a patient may already be registered', 'no patient ends up with two files', '1', 'High', '1', 'REQ-004, REQ-005'),
    ('US-03', 'receptionist', 'find a patient by name, outpatient number, ID number or phone', 'a record is found in seconds, not by searching files', '1', 'High', '1', 'REQ-007, REQ-008'),
    ('US-04', 'receptionist', 'correct a spelling mistake in a patient\'s details', 'the record is right, while the outpatient number stays the same', '1', 'Medium', '1', 'REQ-009'),
    ('US-05', 'records clerk', 'import patients from a spreadsheet after checking a preview', 'I do not have to type the old records again', '1', 'Low', '1', 'REQ-011'),
    ('US-06', 'receptionist', 'book a patient with a doctor in a free slot', 'the doctor is never booked twice for the same time', '1', 'High', '2', 'REQ-012, REQ-013'),
    ('US-07', 'receptionist', 'see a doctor\'s day with the free and taken slots', 'I can offer the patient a time at once', '1', 'High', '2', 'REQ-014'),
    ('US-08', 'receptionist', 'cancel an appointment, or mark it as missed', 'the slot is released and attendance figures are true', '1', 'Medium', '2', 'REQ-015'),
    ('US-09', 'receptionist', 'check a patient in from the booking, or as a walk-in', 'the nurse and doctor can see the patient waiting', '2', 'High', '2', 'REQ-016, REQ-017'),
    ('US-10', 'nurse', 'see who is waiting for triage, with allergy flags', 'I call patients in the order they came and know the risks', '2', 'High', '2', 'REQ-018, REQ-010'),
    ('US-11', 'nurse', 'record vital signs and be stopped when a value is impossible', 'a typing slip is not stored as a measurement', '2', 'High', '2', 'REQ-019, REQ-020'),
    ('US-12', 'nurse', 'record a wound dressing or other procedure', 'it appears on the invoice and is not forgotten', '2, 4', 'Medium', '2', 'REQ-025'),
    ('US-13', 'doctor', 'see my waiting patients, and each patient\'s triage and history on one page', 'I treat the patient with the full picture', '2', 'High', '2', 'REQ-018, REQ-022'),
    ('US-14', 'doctor', 'record the complaint, diagnosis (with an ICD-10 code) and plan', 'the visit is recorded once and correctly', '2', 'High', '2', 'REQ-021'),
    ('US-15', 'doctor', 'close a visit when I have finished', 'the record is locked and cannot be changed by mistake', '2', 'High', '2', 'REQ-023, REQ-024'),
    ('US-16', 'doctor', 'request laboratory tests from the consultation page', 'the patient does not have to carry a paper request', '2', 'High', '3', 'REQ-026, REQ-027, REQ-028'),
    ('US-17', 'doctor', 'see the results of the tests I asked for as soon as they are ready', 'I can decide on treatment without waiting for a paper', '2', 'High', '3', 'REQ-031'),
    ('US-18', 'laboratory technologist', 'see a worklist of the tests waiting, oldest first', 'I work in the right order and know who asked', '2', 'High', '3', 'REQ-029'),
    ('US-19', 'laboratory technologist', 'enter a result and flag it when it is outside the range', 'the doctor sees it, linked to the right test', '2', 'High', '3', 'REQ-030, REQ-032'),
    ('US-20', 'doctor', 'prescribe medicines with the dose, frequency and duration, and be reminded of allergies', 'the pharmacy receives a complete and safe prescription', '2, 3', 'High', '3', 'REQ-039, REQ-040'),
    ('US-21', 'pharmacist', 'see the prescriptions waiting', 'I serve patients in the order they came', '3', 'High', '3', 'REQ-041'),
    ('US-22', 'pharmacist', 'dispense from the batch that expires first and never from expired stock', 'old stock is used first and nobody receives an expired medicine', '3', 'High', '3', 'REQ-042, REQ-043, REQ-044'),
    ('US-23', 'pharmacist', 'receive a delivery as a batch with its expiry date', 'the stock figures and expiry dates are exact', '3', 'High', '3', 'REQ-034, REQ-038'),
    ('US-24', 'pharmacist', 'see medicines that are low or out and batches close to expiry', 'I reorder in time and remove expired medicines', '3', 'High', '3', 'REQ-035, REQ-036'),
    ('US-25', 'pharmacist', 'adjust stock and give the reason', 'damage and counting differences are corrected openly', '3', 'Medium', '3', 'REQ-037'),
    ('US-26', 'cashier', 'find the invoice already listing every service the patient received', 'no charge is missed (the example in the proposal)', '4', 'High', '4', 'REQ-046, REQ-047, REQ-048'),
    ('US-27', 'cashier', 'take full or part payment by cash, M-Pesa, card or insurance', 'what the patient paid is recorded exactly', '4', 'High', '4', 'REQ-050, REQ-051, REQ-054'),
    ('US-28', 'cashier', 'print a numbered receipt', 'the patient has proof of payment', '4', 'Medium', '4', 'REQ-052'),
    ('US-29', 'cashier', 'see today\'s collections by payment method', 'I can balance my cash and mobile money at the end of the day', '4', 'Medium', '4', 'REQ-053'),
    ('US-30', 'hospital manager', 'see an attendance report for any period', 'I know how many patients came, missed or were new', '4', 'High', '4', 'REQ-056'),
    ('US-31', 'hospital manager', 'see how much was billed and collected, by day, method and service', 'I know the hospital\'s income and what is still unpaid', '4', 'High', '4', 'REQ-057'),
    ('US-32', 'hospital manager', 'see stock levels, stock value and expiry alerts', 'I can plan purchasing and avoid losses', '3, 4', 'High', '4', 'REQ-058'),
    ('US-33', 'hospital manager', 'see the laboratory\'s workload and how long results take', 'I can judge whether the laboratory needs help', '4', 'Medium', '4', 'REQ-059'),
    ('US-34', 'hospital manager', 'download any report as a spreadsheet file', 'I can keep it, chart it or send it to the board', '4', 'Medium', '4', 'REQ-060, REQ-061, REQ-062'),
    ('US-35', 'staff member', 'sign in with my own username and password', 'my actions are recorded under my name and nobody can use my account unnoticed', '5', 'High', '1', 'REQ-063, REQ-064, REQ-065, REQ-066, REQ-067'),
    ('US-36', 'staff member', 'see and open only what my role allows', 'patient information is protected from people who do not need it', '5', 'High', '1', 'REQ-072, REQ-073, REQ-075'),
    ('US-37', 'administrator', 'create an account with a role and a one-time password', 'a new member of staff can start work safely', '5', 'High', '1', 'REQ-069'),
    ('US-38', 'administrator', 'deactivate an account at once when a person leaves', 'a former employee cannot get in', '5', 'High', '1', 'REQ-070, REQ-071'),
    ('US-39', 'manager or administrator', 'search the audit trail', 'misuse can be found and nobody can deny what they did', '5', 'High', '1', 'REQ-074, REQ-076, REQ-077, REQ-078'),
    ('US-40', 'administrator', 'change prices, check system health and be sure the backup is recent', 'invoices are right and the hospital does not lose its records', '4, 5', 'Medium', '5', 'REQ-080, REQ-081, REQ-083'),
]


def build_stories(d):
    d.h3('4.3.4 User Stories')
    d.p('The proposal (section 3.2.4) turns each need found in the research into a user story of the form "As a cashier, I want the invoice to list all services automatically so that no charge is missed." '
        'The stories below are the product backlog of the project: they are listed in the order of the modules, and the last columns give the sprint in which each was built and the requirements that carry it out. '
        'Because the interviews were not completed, the stories restate the needs behind the proposal\'s five problems and need to be confirmed with staff at user acceptance testing.')
    rows = [[s[0], f'As a {s[1]}, I want to {s[2]} so that {s[3]}.', s[4], s[5], s[6], s[7]] for s in STORIES]
    d.table(['ID', 'User story', 'Obj.', 'Priority', 'Sprint', 'Requirements'], rows, widths=[1.2, 7.4, 0.9, 1.3, 1.1, 3.4], size=8.5,
            caption='User Stories (Product Backlog)', label='tab_stories', align=['l', 'l', 'c', 'c', 'c', 'l'])
    d.p('Priority follows the same meaning as for the requirements. Sprint 1 built sign-in, accounts and patient registration; Sprint 2 appointments, triage and consultation; Sprint 3 the laboratory and pharmacy; Sprint 4 invoices, payments and reports; and Sprint 5 joined the modules and tested security (Waweru, 2026; Schwaber & Sutherland, 2020).')


def build_traceability(d):
    d.h3('4.3.5 Traceability to the Objectives')
    d.p('Every requirement must serve at least one of the five specific objectives of the proposal, and every objective must be served by requirements that can be tested. '
        '{ref:tab_trace} shows this for the whole system. The counts are taken from the requirement tables in section 4.3.2 and from the test results of Chapter 7.')
    known = load_results()
    rows = []
    totals = [0, 0, 0, 0]
    for o in range(1, 6):
        feats = [f for f in FEATURES if o in f['objectives']]
        reqs = [r for f in feats for r in f['reqs']]
        ucs = sorted({u[0] for lst in USE_CASES.values() for u in lst if u[0] and o in u[4]})
        stories = [s for s in STORIES if str(o) in [x.strip() for x in s[4].split(',')]]
        tests = set()
        for r in reqs:
            for t in expand(r[4], known):
                if t in known:
                    tests.add(t)
        rows.append([str(o), ', '.join(f['key'] for f in feats), str(len(reqs)), f'{len(ucs)}', str(len(stories)), str(len(tests))])
    d.table(['Objective', 'Features', 'Requirements', 'Use cases', 'User stories', 'Automated tests'], rows, widths=[2.0, 5.4, 2.2, 1.9, 1.9, 1.9], caption='Objectives Compared with Requirements, Use Cases, Stories and Tests', label='tab_trace', align=['c', 'l', 'c', 'c', 'c', 'c'])
    d.p('The objectives are the ones in section 1.4.2 of the proposal:')
    d.table(['No.', 'Specific objective'], [[str(o), OBJECTIVES[o]] for o in range(1, 6)], widths=[1.2, 14.1], caption='The Five Specific Objectives', label='tab_objectives', align=['c', 'l'])
    d.p('A feature or use case may serve two objectives, for example procedures recorded by a nurse support both the clinical objective (2) and the billing objective (4), so the counts in {ref:tab_trace} add up to more than the totals. '
        'The main objective, to improve the speed, accuracy and security of information management, is served by all five. '
        'The full matrix that links each requirement to its tests and to their results is in Chapter 7.')
