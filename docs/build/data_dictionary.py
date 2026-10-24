"""Plain-English description of every column in the UB-HMS database (Chapter 5, data dictionary).

The build scripts refuse to run if a column in the live schema has no description here, so the
dictionary cannot fall behind the database.
"""

TABLE_PURPOSE = {
    'roles': 'The eight staff roles (standard table).',
    'users': 'Staff accounts: one row per person who can sign in.',
    'op_sequences': 'Counter that issues the next outpatient number each year.',
    'patients': 'One row per registered patient.',
    'appointments': 'Booked, attended, missed and cancelled appointments.',
    'icd10_codes': 'Hospital list of ICD-10 diagnosis codes (standard table).',
    'services': 'Price list for consultations and procedures (standard table).',
    'visits': 'One row each time a patient is checked in.',
    'triage': 'Nurse measurements for a visit.',
    'consultations': "The doctor's record of a visit.",
    'visit_services': 'Procedures and other services recorded during a visit.',
    'lab_tests': 'Laboratory test catalogue and prices (standard table).',
    'lab_requests': 'Tests requested by doctors, and their results.',
    'medicines': 'Medicine list with price and reorder level.',
    'medicine_batches': 'Stock held in batches with expiry dates.',
    'prescriptions': 'A doctor\'s prescription for a visit.',
    'prescription_items': 'The medicines on a prescription and how much has been given.',
    'stock_movements': 'Ledger of every change to stock.',
    'invoices': 'One invoice per visit.',
    'invoice_items': 'The charges on an invoice.',
    'payments': 'Money received against invoices.',
    'audit_log': 'Record of who did what, from where and when.',
}

