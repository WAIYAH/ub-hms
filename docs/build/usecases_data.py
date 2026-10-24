"""The use cases of UB-HMS, shared by the use case diagrams and the tables in Chapter 4.

Each entry: (id, name, [actors], summary, objective numbers it supports)
Objectives (proposal section 1.4.2): 1 registration and appointments; 2 clinical and laboratory;
3 pharmacy and inventory; 4 billing and reporting; 5 security (login, roles, audit trail).
"""

ACT_STAFF = 'Any staff member'
ACT_ADMIN = 'System administrator'
ACT_MGR = 'Hospital manager'
ACT_REC = 'Receptionist'
ACT_NUR = 'Nurse'
ACT_DOC = 'Doctor'
ACT_LAB = 'Laboratory technologist'
ACT_PHA = 'Pharmacist'
ACT_CAS = 'Cashier'

MODULES = [
    # key, title, objective, left actors, right actors
    ('uc_m6', 'Module 6: user management and security', [5], [ACT_STAFF, ACT_ADMIN], [ACT_MGR]),
    ('uc_m1', 'Module 1: patient registration and appointments', [1], [ACT_REC], [ACT_DOC, ACT_NUR]),
    ('uc_m2', 'Module 2: clinical consultation', [2], [ACT_REC, ACT_NUR], [ACT_DOC]),
    ('uc_m3', 'Module 3: laboratory management', [2], [ACT_DOC], [ACT_LAB]),
    ('uc_m4', 'Module 4: pharmacy and inventory', [3], [ACT_DOC, ACT_PHA], [ACT_MGR]),
    ('uc_m5', 'Module 5: billing and reports', [4], [ACT_CAS, ACT_PHA], [ACT_MGR]),
]

