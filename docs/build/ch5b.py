"""Chapter 5, part 2: file and database design (5.5)."""
import json
import os

from doc_common import fig, HERE, landscape_figure
import data_dictionary as dd

SCHEMA = json.load(open(os.path.join(HERE, 'schema.json'), encoding='utf-8'))
TABLES = SCHEMA['tables']

# Volume assumptions: about 100 outpatient visits a working day, 300 working days a year (to be confirmed by the hospital)
VISITS_PER_DAY, DAYS = 100, 300
YEARLY = {
    'roles': 0, 'users': 5, 'op_sequences': 1, 'patients': 8000, 'appointments': 12000, 'icd10_codes': 20, 'services': 3, 'visits': 30000,
    'triage': 27000, 'consultations': 28000, 'visit_services': 6000, 'lab_tests': 2, 'lab_requests': 15000, 'medicines': 20, 'medicine_batches': 600,
    'prescriptions': 20000, 'prescription_items': 45000, 'stock_movements': 60000, 'invoices': 30000, 'invoice_items': 100000, 'payments': 33000, 'audit_log': 300000,
}
# Bytes per row including indexes: measured on 20,000 patients / 30,000 visits (docs/evidence), else estimated from the row layout
BYTES = {
    'patients': 297, 'visits': 235, 'consultations': 219, 'invoices': 184, 'invoice_items': 123, 'payments': 136, 'lab_requests': 249, 'audit_log': 251,
    'appointments': 220, 'triage': 130, 'visit_services': 60, 'prescriptions': 70, 'prescription_items': 90, 'stock_movements': 100, 'medicine_batches': 110,
    'users': 300, 'medicines': 120, 'services': 100, 'lab_tests': 150, 'icd10_codes': 100, 'roles': 100, 'op_sequences': 16,
}
MEASURED = {'patients', 'visits', 'consultations', 'invoices', 'invoice_items', 'payments', 'lab_requests', 'audit_log'}
WRITE_TEXT = {
    'roles': 'Never changed after installation.',
    'users': 'A few new accounts a year; one update at every sign-in.',
    'op_sequences': 'One update for every patient registered.',
    'patients': 'About 27 new patients a day; corrections are rare.',
    'appointments': 'About 40 bookings a day; each is updated once (attended, missed or cancelled).',
    'icd10_codes': 'Almost never changed.',
    'services': 'Prices changed a few times a year.',
    'visits': 'About 100 a day; each is updated twice (stage, then closed).',
    'triage': 'About 90 a day; corrections are rare.',
    'consultations': 'About 93 a day; corrections are rare.',
    'visit_services': 'About 20 a day.',
    'lab_tests': 'Prices and tests changed a few times a year.',
    'lab_requests': 'About 50 a day; each is updated two or three times (in progress, result).',
    'medicines': 'New medicines and price changes a few times a month.',
    'medicine_batches': 'A few deliveries a week; the quantity is updated at every dispensing.',
    'prescriptions': 'About 67 a day; each is updated once or twice.',
    'prescription_items': 'About 150 a day; each is updated when dispensed.',
    'stock_movements': 'About 200 a day; added only, never changed.',
    'invoices': 'About 100 a day; each is updated after every charge and payment.',
    'invoice_items': 'About 330 a day; added, updated or removed while the visit is open.',
    'payments': 'About 110 a day; added only, never changed.',
    'audit_log': 'About 1,000 a day; added only, never changed.',
}


def fmt_bytes(n):
    if n >= 1024 ** 3:
        return f'{n / 1024 ** 3:.1f} GB'
    if n >= 1024 ** 2:
        return f'{n / 1024 ** 2:.1f} MB'
    return f'{n / 1024:.0f} KB'