D = {
    'roles': {
        'id': 'Role number, 1 to 8',
        'code': 'Short role code: admin, manager, receptionist, nurse, doctor, lab, pharmacist or cashier',
        'name': 'Role name shown on screen',
        'description': 'What the role is for',
    },
    'users': {
        'id': 'User number, issued automatically',
        'full_name': 'Name of the staff member, 3 to 120 characters',
        'username': 'Sign-in name: 3 to 40 lower-case letters, numbers, dots, dashes or underscores; unique',
        'password_hash': 'bcrypt hash of the password (never the password itself)',
        'role_id': 'Role of the user (links to roles)',
        'phone': 'Mobile number in the form +254XXXXXXXXX (optional)',
        'is_active': '1 = may sign in, 0 = deactivated',
        'must_change_password': '1 = must choose a new password at the next sign-in',
        'failed_attempts': 'Wrong passwords since the last good sign-in, 0 to 5',
        'locked_until': 'Account locked until this time after five wrong passwords; empty when not locked',
        'last_login_at': 'Time of the last successful sign-in',
        'created_at': 'When the account was created',
        'updated_at': 'When the account was last changed',
    },
    'op_sequences': {
        'year': 'Four-digit year',
        'last_number': 'Last serial number issued in that year; the next patient receives this plus one',
    },
    'patients': {
        'id': 'Patient number, issued automatically',
        'op_number': 'Outpatient number in the form OPyy-nnnnn, for example OP26-00042; unique',
        'first_name': 'First name, letters, spaces, apostrophes, dots and hyphens only',
        'middle_name': 'Middle name (optional)',
        'last_name': 'Last name',
        'sex': 'Female or Male',
        'date_of_birth': 'Date of birth; not in the future and not more than 120 years ago',
        'dob_estimated': '1 = the date of birth is an estimate',
        'national_id': 'ID, passport or birth certificate number, 5 to 20 letters or numbers; unique; empty for a child without one',
        'phone': 'Mobile number as +254XXXXXXXXX (optional)',
        'residence': 'Estate or town (optional)',
        'allergies': 'Known allergies, shown to doctors, nurses and pharmacists (optional)',
        'next_of_kin_name': 'Name of next of kin (optional)',
        'next_of_kin_phone': 'Next of kin mobile number as +254XXXXXXXXX (optional)',
        'next_of_kin_relation': 'Relationship, for example Mother or Spouse (optional)',
        'registered_by': 'Staff member who registered the patient (links to users)',
        'created_at': 'When the patient was registered',
        'updated_at': 'When the details were last corrected',
    },
    'appointments': {
        'id': 'Appointment number, issued automatically',
        'patient_id': 'Patient (links to patients)',
        'doctor_id': 'Doctor (links to users with the doctor role)',
        'appointment_date': 'Date; a working day, today or later, up to 90 days ahead',
        'start_time': 'Start time on the 20-minute grid, 08:00 to 16:40',
        'end_time': 'End time, 20 minutes after the start',
        'reason': 'Reason for the visit (optional)',
        'status': 'Booked, Attended, Missed or Cancelled',
        'cancel_reason': 'Why the appointment was cancelled (optional)',
        'booked_by': 'Staff member who booked it (links to users)',
        'created_at': 'When the appointment was booked',
        'updated_at': 'When the status last changed',
        'slot_key': 'Calculated: doctor, date and time while the appointment is Booked or Attended, otherwise empty; unique, so a slot cannot be booked twice',
    },
    'icd10_codes': {
        'code': 'ICD-10 code, for example B54',
        'description': 'Name of the condition',
        'is_active': '1 = can be chosen by doctors',
    },
    'services': {
        'id': 'Service number, issued automatically',
        'name': 'Service name, for example General consultation; unique',
        'category': 'Consultation, Procedure or Other',
        'price': 'Price in Kenya shillings, 0 or more',
        'is_active': '1 = can be charged',
    },
    'visits': {
        'id': 'Visit number, issued automatically',
        'patient_id': 'Patient (links to patients)',
        'appointment_id': 'Appointment this visit came from; empty for a walk-in; unique',
        'doctor_id': 'Doctor the patient is waiting for or saw; empty until chosen (links to users)',
        'reason': 'Reason for the visit (optional)',
        'visit_date': 'When the patient was checked in',
        'status': 'Open or Closed',
        'opened_by': 'Staff member who checked the patient in (links to users)',
        'closed_at': 'When the doctor closed the visit',
    },
    'triage': {
        'id': 'Triage record number',
        'visit_id': 'Visit (links to visits); one triage record per visit',
        'temperature': 'Temperature in degrees Celsius, 30.0 to 45.0',
        'pulse': 'Beats per minute, 20 to 250',
        'bp_systolic': 'Upper blood pressure, mmHg, 50 to 300; entered with the lower value',
        'bp_diastolic': 'Lower blood pressure, mmHg, 30 to 200; lower than the upper value',
        'resp_rate': 'Breaths per minute, 5 to 80',
        'spo2': 'Oxygen saturation, per cent, 50 to 100',
        'weight_kg': 'Weight in kilograms, 0.3 to 400.0',
        'height_cm': 'Height in centimetres, 20.0 to 250.0',
        'notes': "Nurse's notes, up to 500 characters",
        'recorded_by': 'Nurse who recorded it (links to users)',
        'recorded_at': 'When it was recorded or last corrected',
    },
    'consultations': {
        'id': 'Consultation number',
        'visit_id': 'Visit (links to visits); one consultation per visit',
        'doctor_id': 'Doctor who recorded it (links to users); only this doctor may change it',
        'service_id': 'Type of consultation charged (links to services)',
        'complaint': "Patient's complaint and history (required)",
        'examination': 'Examination findings (optional)',
        'diagnosis': 'Diagnosis in words (required)',
        'icd10_code': 'ICD-10 code from the hospital list (optional)',
        'plan': 'Treatment plan and advice (optional)',
        'created_at': 'When the consultation was first saved',
        'updated_at': 'When it was last corrected',
    },
    'visit_services': {
        'id': 'Record number',
        'visit_id': 'Visit (links to visits)',
        'service_id': 'Procedure or service (links to services)',
        'quantity': 'How many times, 1 to 20',
        'recorded_by': 'Nurse or doctor who recorded it (links to users)',
        'recorded_at': 'When it was recorded',
    },
    'lab_tests': {
        'id': 'Test number',
        'name': 'Test name; unique',
        'reference_range': 'Usual range shown to the technologist (optional)',
        'price': 'Price in Kenya shillings, 0 or more',
        'is_active': '1 = can be requested',
    },
    'lab_requests': {
        'id': 'Request number',
        'visit_id': 'Visit (links to visits)',
        'test_id': 'Test requested (links to lab_tests)',
        'requested_by': 'Doctor who requested it; the result returns to this doctor (links to users)',
        'clinical_notes': 'Why the test is needed (optional)',
        'requested_at': 'When it was requested',
        'status': 'Requested, In progress, Completed or Cancelled',
        'result': 'Result text entered by the laboratory',
        'is_abnormal': '1 = outside the usual range, 0 = within it, empty = not stated',
        'result_by': 'Technologist who entered the result (links to users)',
        'result_at': 'When the result was entered',
    },
    'medicines': {
        'id': 'Medicine number',
        'name': 'Medicine name',
        'form': 'Tablet, capsule, syrup and so on (may be empty)',
        'strength': 'For example 500 mg (may be empty)',
        'unit': 'What one unit is: tablet, sachet, bottle',
        'unit_price': 'Selling price per unit in Kenya shillings, 0 or more',
        'reorder_level': 'Stock level at or below which the medicine shows as Low',
        'is_active': '1 = can be prescribed',
    },
    'medicine_batches': {
        'id': 'Batch record number',
        'medicine_id': 'Medicine (links to medicines)',
        'batch_no': 'Batch number printed on the pack, stored in upper case; unique for the medicine',
        'expiry_date': 'Expiry date from the pack',
        'quantity': 'Units still on the shelf, never below 0',
        'received_at': 'When the batch was received',
    },
    'prescriptions': {
        'id': 'Prescription number',
        'visit_id': 'Visit (links to visits)',
        'prescribed_by': 'Doctor who wrote it (links to users)',
        'status': 'Pending, Partially dispensed or Dispensed',
        'created_at': 'When it was written',
    },
    'prescription_items': {
        'id': 'Line number',
        'prescription_id': 'Prescription (links to prescriptions)',
        'medicine_id': 'Medicine (links to medicines)',
        'dosage': 'Dose, for example 1 tablet',
        'frequency': 'How often, for example three times a day',
        'duration': 'For how long, for example 5 days (optional)',
        'quantity': 'Total units to dispense, 1 to 9999',
        'dispensed_qty': 'Units given so far; never more than quantity',
    },
    'stock_movements': {
        'id': 'Ledger entry number',
        'medicine_id': 'Medicine (links to medicines)',
        'batch_id': 'Batch affected (links to medicine_batches)',
        'type': 'Receive, Dispense or Adjust',
        'quantity': 'Change in units: positive for receipts, negative for dispensing and write-offs',
        'reference': 'Batch number, prescription and line, or the reason for an adjustment',
        'user_id': 'Staff member who made the change (links to users)',
        'created_at': 'When the change was made',
    },
    'invoices': {
        'id': 'Invoice number (internal)',
        'invoice_no': 'Printed number in the form INV-yyyy-nnnnnn; unique',
        'visit_id': 'Visit billed (links to visits); one invoice per visit',
        'total': 'Sum of the invoice lines, Kenya shillings',
        'amount_paid': 'Sum of payments received; never more than the total',
        'status': 'Unpaid, Partially paid or Paid',
        'created_by': 'User whose action first created the invoice (links to users)',
        'created_at': 'When the invoice was created',
        'updated_at': 'When it last changed',
    },
    'invoice_items': {
        'id': 'Line number',
        'invoice_id': 'Invoice (links to invoices)',
        'source': 'Where the charge came from: Consultation, Laboratory, Pharmacy or Service',
        'source_id': 'Record that caused the charge; with source and invoice it is unique, so a service is never charged twice',
        'description': 'Text shown on the invoice',
        'quantity': 'Number of units, 1 or more',
        'unit_price': 'Price of one unit when charged',
        'line_total': 'Quantity times unit price, rounded to cents',
    },
    'payments': {
        'id': 'Payment number; the receipt number RCT-yyyy-nnnnnn is built from it',
        'invoice_id': 'Invoice paid (links to invoices)',
        'amount': 'Amount received, above 0',
        'method': 'Cash, M-Pesa, Card or Insurance',
        'reference': 'M-Pesa confirmation code, card approval code or insurance claim number; empty for cash',
        'received_by': 'Cashier who took the payment (links to users)',
        'paid_at': 'When the payment was taken',
    },
    'audit_log': {
        'id': 'Entry number',
        'user_id': 'Person who did it; empty for a failed sign-in by an unknown name (links to users)',
        'action': 'What was done, for example create, update, view, login_failed, payment, dispense',
        'entity': 'Kind of record affected, for example patient or invoice',
        'entity_id': 'Number of the record affected',
        'details': 'Short note without clinical or personal details, up to 500 characters',
        'ip_address': 'Address of the computer used',
        'created_at': 'Date and time of the action',
    },
}


