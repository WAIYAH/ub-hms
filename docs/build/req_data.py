"""The functional requirements of UB-HMS, shared by Chapter 4 (requirements) and Chapter 7 (traceability matrix).

Every requirement is written as a sentence a tester can check, and lists the use case it belongs to and the
automated tests that prove it. Test ids are those printed by tests/run_tests.php (UT-...) and tests/e2e.sh (E2E-...).
Objectives (proposal section 1.4.2): 1 registration and appointments; 2 clinical and laboratory;
3 pharmacy and inventory; 4 billing and reporting; 5 security (login, roles, audit trail).
"""

OBJECTIVES = {
    1: 'To develop a patient registration and appointment module that assigns a unique outpatient number and books appointments without clashes.',
    2: 'To develop a clinical and laboratory module that records consultations and links each test request to its result.',
    3: 'To develop a pharmacy and inventory module that updates stock when medicines are issued and alerts staff to low stock and expiry.',
    4: 'To develop a billing and reporting module that produces itemised invoices, records payments and generates management reports.',
    5: 'To implement secure log-in, role-based access control and an audit trail in line with the Data Protection Act, 2019.',
}

MODULES = {
    1: 'Patient registration and appointments',
    2: 'Clinical consultation',
    3: 'Laboratory management',
    4: 'Pharmacy and inventory',
    5: 'Billing and reports',
    6: 'User management and security',
}