def build_database(d):
    d.h2('5.5 File and Database Design')
    d.p('All the data of UB-HMS is held in one relational database managed by MariaDB (Codd, 1970; Elmasri & Navathe, 2016). This section gives the logical design, the physical description, the access methods, the size and update estimates, and the data dictionary. '
        'The tables were designed first as entities and relationships, then normalised, then created by one script (database/schema.sql). The figures and tables in this section were produced from the live database, so they match the system exactly.')

    landscape_figure(d, '5.5.1 Logical Model',
        '{ref:fig_erd0} is the conceptual entity relationship diagram (ERD) of the whole system (Chen, 1976). An entity is a kind of record, drawn as a box, and a line between two boxes shows how they are related. '
        'The marks at the end of a line show how many records can be on that side: a single bar means one, and a three-pronged mark means many. For example, one patient has many visits, and one visit has at most one consultation and one invoice.',
        fig('erd_conceptual'), 'Conceptual entity relationship diagram of UB-HMS', 'fig_erd0')
    d.p('The main relationships are:')
    d.bullets([
        'A **patient** has many **appointments** and many **visits**; a visit may come from one appointment, or none for a walk-in.',
        'A **visit** has at most one **triage** record, at most one **consultation**, many **procedures** (visit services), many **laboratory requests**, many **prescriptions** and exactly one **invoice**.',
        'A **laboratory request** is for one **test** and is made by one doctor (a **user**), who is the only one who receives the result.',
        'A **prescription** has many **prescription items**, each for one **medicine**; a medicine is held in many **batches**, and every change to a batch is a **stock movement**.',
        'An **invoice** has many **invoice items** and many **payments**.',
        'A **user** has one **role**, and every action in the **audit trail** belongs to a user (or to a failed sign-in by an unknown name).',
    ])
    d.p('The detail of each module is shown in {ref:fig_erd1} to {ref:fig_erd6}. These diagrams list the fields of each table, mark the primary key (PK) and foreign keys (FK), and show the relationships in the form used by the database.')
    names = {1: ('erd_m1', 'Module 1: patients, appointments and numbering'), 2: ('erd_m2', 'Module 2: visits and clinical records'),
             3: ('erd_m3', 'Module 3: laboratory'), 4: ('erd_m4', 'Module 4: pharmacy and stock'), 5: ('erd_m5', 'Module 5: invoices and payments'),
             6: ('erd_m6', 'Module 6: users, roles and the audit trail')}
    for m in range(1, 7):
        key, title = names[m]
        d.figure(fig(key), f'Entity relationship diagram, {title}', label=f'fig_erd{m}', max_height_cm=16)
    d.p('{ref:tab_fk} lists every relationship as the database stores it. No relationship deletes records automatically: the rule for all of them is RESTRICT (a record that is referred to cannot be deleted), which protects the clinical and financial history.')
    rows = []
    for tname, t in TABLES.items():
        for fk in t['foreign_keys']:
            rows.append([tname, fk['col'], f'{fk["ref_table"]} ({fk["ref_col"]})', fk['delete_rule']])
    d.table(['Table', 'Foreign key column', 'Refers to', 'On delete'], rows, widths=[3.8, 4.2, 4.8, 2.5], size=8.5, caption='Relationships (Foreign Keys) in the Database', label='tab_fk')

    d.h3('5.5.2 Normalisation')
    d.p('Normalisation arranges data so that each fact is stored once, which prevents the errors that come from changing a fact in one place and not in another (Elmasri & Navathe, 2016). The design was taken through three steps.')
    d.bullets([
        '**First normal form (1NF): every field holds one value.** Names are split into first, middle and last name; blood pressure is two fields; a prescription\'s medicines are separate lines (prescription_items), not a list in one field; an invoice\'s charges are separate lines (invoice_items).',
        '**Second normal form (2NF): every field depends on the whole key.** Every table has a single-field primary key, so this holds by design. The lines of a document (prescription_items, invoice_items) are kept in their own tables so that facts about a line, such as the medicine and the quantity, do not depend on part of another record.',
        '**Third normal form (3NF): no field depends on another non-key field.** The doctor\'s name is not stored on a visit, only the doctor\'s number; the price of a medicine is on the medicine, not on every prescription; the role name is on roles, not on users; the meaning of an ICD-10 code is on icd10_codes.',
    ])
    d.p('{ref:tab_norm} shows the main step from the paper way of working to the design. The "paper visit slip" repeats the same patient details and the same prices on every slip, which is the source of many of the hospital\'s errors.')
    d.table(['On the paper slip', 'Problem', 'In UB-HMS'], [
        ['Patient name, age, address written on every slip, register and request form', 'Written many times, differently each time; a mistake in one place is not corrected in the others', 'Stored once in patients; every other record holds the patient number'],
        ['Doctor\'s name and department on each slip', 'Spelling differs; a doctor who leaves must be changed everywhere', 'Doctors are rows of users with a role; records hold the user number'],
        ['List of medicines on one prescription form', 'No way to count what was given or to total it', 'prescriptions (one per visit) and prescription_items (one per medicine)'],
        ['List of charges on a bill', 'A charge may be missed or counted twice', 'invoices and invoice_items; each line points to the record that caused it'],
        ['Price written beside each service', 'Prices differ between slips', 'Price list in services, lab_tests and medicines; the price at the time is copied onto the invoice line'],
        ['Stock balance on a card', 'Balance and receipts disagree', 'Balance kept in medicine_batches; every change recorded in stock_movements'],
    ], widths=[4.4, 5.4, 5.5], caption='From Paper Records to Normalised Tables', label='tab_norm', size=9)
    d.p('All 22 tables are in third normal form. Four values are stored on purpose although they could be calculated, and each is protected so that it cannot drift from the truth:')
    d.table(['Stored value', 'Why it is stored', 'How it is protected'], [
        ['invoice_items.unit_price and description', 'The price agreed on the day must not change when the price list changes later', 'Updated only by the invoice routine; once a payment is taken, only the quantity can change'],
        ['invoices.total and amount_paid', 'Lists and reports need them quickly, without adding up lines for every invoice', 'Recalculated inside one transaction with every change; the health page compares them with the lines and payments'],
        ['medicine_batches.quantity', 'The pharmacist needs the balance at once', 'Changed only together with a stock_movements entry; the health page compares the two; a CHECK stops it going below zero'],
        ['appointments.slot_key', 'A unique key cannot be built across three columns while the status is ignored', 'A generated column that is filled only while the appointment holds its slot; the database creates it, nobody types it'],
    ], widths=[4.3, 5.3, 5.7], caption='Deliberate Stored Values', label='tab_denorm', size=9)

    d.h3('5.5.3 Physical Description')
    d.p('The physical design is the form in which the logical design is created in MariaDB. {ref:tab_phys} gives the main decisions, and {ref:tab_tables} describes each table.')
    d.table(['Item', 'Decision'], [
        ['DBMS', 'MariaDB 10.4.32 (bundled with XAMPP 8.2.12). The schema uses only standard features (InnoDB, foreign keys, CHECK constraints and one stored generated column); only this version has been tested.'],
        ['Database name', 'uhai_bora_hms (one database for the whole system)'],
        ['Storage engine', 'InnoDB for every table: it supports transactions, row locks and foreign keys, and recovers after a crash.'],
        ['Character set and collation', 'utf8mb4 with utf8mb4_unicode_ci, so that all names, including those with accents, are stored and sorted correctly.'],
        ['Page size', '16 KB InnoDB pages (the default); a table is stored as a tree ordered by its primary key, with each secondary index as a separate tree.'],
        ['Primary keys', 'An automatic whole-number key on every table, except op_sequences (the year) and icd10_codes (the code), which have natural keys.'],
        ['Data types', 'Whole numbers for keys and counts; DECIMAL(10,2) and DECIMAL(12,2) for money; DATE, TIME and DATETIME for times; VARCHAR for text of known length; TEXT for clinical notes; ENUM for fixed lists such as status.'],
        ['Rules in the database', 'Foreign keys on every link; unique keys (OP number, ID number, username, invoice number, one invoice per visit, appointment slot, batch per medicine); CHECK constraints (no negative price, stock, or payment of zero).'],
        ['Accounts', 'The application should connect as ubhms_app, which can read and change the data tables but can only add rows to audit_log, payments and stock_movements, and cannot change the structure.'],
        ['Time zone', 'The connection sets +03:00 (East Africa Time) so that database time agrees with the hospital\'s clock.'],
    ], widths=[3.4, 11.9], caption='Physical Design Decisions', label='tab_phys', size=9, align=['l', 'l'])
    rows = []
    for tname, t in TABLES.items():
        pk = [c['name'] for c in t['columns'] if c['ckey'] == 'PRI']
        uniq = sum(1 for i in t['indexes'] if i['unique'] and i['name'] != 'PRIMARY')
        rows.append([tname, str(len(t['columns'])), ', '.join(pk), str(uniq), str(len(t['foreign_keys'])), str(len(t['indexes'])), dd.TABLE_PURPOSE[tname]])
    tot_cols = sum(int(r[1]) for r in rows)
    rows.append(['Total (22 tables)', str(tot_cols), '', '', str(sum(int(r[4]) for r in rows)), str(sum(int(r[5]) for r in rows)), ''])
    d.table(['Table', 'Fields', 'Primary key', 'Unique keys', 'Foreign keys', 'Indexes', 'Purpose'], rows, widths=[2.9, 1.1, 2.0, 1.2, 1.2, 1.3, 5.6], size=8, caption='The 22 Tables of the Database', label='tab_tables', align=['l', 'c', 'l', 'c', 'c', 'c', 'l'])

    d.h3('5.5.4 Access Methods')
    d.p('The database finds rows by index, not by reading whole tables, so the system stays fast as the records grow. Primary keys are clustered: the table is stored in key order, so a lookup by key reads one page. Other indexes are separate trees that point back to the key. '
        'Every foreign key column is indexed (this is checked by test UT-INT-006). {ref:tab_access} lists the common operations and how each is served.')
    d.table(['Operation', 'Table and index', 'Access method'], [
        ['Find a patient by OP number or ID number', 'patients: unique indexes uq_patients_op, uq_patients_national_id', 'Random access by unique key'],
        ['Find patients by name or phone', 'patients: ix_patients_name (last, first name), ix_patients_phone', 'Indexed range scan; text matched inside the result'],
        ['Show a doctor\'s diary for a day', 'appointments: ix_appt_doctor_day (doctor, date)', 'Indexed range scan, sorted by time'],
        ['Refuse a double booking', 'appointments: unique key on slot_key', 'Unique-key check at the moment of saving'],
        ['Show queues of open visits', 'visits: ix_visits_status_date (status, date)', 'Indexed range scan on status'],
        ['Laboratory worklist; a doctor\'s results', 'lab_requests: ix_lab_status; ix_lab_requested_by (doctor, status)', 'Indexed scan on status or on doctor and status'],
        ['Pick the batch that expires first', 'medicine_batches: uq_batch (medicine, batch); ix_batch_expiry', 'Index on the medicine, batches ordered by expiry date'],
        ['Pharmacy queue', 'prescriptions: ix_rx_status', 'Indexed scan on status'],
        ['Find an invoice by number, visit or status', 'invoices: uq_invoice_no, uq_invoice_visit, ix_invoice_status', 'Random access by unique key; indexed scan'],
        ['Charge each service once', 'invoice_items: unique (invoice, source, source id)', 'Unique-key check when adding a line'],
        ['Payments of a day', 'payments: ix_pay_paid_at', 'Indexed range scan on the date'],
        ['Audit trail by record, by date, by sign-in failures', 'audit_log: ix_audit_entity, ix_audit_created, ix_audit_action_ip', 'Indexed range scans; the last one serves the lockout of a computer'],
        ['Reports for a period', 'appointments, visits, invoices, payments, lab_requests and stock_movements by date', 'Indexed range scan on the date, then totals in the database'],
        ['CSV export, backup', 'All rows of the table or database', 'Sequential read'],
    ], widths=[4.6, 6.4, 4.3], caption='Common Operations and the Access Method for Each', label='tab_access', size=8.5)

    d.h3('5.5.5 Estimated Size of the Database')
    d.p('The size depends on how many patients the hospital sees. The estimate assumes about 100 outpatient visits on each working day and 300 working days a year, which is 30,000 visits a year. That number is an assumption; the hospital should replace it with its own figure. '
        'The bytes for each row include its indexes. For the eight biggest tables they were measured on a database holding 20,000 patients and 30,000 visits of synthetic data (62.5 MB in all, see Chapter 7); the others are estimated from their row layout and marked with an asterisk.')
    rows = []
    total_year = 0
    for tname in TABLES:
        n = YEARLY[tname]
        b = BYTES[tname]
        size = n * b
        total_year += size
        rows.append([tname, f'{n:,}', f'{b}' + ('' if tname in MEASURED else ' *'), fmt_bytes(size)])
    rows.append(['Total for one year', '', '', fmt_bytes(total_year)])
    three = total_year * 3
    rows.append(['Three years, with 30% allowance for free space and page overhead', '', '', fmt_bytes(three * 1.3)])
    d.table(['Table', 'New rows a year', 'Bytes per row', 'Size added in a year'], rows, widths=[6.4, 3.0, 2.6, 3.3], size=8.5, caption='Estimated Growth of the Database', label='tab_size', align=['l', 'r', 'r', 'r'])
    d.p(f'The result is about {fmt_bytes(total_year)} a year, or about {fmt_bytes(three * 1.3)} after three years. The audit trail is the largest table because every sign-in, view and change is recorded. '
        'This is small beside the 128 GB disk of the minimum server, so storage is not a constraint. A nightly backup of the large test database (62.5 MB of data) took 3.3 seconds and made a file of 3.1 MB (Chapter 7).')

    d.h3('5.5.6 Update Frequency')
    d.p('{ref:tab_update} states how often each table changes. Most tables are written to many times a day; the standard tables almost never change. Over a normal day the system makes about 2,400 new rows and about 1,500 updates, which is fewer than ten changes a minute over a nine-hour day, a light load for MariaDB.')
    d.table(['Table', 'Kind', 'How often it changes'], [
        [t, 'Standard table' if t in ('roles', 'icd10_codes', 'services', 'lab_tests') else ('Append-only' if t in ('audit_log', 'payments', 'stock_movements') else 'Working table'), WRITE_TEXT[t]] for t in TABLES
    ], widths=[3.4, 2.6, 9.3], size=8.5, caption='Update Frequency of the Tables', label='tab_update')

    build_dictionary(d)