def type_words(col):
    """Return (type label, width text) for a column from information_schema."""
    t = col['type'].lower()
    unsigned = ' (no minus)' if 'unsigned' in t else ''
    if t.startswith('enum'):
        return 'Choice', t[5:-1].replace("'", '').replace(',', ' / ')
    if t.startswith(('int', 'smallint', 'tinyint', 'bigint', 'mediumint')):
        base = t.split('(')[0]
        if t.startswith('tinyint(1)'):
            return 'Yes/No (1 or 0)', '1'
        width = t[t.index('(') + 1:t.index(')')] if '(' in t else ''
        label = {'tinyint': 'Whole number', 'smallint': 'Whole number', 'mediumint': 'Whole number', 'int': 'Whole number', 'bigint': 'Whole number'}[base]
        return label + unsigned, width
    if t.startswith('decimal'):
        return 'Decimal', t[8:-1]
    if t.startswith('varchar') or t.startswith('char'):
        return 'Text', t[t.index('(') + 1:t.index(')')]
    if t == 'text':
        return 'Long text', '65,535'
    if t == 'datetime':
        return 'Date and time', 'yyyy-mm-dd hh:mm:ss'
    if t == 'date':
        return 'Date', 'yyyy-mm-dd'
    if t == 'time':
        return 'Time', 'hh:mm:ss'
    return t, ''


def key_words(table, col, pk_cols, fks):
    parts = []
    if col['name'] in pk_cols:
        parts.append('PK')
    if any(f['col'] == col['name'] for f in fks):
        parts.append('FK')
    if col['ckey'] == 'UNI' and col['name'] not in pk_cols:
        parts.append('Unique')
    return ', '.join(parts)