USE_CASES = {
    'uc_m6': [
        ('UC-01', 'Sign in', [ACT_STAFF], 'Staff enter their username and password; the system checks them, applies lockout and opens the menu for their role.', [5]),
        ('UC-02', 'Change own password', [ACT_STAFF], 'A user chooses a new password that meets the password rules. Required at first sign-in.', [5]),
        ('UC-03', 'Sign out', [ACT_STAFF], 'The session is ended and the browser can no longer use it.', [5]),
        ('UC-04', 'Manage user accounts', [ACT_ADMIN], 'Create, edit, activate and deactivate accounts and assign one of the eight roles.', [5]),
        ('UC-05', 'Reset password or unlock account', [ACT_ADMIN], 'Give a user a new one-time password or clear a lockout.', [5]),
        ('UC-06', 'View the audit trail', [ACT_ADMIN, ACT_MGR], 'Search who did what, from which computer and when.', [5]),
        ('UC-07', 'Maintain the price list', [ACT_ADMIN], 'Change the price of services and laboratory tests; every change is logged.', [4, 5]),
        ('UC-08', 'View system health and backup status', [ACT_ADMIN], 'See database, disk, backup, failed sign-in and data-integrity checks.', [5]),
    ],
    'uc_m1': [
        ('UC-09', 'Register patient', [ACT_REC], 'Enter the patient once; the system issues the outpatient number OPyy-nnnnn.', [1]),
        ('', 'Check for duplicate patients', [], 'Included in UC-09: same ID number is refused, same name and date of birth needs confirmation.', [1]),
        ('UC-10', 'Search patients', [ACT_REC, ACT_NUR, ACT_DOC], 'Find a patient by outpatient number, name, ID number or phone.', [1]),
        ('UC-11', 'Correct patient details', [ACT_REC], 'Correct spelling, contacts or allergies; the outpatient number never changes.', [1]),
        ('UC-12', 'Import patients from a CSV file', [ACT_REC], 'Move patients from the paper register or a spreadsheet after a checked preview.', [1]),
        ('UC-13', 'Book appointment', [ACT_REC], 'Choose doctor, date and a free 20-minute slot; clashes are refused.', [1]),
        ('', 'Check that the slot is free', [], 'Included in UC-13: no other booking for that doctor or that patient at that time.', [1]),
        ('UC-14', 'View the appointment diary', [ACT_REC, ACT_DOC, ACT_NUR], 'See each day\'s appointments by doctor and status.', [1]),
        ('UC-15', 'Cancel or mark an appointment missed', [ACT_REC], 'Cancel before the time, or mark missed after it passes; the slot is released.', [1]),
    ],
    'uc_m2': [
        ('UC-16', 'Check in patient', [ACT_REC], 'Open a visit for a booked patient (the appointment becomes Attended) or a walk-in.', [2]),
        ('UC-17', 'View visit queues', [ACT_REC, ACT_NUR, ACT_DOC], 'See who is waiting for triage, for the doctor, or still open.', [2]),
        ('UC-18', 'Record triage', [ACT_NUR], 'Enter vital signs and notes; implausible values are refused.', [2]),
        ('UC-19', 'Record a procedure or service', [ACT_NUR, ACT_DOC], 'Record, for example, a wound dressing so that it appears on the invoice.', [2, 4]),
        ('UC-20', 'Record consultation', [ACT_DOC], 'Complaint, examination, diagnosis (words and optional ICD-10 code) and plan.', [2]),
        ('', 'Show earlier visits and allergies', [], 'Included in UC-20: the doctor sees the history and allergy warning.', [2]),
        ('UC-21', 'View patient history', [ACT_DOC], 'Read a patient\'s earlier visits, diagnoses, tests and medicines.', [2]),
        ('UC-22', 'Close visit', [ACT_DOC], 'Mark the doctor\'s part of the visit complete.', [2]),
    ],
    'uc_m3': [
        ('UC-23', 'Request laboratory tests', [ACT_DOC], 'Choose tests for the visit; each request is tied to the requesting doctor.', [2]),
        ('', 'Add the test price to the invoice', [], 'Included in UC-23: the charge is added automatically.', [4]),
        ('UC-24', 'Cancel a test request', [ACT_DOC], 'Withdraw a request before the laboratory has started it.', [2]),
        ('UC-25', 'View my test results', [ACT_DOC], 'Read results for the tests this doctor requested.', [2]),
        ('UC-26', 'View the laboratory worklist', [ACT_LAB], 'See the tests waiting and in progress, oldest first.', [2]),
        ('UC-27', 'Start a test', [ACT_LAB], 'Mark a request as in progress.', [2]),
        ('UC-28', 'Enter or correct a result', [ACT_LAB], 'Record the result and a within/outside-range flag; the result goes to the requesting doctor.', [2]),
    ],
    'uc_m4': [
        ('UC-29', 'Prescribe medicines', [ACT_DOC], 'Add medicines with dose, frequency, duration and quantity; allergy reminders are shown.', [2, 3]),
        ('UC-30', 'View medicine availability', [ACT_DOC, ACT_PHA, ACT_MGR], 'See stock levels and status (OK, Low, Out).', [3]),
        ('UC-31', 'View the prescription queue', [ACT_PHA], 'See prescriptions waiting, oldest first.', [3]),
        ('UC-32', 'Dispense a prescription', [ACT_PHA], 'Give the medicines; stock is deducted from the batch that expires first.', [3]),
        ('', 'Deduct stock and add to the invoice', [], 'Included in UC-32: batches, ledger and invoice are updated in one transaction.', [3, 4]),
        ('UC-33', 'Receive a stock batch', [ACT_PHA], 'Record a delivery with batch number, expiry date and quantity.', [3]),
        ('UC-34', 'Adjust stock', [ACT_PHA], 'Correct a balance for damage, write-off or count differences, with a reason.', [3]),
        ('UC-35', 'Maintain the medicine list', [ACT_PHA], 'Add or change medicines, prices and reorder levels.', [3]),
        ('UC-36', 'View stock alerts', [ACT_PHA, ACT_MGR], 'See medicines that are low or out, and batches expiring or expired.', [3]),
    ],
    'uc_m5': [
        ('UC-37', 'View invoices', [ACT_CAS], 'Search and open invoices; each lists every service charged.', [4]),
        ('UC-38', 'Take a payment', [ACT_CAS], 'Record cash, M-Pesa, card or insurance payment, in full or in part.', [4]),
        ('', 'Check reference and balance', [], 'Included in UC-38: amount not above the balance; reference valid for the method.', [4]),
        ('UC-39', 'Print an invoice or receipt', [ACT_CAS], 'Print the invoice or a numbered receipt.', [4]),
        ('UC-40', 'View my collections today', [ACT_CAS], 'See today\'s takings by payment method for reconciliation.', [4]),
        ('UC-41', 'View the attendance report', [ACT_MGR], 'Appointments by outcome, walk-ins, new patients and patients seen per doctor.', [4]),
        ('UC-42', 'View the revenue report', [ACT_MGR], 'Billed and collected amounts by day, method and type of service.', [4]),
        ('UC-43', 'View the stock report', [ACT_MGR, ACT_PHA], 'Stock levels, value, movements and expiry alerts.', [3, 4]),
        ('UC-44', 'View the laboratory workload report', [ACT_MGR], 'Tests requested, completed, pending and time taken.', [4]),
        ('UC-45', 'Export a report to CSV', [ACT_MGR, ACT_PHA], 'Download a report for a spreadsheet.', [4]),
    ],
}

# which included use case belongs to which base use case (by the name of the entry before it)