def build_dictionary(d):
    d.h3('5.5.7 Data Dictionary')
    d.p('The data dictionary describes every field of every table: its meaning, type, width and the values it accepts (Elmasri & Navathe, 2016). It was generated from the live database, and the build refuses to run if any field has no description, so the dictionary cannot fall behind the database. '
        'In the Key column, PK is the primary key, FK a foreign key and Unique a field whose value cannot repeat. In the Type column, "Yes/No" means 1 or 0. Fields that may be empty are marked "May be empty" in the notes.')
    for i, (tname, t) in enumerate(TABLES.items(), 1):
        d.h4(f'5.5.7.{i} {tname}')
        d.p(dd.TABLE_PURPOSE[tname], keep_next=True)
        pk = [c['name'] for c in t['columns'] if c['ckey'] == 'PRI']
        rows = []
        for c in t['columns']:
            label, width = dd.type_words(c)
            note = dd.D[tname][c['name']]
            if c['nullable'] == 'YES' and 'optional' not in note.lower() and 'empty' not in note.lower():
                note += ' (may be empty)'
            rows.append([c['name'], label, width, dd.key_words(tname, c, pk, t['foreign_keys']), note])
        d.table(['Field', 'Type', 'Width', 'Key', 'Meaning and accepted values'], rows, widths=[2.9, 2.2, 2.2, 1.4, 6.6], size=8, caption=f'Data Dictionary: {tname}', label='dd_' + tname, align=['l', 'l', 'l', 'l', 'l'])