# Each feature: key, module, title, priority, objective list, description, stimulus/response pairs, requirements.
# A requirement: (id, text, priority, use case(s), tests)
FEATURES = [
    dict(key='F01', module=1, title='Patient registration', priority='High', objectives=[1],
         description='Registers each patient once and issues the outpatient (OP) number that identifies the patient in every other module. '
                     'Without it no other feature can be used, so it is built first.',
         stimulus=[
             ('The receptionist fills in the registration form and presses Register patient.', 'The system checks every field, looks for an existing patient, issues the next OP number and shows the new record.'),
             ('The form has errors.', 'The system keeps what was typed and shows a message beside each wrong field and a list at the top.'),
             ('The ID number is already registered.', 'The system refuses and shows the OP number of the existing patient.'),
             ('A patient with the same name and date of birth exists.', 'The system lists the similar patients and saves only after the receptionist confirms.'),
         ],
         reqs=[
             ('REQ-001', 'The system shall let a receptionist register a patient by entering first name, last name, sex and date of birth (required) and middle name, ID number, mobile number, residence, allergies and next of kin (optional).', 'High', 'UC-09', 'UT-PAT-005, E2E-018, E2E-022'),
             ('REQ-002', 'The system shall give every new patient a unique outpatient number in the form OPyy-nnnnn (two-digit year, five-digit serial) that is never changed and never reused, and shall not use up a number when a registration fails.', 'High', 'UC-09', 'UT-PAT-001, UT-PAT-002, UT-PAT-003, UT-PAT-004, E2E-019'),
             ('REQ-003', 'The system shall refuse names that contain digits or symbols (apostrophes, hyphens and dots are allowed), dates of birth that are impossible, in the future or more than 120 years ago, and mobile numbers that are not Kenyan, and shall show a message beside each field.', 'High', 'UC-09', 'UT-PAT-005, UT-PAT-006, UT-PAT-007, UT-PAT-008, UT-COR-002, UT-COR-003, UT-COR-004, UT-COR-005, E2E-022'),
             ('REQ-004', 'The system shall refuse a second patient with the same ID number, showing the existing OP number, and shall ask for confirmation when a patient with the same name and date of birth already exists.', 'High', 'UC-09', 'UT-PAT-009, UT-PAT-010, E2E-020, E2E-021'),
             ('REQ-005', 'The system shall allow a patient without an ID number, such as a young child, without clashing with other patients who also have none.', 'Medium', 'UC-09', 'UT-PAT-011'),
             ('REQ-006', 'The system shall record who registered each patient and when, and shall write an audit entry that holds no health details.', 'High', 'UC-09', 'UT-PAT-012, E2E-031, E2E-033'),
         ]),
    dict(key='F02', module=1, title='Patient search and correction', priority='High', objectives=[1],
         description='Finds a patient quickly from whatever the staff member knows, and lets the receptionist correct mistakes without changing the OP number.',
         stimulus=[
             ('A staff member types part of a name, an OP number, an ID number or a phone number and presses Search.', 'The system lists matching patients 25 to a page, with a link to each record.'),
             ('The receptionist corrects a detail and saves.', 'The system validates the form, saves the change and records which fields changed (not their values).'),
         ],
         reqs=[
             ('REQ-007', 'The system shall find patients by outpatient number, name, ID number or mobile number, accepting part of the text, and list the results 25 to a page.', 'High', 'UC-10', 'UT-PAT-015, UT-PAT-017, E2E-025, E2E-026, E2E-027'),
             ('REQ-008', 'The system shall treat everything typed in the search box as plain text, including the characters % and _ and any SQL.', 'High', 'UC-10', 'UT-PAT-016, UT-COR-015, E2E-024'),
             ('REQ-009', 'The system shall let a receptionist correct a patient\'s details, shall keep the OP number unchanged, shall refuse an ID number that belongs to another patient, and shall log the names of the fields changed but not their values.', 'High', 'UC-11', 'UT-PAT-013, UT-PAT-014, E2E-028'),
             ('REQ-010', 'The system shall show a patient\'s recorded allergies to clinical staff wherever the patient is handled: the triage queue, the consultation page and the prescription queue.', 'High', 'UC-10, UC-20', 'E2E-054, E2E-059, E2E-100'),
         ]),
    dict(key='F03', module=1, title='Patient import from a spreadsheet file', priority='Low', objectives=[1],
         description='Moves patients already recorded in a spreadsheet or in the paper register into the system in one step, after the file has been checked.',
         stimulus=[
             ('The receptionist chooses a CSV file and presses Check the file.', 'The system shows each row as Valid, Duplicate or Error, and saves nothing.'),
             ('The receptionist confirms.', 'The system registers only the valid rows, issues their OP numbers and logs the import.'),
         ],
         reqs=[
             ('REQ-011', 'The system shall import patients from a CSV file of up to 500 rows and 1 MB: it shall first show a preview that classifies every row and saves nothing, shall save only the valid rows after confirmation, and shall refuse a file without the required columns.', 'Low', 'UC-12', 'UT-PAT-018, UT-PAT-019, UT-PAT-020'),
         ]),
    dict(key='F04', module=1, title='Appointments and the diary', priority='High', objectives=[1],
         description='Books a doctor\'s time in 20-minute slots so that two patients are never given the same slot, and shows each day\'s diary.',
         stimulus=[
             ('The receptionist chooses a patient, a doctor and a date.', 'The system shows the day\'s 27 slots as free, taken or past.'),
             ('The receptionist chooses a free slot and presses Book.', 'The system saves the appointment as Booked and returns to the diary.'),
             ('Someone else has just taken the slot.', 'The system refuses and asks for another slot; no double booking is saved.'),
             ('The receptionist cancels or marks an appointment as missed.', 'The system changes the status, releases the slot on cancellation and records the action.'),
         ],
         reqs=[
             ('REQ-012', 'The system shall book appointments in 20-minute slots from 08:00 to 16:40, Monday to Saturday, up to 90 days ahead, and shall refuse past dates and times, times off the grid, closed days, unknown patients and inactive doctors.', 'High', 'UC-13', 'UT-APT-001, UT-APT-002, UT-APT-003, UT-APT-007, UT-APT-008, UT-APT-009, UT-APT-010, E2E-040, E2E-041, E2E-045, E2E-046'),
             ('REQ-013', 'The system shall never book one doctor twice for the same slot, nor one patient for two appointments at the same time, even when two receptionists save at the same moment; the database itself shall enforce this.', 'High', 'UC-13', 'UT-APT-004, UT-APT-005, UT-APT-006, UT-APT-014, UT-APT-018, E2E-043, E2E-044'),
             ('REQ-014', 'The system shall show the diary for a chosen date, doctor and status in time order, and shall show each slot as free, taken or past when booking.', 'High', 'UC-14', 'UT-APT-016, UT-APT-017, E2E-040, E2E-042'),
             ('REQ-015', 'The system shall allow a Booked appointment to be cancelled before its time (releasing the slot) or marked Missed after its time has passed, shall mark overdue appointments Missed automatically, and shall not allow any other change of status.', 'Medium', 'UC-15', 'UT-APT-011, UT-APT-012, UT-APT-013, UT-APT-015, E2E-047, E2E-048'),
         ]),
    dict(key='F05', module=2, title='Check-in and queues', priority='High', objectives=[2],
         description='Opens a visit when the patient arrives and moves the patient from reception to the nurse and then to the doctor, so each department sees who is waiting.',
         stimulus=[
             ('The receptionist checks in a patient who has a booked appointment today.', 'The system opens a visit, marks the appointment Attended and puts the patient in the triage queue.'),
             ('The receptionist checks in a walk-in patient.', 'The system opens a visit without an appointment, with or without a preferred doctor.'),
             ('The patient already has an open visit.', 'The system refuses and shows the open visit.'),
         ],
         reqs=[
             ('REQ-016', 'The system shall check in a patient from today\'s booked appointment (the appointment becomes Attended and the doctor is assigned) or as a walk-in with or without a preferred doctor, and shall open a visit that waits for triage.', 'High', 'UC-16', 'UT-VIS-001, UT-VIS-002, E2E-049, E2E-050, E2E-051, E2E-053'),
             ('REQ-017', 'The system shall not allow a patient two open visits and shall refuse another patient\'s, a cancelled or a future appointment.', 'High', 'UC-16', 'UT-VIS-003, UT-VIS-004, E2E-052'),
             ('REQ-018', 'The system shall show each open visit at its stage (Waiting for triage, Waiting for doctor, With doctor) with the waiting time; a doctor shall see own and unassigned patients only.', 'High', 'UC-17', 'UT-VIS-005, E2E-054, E2E-058'),
         ]),
    dict(key='F06', module=2, title='Triage and vital signs', priority='High', objectives=[2],
         description='Lets the nurse record the patient\'s measurements before the doctor sees the patient, and refuses values that cannot be right.',
         stimulus=[
             ('The nurse enters the measurements and presses Save.', 'The system checks each value against its allowed range, saves the triage record and moves the patient to the doctor\'s queue.'),
             ('A value is impossible (for example a temperature of 60).', 'The system shows a message beside the field and saves nothing.'),
         ],
         reqs=[
             ('REQ-019', 'The system shall let a nurse record temperature, pulse, blood pressure (both values), breathing rate, oxygen saturation, weight, height and notes, shall refuse values outside the allowed ranges with a message for each field, and shall move the patient to the doctor\'s queue.', 'High', 'UC-18', 'UT-TRI-001, UT-TRI-002, UT-TRI-003, UT-TRI-004, E2E-055, E2E-056'),
             ('REQ-020', 'The system shall let the nurse correct a triage record (the same record is updated and the change is audited) but not on a closed visit.', 'Medium', 'UC-18', 'UT-TRI-005, UT-TRI-006'),
         ]),
    dict(key='F07', module=2, title='Consultation record', priority='High', objectives=[2],
         description='Gives the doctor the patient\'s earlier visits, allergies and triage on one page and records the complaint, examination, diagnosis and plan.',
         stimulus=[
             ('The doctor opens a patient from the queue.', 'The system shows triage, allergy warning and earlier visits, and the consultation form with the ICD-10 list.'),
             ('The doctor saves the consultation.', 'The system validates it, records the doctor, adds the consultation fee to the invoice and moves the stage to With doctor.'),
             ('The doctor presses Close visit.', 'The system closes the visit if a consultation exists and locks the record.'),
         ],
         reqs=[
             ('REQ-021', 'The system shall let a doctor record the complaint, examination, diagnosis in words, an optional ICD-10 code from the hospital list, the plan and the type of consultation; complaint, diagnosis and type shall be required.', 'High', 'UC-20', 'UT-CON-001, UT-CON-002, E2E-059, E2E-060, E2E-061, E2E-063'),
             ('REQ-022', 'The system shall show the doctor the patient\'s triage, allergies and earlier visits (diagnoses, tests and medicines, newest first) on the consultation page, and shall let the doctor find an ICD-10 code by its code or by words.', 'High', 'UC-20, UC-21', 'UT-CON-006, UT-CON-008, E2E-059, E2E-070'),
             ('REQ-023', 'The system shall allow only the doctor who recorded a consultation to change it, shall allow a visit to be closed only after a consultation exists, and shall lock a closed visit.', 'High', 'UC-20, UC-22', 'UT-CON-003, UT-CON-004, E2E-069'),
             ('REQ-024', 'The system shall show vital signs and diagnoses only to clinical staff; a receptionist who opens a visit shall see that it exists but not its clinical record.', 'High', 'UC-17, UC-21', 'UT-CON-007, E2E-068, E2E-227, E2E-228'),
         ]),
    dict(key='F08', module=2, title='Procedures and other services', priority='Medium', objectives=[2, 4],
         description='Records a procedure such as a wound dressing, so that it appears on the invoice without the cashier having to ask for it.',
         stimulus=[
             ('The nurse or doctor chooses a procedure and a quantity and presses Record.', 'The system saves it against the visit and adds the charge to the invoice.'),
         ],
         reqs=[
             ('REQ-025', 'The system shall let a nurse or doctor record a procedure from the price list with a quantity from 1 to 20 on an open visit, and the procedure shall appear on the invoice.', 'Medium', 'UC-19', 'UT-CON-005, E2E-057, UT-BIL-005'),
         ]),
    dict(key='F09', module=3, title='Laboratory test requests', priority='High', objectives=[2],
         description='Sends the doctor\'s test requests straight to the laboratory, so that no paper request is carried by the patient.',
         stimulus=[
             ('The doctor ticks one or more tests and presses Send to laboratory.', 'The system saves one request for each test, tied to the visit and to the doctor, and adds the test price to the invoice.'),
             ('The same test is already pending for the visit.', 'The system refuses the whole request and names the test.'),
             ('The doctor cancels a request that the laboratory has not started.', 'The system cancels it and removes the charge from the invoice.'),
         ],
         reqs=[
             ('REQ-026', 'The system shall let a doctor request one or more tests for an open visit that has a consultation, shall link each request to the visit and to the requesting doctor, and shall add the test price to the invoice.', 'High', 'UC-23', 'UT-LAB-001, UT-LAB-002, E2E-088, E2E-089, UT-BIL-003'),
             ('REQ-027', 'The system shall refuse a test that is already pending for the visit and shall save nothing if any test in the request is unknown, but shall allow a test to be repeated after it is completed.', 'High', 'UC-23', 'UT-LAB-003, UT-LAB-004, E2E-090'),
             ('REQ-028', 'The system shall let only the requesting doctor cancel a request, and only before the laboratory starts it; the charge shall then be removed from the invoice.', 'Medium', 'UC-24', 'UT-LAB-009, UT-BIL-003'),
         ]),
    dict(key='F10', module=3, title='Laboratory worklist and results', priority='High', objectives=[2],
         description='Shows the laboratory what to do, takes the result and returns it to the doctor who asked for it.',
         stimulus=[
             ('The technologist opens the worklist.', 'The system lists open requests, oldest first, with the patient, test, requesting doctor and clinical notes.'),
             ('The technologist enters a result and presses Save.', 'The system records the result, the range flag, who entered it and when, and marks the request Completed.'),
             ('The doctor opens My test results.', 'The system lists the results of the tests that doctor requested.'),
         ],
         reqs=[
             ('REQ-029', 'The system shall show the laboratory a worklist of open requests, oldest first, with the patient, the test, the requesting doctor and the clinical notes.', 'High', 'UC-26', 'UT-LAB-005, E2E-091'),
             ('REQ-030', 'The system shall let the laboratory mark a test In progress and enter a result with a within-range or outside-range flag; the result shall not be empty, shall not be entered for a cancelled request, and shall be recorded with who entered it and when; a correction shall be audited as an amendment.', 'High', 'UC-27, UC-28', 'UT-LAB-006, UT-LAB-007, UT-LAB-010, E2E-092, E2E-093, E2E-094'),
             ('REQ-031', 'The system shall return a result to the doctor who requested the test, on the My test results page and on the consultation page; other doctors shall not see it there but shall still see the patient\'s history.', 'High', 'UC-25', 'UT-LAB-008, UT-LAB-011, UT-INT-011, E2E-095, E2E-096, E2E-097'),
             ('REQ-032', 'The system shall keep result text out of the audit trail.', 'High', 'UC-28', 'UT-LAB-010, E2E-115'),
         ]),
    dict(key='F11', module=4, title='Medicine list and stock', priority='High', objectives=[3],
         description='Keeps the list of medicines and the stock held in batches with expiry dates, so that the quantity shown is the quantity that can safely be given.',
         stimulus=[
             ('The pharmacist adds a medicine or changes its price or reorder level.', 'The system validates the details, refuses a duplicate and records the change.'),
             ('The pharmacist receives a delivery.', 'The system creates a batch with its expiry date, adds a ledger entry and updates the stock shown.'),
             ('Stock falls to the reorder level or a batch nears its expiry date.', 'The system shows Low, Out or Expiring on the stock page and in the alerts on the dashboards.'),
         ],
         reqs=[
             ('REQ-033', 'The system shall let the pharmacist keep a list of medicines (name, form, strength, unit, unit price, reorder level and whether active) and shall refuse the same medicine twice; every edit shall be audited with the fields changed and the price movement.', 'High', 'UC-35', 'UT-PHA-001, UT-PHA-021, E2E-080, E2E-082'),
             ('REQ-034', 'The system shall record a delivery as a batch with batch number, expiry date and quantity, shall write a ledger entry, and shall refuse stock that has already expired or has invalid details.', 'High', 'UC-33', 'UT-PHA-002, UT-PHA-003, UT-PHA-004, E2E-083, E2E-084'),
             ('REQ-035', 'The system shall show for each medicine the usable quantity (expired batches left out) and a status of OK, Low or Out against its reorder level.', 'High', 'UC-30', 'UT-PHA-005, E2E-085'),
             ('REQ-036', 'The system shall flag batches that expire within 90 days and batches that have expired, and shall list low stock, out-of-stock and expiry alerts for the pharmacist and the manager.', 'High', 'UC-36', 'UT-PHA-006, UT-PHA-007, E2E-086, E2E-087'),
             ('REQ-037', 'The system shall let the pharmacist adjust stock for damage, write-off or a count difference only with a reason, shall not allow a balance below zero, and shall record the adjustment in the ledger.', 'Medium', 'UC-34', 'UT-PHA-008'),
             ('REQ-038', 'The system shall keep a ledger of every change to stock (received, dispensed, adjusted) with who and when, and the ledger shall always agree with the batch balances.', 'High', 'UC-33, UC-34, UC-32', 'UT-PHA-018, UT-INT-003, E2E-106'),
         ]),
    dict(key='F12', module=4, title='Prescribing', priority='High', objectives=[2, 3],
         description='Lets the doctor send the prescription to the pharmacy from the consultation page, with a reminder when a medicine matches a recorded allergy.',
         stimulus=[
             ('The doctor chooses a medicine and enters the dose, how often, for how long and the quantity.', 'The system saves the prescription and places it in the pharmacy queue.'),
             ('A line is incomplete or the quantity is zero.', 'The system refuses the prescription and shows the line to correct.'),
         ],
         reqs=[
             ('REQ-039', 'The system shall let a doctor prescribe one or more medicines, each with dose, frequency, duration and quantity, on an open visit that has a consultation, and shall refuse incomplete or invalid lines.', 'High', 'UC-29', 'UT-PHA-009, UT-PHA-010, E2E-098, E2E-099'),
             ('REQ-040', 'The system shall remind the prescriber and the pharmacist when a recorded allergy matches the name of a medicine on the prescription.', 'Medium', 'UC-29', 'UT-PHA-011, E2E-100'),
         ]),
    dict(key='F13', module=4, title='Dispensing', priority='High', objectives=[3],
         description='Gives the medicines on a prescription and takes them off the shelf in the system at the same moment, using the batch that expires first.',
         stimulus=[
             ('The pharmacist opens a prescription and enters the quantity to give for each line.', 'The system shows the stock available for each medicine.'),
             ('The pharmacist presses Dispense.', 'The system deducts the stock, updates the prescription, adds the medicines to the invoice and shows which batch each came from.'),
             ('There is not enough unexpired stock for a line.', 'The system refuses the whole dispensing and deducts nothing.'),
         ],
         reqs=[
             ('REQ-041', 'The system shall list prescriptions waiting in the pharmacy, oldest first, with an allergy flag.', 'High', 'UC-31', 'UT-PHA-020, E2E-100, E2E-101'),
             ('REQ-042', 'The system shall dispense by taking stock from the batch that expires first, continuing into the next batch when needed, and shall never take stock from an expired batch.', 'High', 'UC-32', 'UT-PHA-012, UT-PHA-013, UT-PHA-014, E2E-104, E2E-105'),
             ('REQ-043', 'The system shall refuse a quantity larger than what remains on the prescription or than the usable stock, shall treat all lines as one transaction (all given or none), and shall leave the rest of a part-dispensed prescription in the queue.', 'High', 'UC-32', 'UT-PHA-015, UT-PHA-016, UT-PHA-017, E2E-103, E2E-107'),
             ('REQ-044', 'The database shall refuse a negative batch balance even if the application is bypassed.', 'High', 'UC-32', 'UT-PHA-019'),
             ('REQ-045', 'The system shall add dispensed medicines to the invoice at the quantity actually given.', 'High', 'UC-32', 'UT-BIL-004'),
         ]),
    dict(key='F14', module=5, title='Invoices', priority='High', objectives=[4],
         description='Builds each patient\'s invoice from the services already recorded, so that nothing is charged from a paper slip and nothing is forgotten.',
         stimulus=[
             ('A billable service is recorded (consultation, test, medicine given or procedure).', 'The system creates the visit\'s invoice if it does not exist and adds or updates the charge.'),
             ('The cashier opens the invoice list.', 'The system lists invoices that have a balance, and lets the cashier search by number, patient, status or date.'),
         ],
         reqs=[
             ('REQ-046', 'The system shall create an itemised invoice numbered INV-yyyy-nnnnnn automatically when the first billable service is recorded for a visit, shall have one invoice per visit, and shall create none until there is something to charge.', 'High', 'UC-37', 'UT-BIL-001, UT-BIL-002, UT-BIL-007, UT-BIL-019, E2E-121, E2E-122'),
             ('REQ-047', 'The system shall never charge the same service twice, shall remove a charge whose source is cancelled, shall raise the balance when a charge is added after payment, shall refuse a removal that would leave the invoice over-paid, and shall keep the agreed price of a charge once a payment has been taken.', 'High', 'UC-37', 'UT-BIL-006, UT-BIL-012, UT-BIL-013, UT-BIL-014'),
             ('REQ-048', 'The system shall give the cashier no way to change a charge, price or quantity on an invoice.', 'High', 'UC-37', 'E2E-123'),
             ('REQ-049', 'The system shall let the cashier list and search invoices by number, outpatient number, patient name, status, balance and dates.', 'Medium', 'UC-37', 'UT-BIL-017, E2E-120, E2E-238'),
         ]),
    dict(key='F15', module=5, title='Payments and receipts', priority='High', objectives=[4],
         description='Records what the patient pays, in full or in part, by cash, M-Pesa, card or insurance, and issues a numbered receipt.',
         stimulus=[
             ('The cashier chooses the method, enters the amount and the reference and presses Record payment.', 'The system validates them, saves the payment, updates the invoice status and shows the receipt.'),
             ('The amount is more than the balance, or the M-Pesa code is not valid or already used.', 'The system refuses and shows the reason beside the field.'),
         ],
         reqs=[
             ('REQ-050', 'The system shall record a payment by cash, M-Pesa, card or insurance, in full or in part, for an amount above zero and not above the balance, and shall move the invoice from Unpaid to Partially paid to Paid; a paid invoice shall accept no further payment.', 'High', 'UC-38', 'UT-BIL-008, UT-BIL-009, UT-BIL-019, E2E-125, E2E-127, E2E-128, E2E-130, E2E-131'),
             ('REQ-051', 'The system shall require a reference that fits the method (a 10-character M-Pesa code, a card approval code of 4 to 20 characters, or an insurer\'s claim number), and shall refuse an M-Pesa code that has been used before.', 'High', 'UC-38', 'UT-BIL-010, UT-BIL-011, E2E-126, E2E-129'),
             ('REQ-052', 'The system shall issue a numbered receipt RCT-yyyy-nnnnnn showing the method, amount and who received it, and shall let the cashier print the invoice and the receipt.', 'High', 'UC-39', 'UT-BIL-016, E2E-132'),
             ('REQ-053', 'The system shall show the cashier today\'s collections by method.', 'Medium', 'UC-40', 'UT-BIL-018, E2E-133'),
             ('REQ-054', 'The system shall calculate and store all money amounts exactly to the cent, with no rounding drift.', 'High', 'UC-38', 'UT-COR-006, UT-BIL-015'),
             ('REQ-055', 'The system shall audit each payment with its amount and method but not its reference code.', 'High', 'UC-38', 'UT-BIL-016, E2E-145, E2E-146'),
         ]),
    dict(key='F16', module=5, title='Management reports', priority='High', objectives=[4],
         description='Turns the day-to-day records into the four reports management needs, for any period, without anyone compiling them by hand.',
         stimulus=[
             ('The manager chooses a report and a date range and presses Show report.', 'The system calculates the report from the live records and shows it with totals.'),
             ('The manager presses Download CSV.', 'The system sends the same figures as a spreadsheet file and records the export.'),
         ],
         reqs=[
             ('REQ-056', 'The system shall produce an attendance report for a date range: appointments by outcome, attendance rate, total visits, walk-ins, new patients and visits per doctor.', 'High', 'UC-41', 'UT-REP-002, UT-REP-003, E2E-140'),
             ('REQ-057', 'The system shall produce a revenue report that separates the amount billed from the amount collected, by day, by payment method and by type of service, and shows unpaid balances.', 'High', 'UC-42', 'UT-REP-004, E2E-139'),
             ('REQ-058', 'The system shall produce a stock report with stock levels, stock value, units received, dispensed and adjusted in the period, and expiry alerts.', 'High', 'UC-43', 'UT-REP-005, E2E-142'),
             ('REQ-059', 'The system shall produce a laboratory report with tests requested, completed, pending, cancelled and outside range, average time to result, and the count by requesting doctor.', 'Medium', 'UC-44', 'UT-REP-006, E2E-141'),
             ('REQ-060', 'The system shall let every report be downloaded as a CSV file in which any cell that starts with =, +, - or @ is neutralised so that a spreadsheet does not run it as a formula.', 'Medium', 'UC-45', 'UT-REP-007, E2E-143, E2E-144, E2E-245'),
             ('REQ-061', 'The system shall default a report\'s dates to the current month, shall swap dates given the wrong way round, and shall fall back to a safe range when the dates are not valid.', 'Low', 'UC-41, UC-42', 'UT-REP-001, E2E-239'),
             ('REQ-062', 'The system shall allow each report only to the managers and the departments it concerns, and shall record every export in the audit trail.', 'High', 'UC-41, UC-45', 'UT-REP-008, E2E-134, E2E-137, E2E-138, E2E-145, E2E-215 to E2E-218, E2E-229'),
         ]),
    dict(key='F17', module=6, title='Sign-in, passwords and sessions', priority='High', objectives=[5],
         description='Makes every user prove who they are with a personal account, protects the account against guessing, and ends sessions that are no longer in use.',
         stimulus=[
             ('A user enters a username and password and presses Sign in.', 'The system checks them, starts a new session and opens the dashboard for the user\'s role.'),
             ('The user enters a wrong password five times.', 'The system locks the account for 15 minutes, even for the right password, and records each attempt.'),
             ('A user signs in for the first time, or after a password reset.', 'The system asks for a new password and allows nothing else until it is set.'),
             ('A user leaves the computer for 30 minutes.', 'The system ends the session; the next request goes to the sign-in page.'),
         ],
         reqs=[
             ('REQ-063', 'The system shall let a user sign in only with a personal username and password, and shall show the same message for a wrong password and an unknown username.', 'High', 'UC-01', 'UT-AUT-001, UT-AUT-002, UT-AUT-003, UT-AUT-010, E2E-004, E2E-005'),
             ('REQ-064', 'The system shall lock an account for 15 minutes after five wrong passwords (refusing even the right password), shall block a computer after 20 failures in 15 minutes, and shall refuse a deactivated account.', 'High', 'UC-01', 'UT-AUT-004, UT-AUT-005, UT-AUT-006, UT-AUT-007, E2E-036'),
             ('REQ-065', 'The system shall store passwords only as bcrypt hashes.', 'High', 'UC-01', 'UT-AUT-009'),
             ('REQ-066', 'The system shall require a password of 10 to 72 characters with a lower-case letter, an upper-case letter and a number that does not contain the username; a one-time password shall have to be changed at first sign-in, and changing a password shall require the current one.', 'High', 'UC-02', 'UT-COR-008, UT-COR-009, UT-AUT-012, E2E-006, E2E-007, E2E-008, E2E-009, E2E-236'),
             ('REQ-067', 'The system shall end a session after 30 minutes without activity or 8 hours in total, shall issue a new session number at sign-in, shall make the old session useless after sign-out, and shall end a person\'s open session at once when the account is deactivated.', 'High', 'UC-01, UC-03', 'UT-INT-007, E2E-030, E2E-035, E2E-231, E2E-232, E2E-234, E2E-235'),
             ('REQ-068', 'The system shall send the user after sign-in only to a page inside UB-HMS.', 'Medium', 'UC-01', 'E2E-034'),
         ]),
    dict(key='F18', module=6, title='User account management', priority='High', objectives=[5],
         description='Lets the administrator create accounts for staff, give each one a role, and stop an account when a person leaves.',
         stimulus=[
             ('The administrator fills in the new account form.', 'The system creates the account with a one-time password shown once, which the user must change at first sign-in.'),
             ('The administrator deactivates an account.', 'The system blocks sign-in and ends any open session of that person.'),
         ],
         reqs=[
             ('REQ-069', 'The system shall let the administrator create an account with a full name, a unique username and one of eight roles, issue a one-time password, and validate the details.', 'High', 'UC-04', 'UT-USR-001, UT-USR-002, UT-USR-003, E2E-015, E2E-016, E2E-017'),
             ('REQ-070', 'The system shall let the administrator edit, activate and deactivate accounts, reset a password and unlock an account, but shall not let an administrator deactivate their own account or the last active administrator.', 'High', 'UC-04, UC-05', 'UT-USR-004, UT-USR-005, UT-USR-006, UT-USR-007, UT-AUT-005'),
             ('REQ-071', 'The system shall record all account management in the audit trail.', 'High', 'UC-04, UC-05', 'UT-USR-008, UT-USR-009'),
         ]),
    dict(key='F19', module=6, title='Role-based access control', priority='High', objectives=[5],
         description='Gives each role only the pages and actions that its work needs, and refuses the rest, whatever address the user types.',
         stimulus=[
             ('A user opens the menu.', 'The system lists only the pages the user\'s role may open.'),
             ('A user types the address of a page the role may not open.', 'The system shows a 403 "not allowed" page and records the attempt.'),
             ('A page that changes data is requested without the security token, or by an ordinary link.', 'The system refuses and changes nothing.'),
         ],
         reqs=[
             ('REQ-072', 'The system shall check the signed-in user\'s permissions before every page and every action, shall refuse anything the role does not hold with a 403 page, and shall give no role more than its work needs (for example, the administrator shall not see patient records and the manager shall not see individual invoices).', 'High', 'UC-01', 'UT-COR-011, UT-COR-012, UT-AUT-011, E2E-010 to E2E-014, E2E-065 to E2E-067, E2E-108 to E2E-113, E2E-135 to E2E-138, E2E-200 to E2E-224, E2E-229'),
             ('REQ-073', 'The system shall show in each user\'s menu only the pages that user\'s role may open.', 'Medium', 'UC-01', 'E2E-010'),
             ('REQ-074', 'The system shall record refused requests in the audit trail.', 'High', 'UC-06', 'E2E-225, E2E-226'),
             ('REQ-075', 'The system shall accept a change to data only through a POST request that carries a valid security (CSRF) token, shall refuse the same request from another session, and shall never change data in answer to a plain link.', 'High', 'UC-01', 'UT-COR-010, UT-INT-009, E2E-003, E2E-029, E2E-064, E2E-102, E2E-124, E2E-233, E2E-248'),
         ]),
    dict(key='F20', module=6, title='Audit trail', priority='High', objectives=[5],
         description='Keeps a record of who did what, to which record, from which computer and when, so that misuse can be found and nobody can deny what they did.',
         stimulus=[
             ('A user performs an important action.', 'The system writes an audit entry with the person, action, record, computer address and time.'),
             ('The manager or administrator opens the audit trail.', 'The system lists entries 25 to a page, with filters, and records that it was opened.'),
         ],
         reqs=[
             ('REQ-076', 'The system shall record in the audit trail who did what, to which record, from which computer and when, for sign-ins and failed sign-ins, registrations, corrections, views of a patient record, check-ins, triage, consultations, test requests and results, prescriptions, dispensing, stock changes, payments, price changes, report exports, account management and refused requests.', 'High', 'UC-06', 'UT-COR-013, UT-AUT-008, E2E-031, E2E-032, E2E-071, E2E-114, E2E-145'),
             ('REQ-077', 'The system shall keep names, phone numbers, diagnoses, test results and payment reference codes out of the audit trail; it shall hold identifiers and short descriptions only.', 'High', 'UC-06', 'E2E-033, E2E-115, E2E-146'),
             ('REQ-078', 'The system shall let the administrator and the manager search the audit trail by user, action, record and dates, and shall record that the trail was opened.', 'High', 'UC-06', 'UT-USR-010, E2E-222, E2E-240, E2E-252'),
             ('REQ-079', 'The system shall not let any page change or delete an audit entry, and the database account used by the application shall be able only to add rows to the audit trail, the payments and the stock ledger.', 'High', 'UC-06', 'E2E suite re-run with the restricted account'),
         ]),
    dict(key='F21', module=6, title='Price list and system health', priority='Medium', objectives=[4, 5],
         description='Lets the administrator keep the prices of services and tests up to date and check that the system, the data and the backups are healthy.',
         stimulus=[
             ('The administrator changes a price and saves.', 'The system validates the amount, saves it and records the old and new prices.'),
             ('The administrator opens System health.', 'The system runs the checks and shows each one as OK, Warning or Failed.'),
         ],
         reqs=[
             ('REQ-080', 'The system shall let the administrator change the price of a service or laboratory test, shall refuse an invalid price, and shall record the old and the new price in the audit trail.', 'Medium', 'UC-07', 'E2E-250, E2E-251'),
             ('REQ-081', 'The system shall show the administrator the state of the database, disk space, folders, the latest backup, failed sign-ins, locked accounts and a data-integrity check that compares invoices, payments, stock and prescriptions.', 'Medium', 'UC-08', 'UT-INT-004, UT-INT-010, E2E-249'),
         ]),
    dict(key='F22', module=6, title='Dashboards', priority='Medium', objectives=[1, 2, 3, 4],
         description='Gives each user a start page that shows the work waiting for their role and the shortcuts they use most.',
         stimulus=[
             ('A user signs in.', 'The system shows the dashboard of the user\'s role with counts of work waiting (patients, tests, prescriptions, invoices) and alerts.'),
         ],
         reqs=[
             ('REQ-082', 'The system shall show each user a dashboard for their role: patients waiting for nurses and doctors, tests waiting for the laboratory and results ready for doctors, prescriptions and stock alerts for the pharmacy, invoices and collections for the cashier, and revenue and stock alerts for the manager.', 'Medium', 'UC-01', 'UT-CON-009, UT-LAB-012, UT-BIL-020, E2E-086, E2E-147'),
         ]),
    dict(key='F23', module=6, title='Backup and recovery', priority='High', objectives=[5],
         description='Makes a safe copy of the database every night and puts it back if something goes wrong, so that the hospital does not lose its records.',
         stimulus=[
             ('The scheduled task runs the backup script.', 'The script writes a dated copy of the database, checks that it is complete, compresses it and deletes copies older than 30 days.'),
             ('The administrator runs the restore script.', 'The script makes a safety copy of the current data, asks for the database name as confirmation and then restores the chosen backup.'),
         ],
         reqs=[
             ('REQ-083', 'The system shall include a script that backs up the whole database to a dated file, checks that the file is complete, compresses it and removes copies older than 30 days, and a script that restores a chosen backup after making a safety copy of the current data and asking for confirmation.', 'High', 'UC-08', 'Manual checks MAN-01 and MAN-02 (Chapter 7)'),
         ]),
]


def all_requirements():
    for f in FEATURES:
        for r in f['reqs']:
            yield f, r


def requirement_count():
    return sum(len(f['reqs']) for f in FEATURES)


if __name__ == '__main__':
    ids = [r[0] for _, r in all_requirements()]
    assert len(ids) == len(set(ids)), 'duplicate requirement id'
    for i, rid in enumerate(ids, 1):
        assert rid == f'REQ-{i:03d}', (i, rid)
    print(len(FEATURES), 'features,', len(ids), 'requirements')
    by_obj = {}
    for f in FEATURES:
        for o in f['objectives']:
            by_obj.setdefault(o, 0)
            by_obj[o] += len(f['reqs'])
    print(by_obj)
