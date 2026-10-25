-- Uhai Bora Hospital Management System (UB-HMS) - database schema
--
-- Covers all six modules:
--   1 Patient registration and appointments   2 Clinical consultation
--   3 Laboratory management                    4 Pharmacy and inventory
--   5 Billing and reports                      6 User management and security
--
-- Tested on MariaDB 10.4.32 (the version bundled with XAMPP 8.2.12). It uses
-- only standard features (InnoDB, foreign keys, CHECK constraints and a stored
-- generated column) that current MariaDB and MySQL 8 also provide, but only
-- MariaDB 10.4.32 has been tested. It creates tables and standard reference
-- data only. Create an empty database first, or run `php database/install.php`,
-- which does both.
--
-- WARNING: this script drops and recreates every UB-HMS table.
-- Never run it against a database that holds real patient information.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS public_requests, audit_log, payments, invoice_items, invoices, stock_movements,
    prescription_items, prescriptions, medicine_batches, medicines, lab_requests, lab_tests,
    visit_services, consultations, triage, visits, appointments, patients, op_sequences,
    users, roles, icd10_codes, services;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- Module 6: users and security
-- ---------------------------------------------------------------------------

CREATE TABLE roles (
    id          TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(30)  NOT NULL,
    name        VARCHAR(60)  NOT NULL,
    description VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name            VARCHAR(120) NOT NULL,
    username             VARCHAR(40)  NOT NULL,
    password_hash        VARCHAR(255) NOT NULL,
    role_id              TINYINT UNSIGNED NOT NULL,
    phone                VARCHAR(15)  NULL,
    is_active            TINYINT(1)   NOT NULL DEFAULT 1,
    must_change_password TINYINT(1)   NOT NULL DEFAULT 1,
    failed_attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until         DATETIME     NULL,
    last_login_at        DATETIME     NULL,
    created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    KEY fk_users_role (role_id),
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Requests and messages sent from the public pages: a person asking for a staff account (kind 'access') or
-- sending a message from the contact page (kind 'contact'). An access request never creates an account;
-- the System Administrator reviews it and creates the account by hand.
CREATE TABLE public_requests (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    kind           ENUM('access','contact') NOT NULL,
    reference      VARCHAR(20)  NULL,
    full_name      VARCHAR(120) NOT NULL,
    email          VARCHAR(160) NOT NULL,
    phone          VARCHAR(15)  NULL,
    department     VARCHAR(80)  NULL,
    requested_role VARCHAR(30)  NULL,
    staff_number   VARCHAR(40)  NULL,
    supervisor     VARCHAR(120) NULL,
    topic          VARCHAR(40)  NULL,
    message        TEXT         NULL,
    status         ENUM('new','in_progress','done','declined') NOT NULL DEFAULT 'new',
    admin_note     VARCHAR(255) NULL,
    handled_by     INT UNSIGNED NULL,
    handled_at     DATETIME     NULL,
    ip_address     VARCHAR(45)  NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pr_reference (reference),
    KEY ix_pr_kind_status (kind, status, created_at),
    KEY ix_pr_ip (ip_address, kind, created_at),
    KEY ix_pr_email (email, kind, status),
    KEY fk_pr_handler (handled_by),
    CONSTRAINT fk_pr_handler FOREIGN KEY (handled_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Module 1: patient registration and appointments
-- ---------------------------------------------------------------------------

-- One row per year; last_number is incremented atomically to issue outpatient numbers.
CREATE TABLE op_sequences (
    year        SMALLINT UNSIGNED NOT NULL,
    last_number INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE patients (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    op_number            VARCHAR(20)  NOT NULL,
    first_name           VARCHAR(50)  NOT NULL,
    middle_name          VARCHAR(50)  NULL,
    last_name            VARCHAR(50)  NOT NULL,
    sex                  ENUM('Female','Male') NOT NULL,
    date_of_birth        DATE         NOT NULL,
    dob_estimated        TINYINT(1)   NOT NULL DEFAULT 0,
    national_id          VARCHAR(20)  NULL,
    phone                VARCHAR(15)  NULL,
    residence            VARCHAR(100) NULL,
    allergies            VARCHAR(255) NULL,
    next_of_kin_name     VARCHAR(100) NULL,
    next_of_kin_phone    VARCHAR(15)  NULL,
    next_of_kin_relation VARCHAR(40)  NULL,
    registered_by        INT UNSIGNED NOT NULL,
    created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_patients_op (op_number),
    UNIQUE KEY uq_patients_national_id (national_id),
    KEY ix_patients_name (last_name, first_name),
    KEY ix_patients_phone (phone),
    KEY fk_patients_user (registered_by),
    CONSTRAINT fk_patients_user FOREIGN KEY (registered_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- slot_key is filled only while an appointment holds its slot (Booked or Attended).
-- Its UNIQUE index makes a double booking of one doctor at one time impossible,
-- even if two receptionists save at the same instant.
CREATE TABLE appointments (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_id       INT UNSIGNED NOT NULL,
    doctor_id        INT UNSIGNED NOT NULL,
    appointment_date DATE NOT NULL,
    start_time       TIME NOT NULL,
    end_time         TIME NOT NULL,
    reason           VARCHAR(255) NULL,
    status           ENUM('Booked','Attended','Missed','Cancelled') NOT NULL DEFAULT 'Booked',
    cancel_reason    VARCHAR(255) NULL,
    booked_by        INT UNSIGNED NOT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    slot_key         VARCHAR(40) GENERATED ALWAYS AS (
                         IF(status IN ('Booked','Attended'),
                            CONCAT(doctor_id, '|', appointment_date, '|', start_time), NULL)
                     ) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_appt_slot (slot_key),
    KEY ix_appt_doctor_day (doctor_id, appointment_date),
    KEY ix_appt_patient_day (patient_id, appointment_date),
    KEY ix_appt_day_status (appointment_date, status),
    KEY fk_appt_user (booked_by),
    CONSTRAINT fk_appt_doctor  FOREIGN KEY (doctor_id)  REFERENCES users (id),
    CONSTRAINT fk_appt_patient FOREIGN KEY (patient_id) REFERENCES patients (id),
    CONSTRAINT fk_appt_user    FOREIGN KEY (booked_by)  REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Module 2: clinical consultation
-- ---------------------------------------------------------------------------

-- Standard tables used to validate coded fields
CREATE TABLE icd10_codes (
    code        VARCHAR(10)  NOT NULL,
    description VARCHAR(200) NOT NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE services (
    id        SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name      VARCHAR(100) NOT NULL,
    category  ENUM('Consultation','Procedure','Other') NOT NULL DEFAULT 'Other',
    price     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_services_name (name),
    CONSTRAINT chk_services_price CHECK (price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE visits (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_id     INT UNSIGNED NOT NULL,
    appointment_id INT UNSIGNED NULL,
    doctor_id      INT UNSIGNED NULL,
    reason         VARCHAR(255) NULL,
    visit_date     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status         ENUM('Open','Closed') NOT NULL DEFAULT 'Open',
    opened_by      INT UNSIGNED NOT NULL,
    closed_at      DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_visits_appointment (appointment_id),
    KEY ix_visits_patient (patient_id),
    KEY ix_visits_status_date (status, visit_date),
    KEY fk_visits_doctor (doctor_id),
    KEY fk_visits_user (opened_by),
    CONSTRAINT fk_visits_appt    FOREIGN KEY (appointment_id) REFERENCES appointments (id),
    CONSTRAINT fk_visits_doctor  FOREIGN KEY (doctor_id)      REFERENCES users (id),
    CONSTRAINT fk_visits_patient FOREIGN KEY (patient_id)     REFERENCES patients (id),
    CONSTRAINT fk_visits_user    FOREIGN KEY (opened_by)      REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE triage (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    visit_id     INT UNSIGNED NOT NULL,
    temperature  DECIMAL(4,1) NULL,
    pulse        SMALLINT UNSIGNED NULL,
    bp_systolic  SMALLINT UNSIGNED NULL,
    bp_diastolic SMALLINT UNSIGNED NULL,
    resp_rate    SMALLINT UNSIGNED NULL,
    spo2         TINYINT UNSIGNED NULL,
    weight_kg    DECIMAL(5,1) NULL,
    height_cm    DECIMAL(5,1) NULL,
    notes        VARCHAR(500) NULL,
    recorded_by  INT UNSIGNED NOT NULL,
    recorded_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_triage_visit (visit_id),
    KEY fk_triage_user (recorded_by),
    CONSTRAINT fk_triage_user  FOREIGN KEY (recorded_by) REFERENCES users (id),
    CONSTRAINT fk_triage_visit FOREIGN KEY (visit_id)    REFERENCES visits (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE consultations (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    visit_id    INT UNSIGNED NOT NULL,
    doctor_id   INT UNSIGNED NOT NULL,
    service_id  SMALLINT UNSIGNED NULL,
    complaint   TEXT NULL,
    examination TEXT NULL,
    diagnosis   VARCHAR(255) NULL,
    icd10_code  VARCHAR(10) NULL,
    plan        TEXT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_consult_visit (visit_id),
    KEY fk_consult_doctor (doctor_id),
    KEY fk_consult_service (service_id),
    KEY fk_consult_icd (icd10_code),
    CONSTRAINT fk_consult_doctor  FOREIGN KEY (doctor_id)  REFERENCES users (id),
    CONSTRAINT fk_consult_icd     FOREIGN KEY (icd10_code) REFERENCES icd10_codes (code),
    CONSTRAINT fk_consult_service FOREIGN KEY (service_id) REFERENCES services (id),
    CONSTRAINT fk_consult_visit   FOREIGN KEY (visit_id)   REFERENCES visits (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Procedures and other services recorded during a visit; each becomes an invoice line.
CREATE TABLE visit_services (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    visit_id    INT UNSIGNED NOT NULL,
    service_id  SMALLINT UNSIGNED NOT NULL,
    quantity    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    recorded_by INT UNSIGNED NOT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY fk_vs_visit (visit_id),
    KEY fk_vs_service (service_id),
    KEY fk_vs_user (recorded_by),
    CONSTRAINT fk_vs_service FOREIGN KEY (service_id)  REFERENCES services (id),
    CONSTRAINT fk_vs_user    FOREIGN KEY (recorded_by) REFERENCES users (id),
    CONSTRAINT fk_vs_visit   FOREIGN KEY (visit_id)    REFERENCES visits (id),
    CONSTRAINT chk_vs_qty CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Module 3: laboratory management
-- ---------------------------------------------------------------------------

CREATE TABLE lab_tests (
    id              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(100) NOT NULL,
    reference_range VARCHAR(100) NULL,
    price           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_lab_tests_name (name),
    CONSTRAINT chk_lab_tests_price CHECK (price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- requested_by is the doctor who ordered the test; results return to that doctor.
CREATE TABLE lab_requests (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    visit_id       INT UNSIGNED NOT NULL,
    test_id        SMALLINT UNSIGNED NOT NULL,
    requested_by   INT UNSIGNED NOT NULL,
    clinical_notes VARCHAR(255) NULL,
    requested_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status         ENUM('Requested','In progress','Completed','Cancelled') NOT NULL DEFAULT 'Requested',
    result         TEXT NULL,
    is_abnormal    TINYINT(1) NULL,
    result_by      INT UNSIGNED NULL,
    result_at      DATETIME NULL,
    PRIMARY KEY (id),
    KEY ix_lab_status (status),
    KEY ix_lab_requested_by (requested_by, status),
    KEY fk_lab_visit (visit_id),
    KEY fk_lab_test (test_id),
    KEY fk_lab_res_by (result_by),
    CONSTRAINT fk_lab_req_by FOREIGN KEY (requested_by) REFERENCES users (id),
    CONSTRAINT fk_lab_res_by FOREIGN KEY (result_by)    REFERENCES users (id),
    CONSTRAINT fk_lab_test   FOREIGN KEY (test_id)      REFERENCES lab_tests (id),
    CONSTRAINT fk_lab_visit  FOREIGN KEY (visit_id)     REFERENCES visits (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Module 4: pharmacy and inventory
-- ---------------------------------------------------------------------------

CREATE TABLE medicines (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(100) NOT NULL,
    form          VARCHAR(30)  NOT NULL DEFAULT '',
    strength      VARCHAR(30)  NOT NULL DEFAULT '',
    unit          VARCHAR(20)  NOT NULL DEFAULT 'unit',
    unit_price    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    reorder_level INT UNSIGNED NOT NULL DEFAULT 0,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_medicine (name, form, strength),
    CONSTRAINT chk_medicines_price CHECK (unit_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- quantity is the balance still on the shelf for this batch.
CREATE TABLE medicine_batches (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    medicine_id INT UNSIGNED NOT NULL,
    batch_no    VARCHAR(40) NOT NULL,
    expiry_date DATE NOT NULL,
    quantity    INT NOT NULL DEFAULT 0,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_batch (medicine_id, batch_no),
    KEY ix_batch_expiry (expiry_date),
    CONSTRAINT fk_batch_medicine FOREIGN KEY (medicine_id) REFERENCES medicines (id),
    CONSTRAINT chk_batch_quantity CHECK (quantity >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE prescriptions (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    visit_id      INT UNSIGNED NOT NULL,
    prescribed_by INT UNSIGNED NOT NULL,
    status        ENUM('Pending','Partially dispensed','Dispensed') NOT NULL DEFAULT 'Pending',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_rx_status (status),
    KEY fk_rx_visit (visit_id),
    KEY fk_rx_user (prescribed_by),
    CONSTRAINT fk_rx_user  FOREIGN KEY (prescribed_by) REFERENCES users (id),
    CONSTRAINT fk_rx_visit FOREIGN KEY (visit_id)      REFERENCES visits (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE prescription_items (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    prescription_id INT UNSIGNED NOT NULL,
    medicine_id     INT UNSIGNED NOT NULL,
    dosage          VARCHAR(60) NULL,
    frequency       VARCHAR(60) NULL,
    duration        VARCHAR(60) NULL,
    quantity        INT UNSIGNED NOT NULL,
    dispensed_qty   INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY fk_rxi_rx (prescription_id),
    KEY fk_rxi_medicine (medicine_id),
    CONSTRAINT fk_rxi_medicine FOREIGN KEY (medicine_id)     REFERENCES medicines (id),
    CONSTRAINT fk_rxi_rx       FOREIGN KEY (prescription_id) REFERENCES prescriptions (id),
    CONSTRAINT chk_rxi_qty CHECK (quantity > 0 AND dispensed_qty <= quantity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ledger of every change to stock; quantity is negative for dispensing and write-offs.
CREATE TABLE stock_movements (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    medicine_id INT UNSIGNED NOT NULL,
    batch_id    INT UNSIGNED NULL,
    type        ENUM('Receive','Dispense','Adjust') NOT NULL,
    quantity    INT NOT NULL,
    reference   VARCHAR(60) NULL,
    user_id     INT UNSIGNED NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_stock_created (created_at),
    KEY fk_stock_medicine (medicine_id),
    KEY fk_stock_batch (batch_id),
    KEY fk_stock_user (user_id),
    CONSTRAINT fk_stock_batch    FOREIGN KEY (batch_id)    REFERENCES medicine_batches (id),
    CONSTRAINT fk_stock_medicine FOREIGN KEY (medicine_id) REFERENCES medicines (id),
    CONSTRAINT fk_stock_user     FOREIGN KEY (user_id)     REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Module 5: billing
-- ---------------------------------------------------------------------------

CREATE TABLE invoices (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    invoice_no  VARCHAR(20) NOT NULL,
    visit_id    INT UNSIGNED NOT NULL,
    total       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status      ENUM('Unpaid','Partially paid','Paid') NOT NULL DEFAULT 'Unpaid',
    created_by  INT UNSIGNED NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_invoice_no (invoice_no),
    UNIQUE KEY uq_invoice_visit (visit_id),
    KEY ix_invoice_status (status),
    KEY fk_inv_user (created_by),
    CONSTRAINT fk_inv_user  FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_inv_visit FOREIGN KEY (visit_id)   REFERENCES visits (id),
    CONSTRAINT chk_inv_amounts CHECK (total >= 0 AND amount_paid >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- source + source_id point back to the record that caused the charge, and the
-- UNIQUE key stops the same service from ever being charged twice.
CREATE TABLE invoice_items (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    invoice_id  INT UNSIGNED NOT NULL,
    source      ENUM('Consultation','Laboratory','Pharmacy','Service') NOT NULL,
    source_id   INT UNSIGNED NOT NULL,
    description VARCHAR(150) NOT NULL,
    quantity    INT UNSIGNED NOT NULL DEFAULT 1,
    unit_price  DECIMAL(10,2) NOT NULL,
    line_total  DECIMAL(12,2) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_invoice_source (invoice_id, source, source_id),
    CONSTRAINT fk_invi_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id),
    CONSTRAINT chk_invi_amounts CHECK (quantity > 0 AND unit_price >= 0 AND line_total >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    invoice_id  INT UNSIGNED NOT NULL,
    amount      DECIMAL(12,2) NOT NULL,
    method      ENUM('Cash','M-Pesa','Card','Insurance') NOT NULL,
    reference   VARCHAR(40) NULL,
    received_by INT UNSIGNED NOT NULL,
    paid_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_pay_paid_at (paid_at),
    KEY fk_pay_invoice (invoice_id),
    KEY fk_pay_user (received_by),
    CONSTRAINT fk_pay_invoice FOREIGN KEY (invoice_id)  REFERENCES invoices (id),
    CONSTRAINT fk_pay_user    FOREIGN KEY (received_by) REFERENCES users (id),
    CONSTRAINT chk_pay_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Module 6: audit trail (written by the application for every important action)
-- ---------------------------------------------------------------------------

CREATE TABLE audit_log (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NULL,
    action     VARCHAR(40) NOT NULL,
    entity     VARCHAR(40) NULL,
    entity_id  INT UNSIGNED NULL,
    details    VARCHAR(500) NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_audit_entity (entity, entity_id),
    KEY ix_audit_created (created_at),
    KEY ix_audit_action_ip (action, ip_address, created_at),
    KEY fk_audit_user (user_id),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Standard reference data
-- ---------------------------------------------------------------------------

INSERT INTO roles (id, code, name, description) VALUES
    (1, 'admin',        'System Administrator',      'Manages user accounts and system settings'),
    (2, 'manager',      'Hospital Manager',          'Views reports and the audit trail'),
    (3, 'receptionist', 'Receptionist / Records',    'Registers patients and books appointments'),
    (4, 'nurse',        'Nurse',                     'Records triage and vital signs'),
    (5, 'doctor',       'Doctor / Clinical Officer', 'Records consultations, prescriptions and test requests'),
    (6, 'lab',          'Laboratory Technologist',   'Records laboratory results'),
    (7, 'pharmacist',   'Pharmacist',                'Dispenses medicines and manages stock'),
    (8, 'cashier',      'Cashier / Billing Clerk',   'Prepares invoices and records payments');

INSERT INTO services (name, category, price) VALUES
    ('General consultation',   'Consultation', 500.00),
    ('Follow-up consultation', 'Consultation', 300.00),
    ('Wound dressing',         'Procedure',    400.00),
    ('Injection administration', 'Procedure',  150.00),
    ('Nebulisation',           'Procedure',    600.00);

INSERT INTO lab_tests (name, reference_range, price) VALUES
    ('Full haemogram',        'Hb 12.0-16.0 g/dL (women), 13.5-17.5 g/dL (men)', 800.00),
    ('Malaria test (BS/MPS)', 'Negative',                                          300.00),
    ('Urinalysis',            'Negative for protein, glucose and blood',           400.00),
    ('Random blood sugar',    '3.9-7.8 mmol/L',                                    200.00),
    ('Pregnancy test',        'Negative / Positive',                               250.00),
    ('Widal test',            'Titre below 1:80',                                  500.00),
    ('Stool microscopy',      'No ova or cysts seen',                              300.00);

INSERT INTO icd10_codes (code, description) VALUES
    ('A01.0', 'Typhoid fever'),
    ('A09',   'Other gastroenteritis and colitis of infectious and unspecified origin'),
    ('B34.9', 'Viral infection, unspecified'),
    ('B35.9', 'Dermatophytosis, unspecified'),
    ('B50.9', 'Plasmodium falciparum malaria, unspecified'),
    ('B54',   'Unspecified malaria'),
    ('B82.9', 'Intestinal parasitism, unspecified'),
    ('D64.9', 'Anaemia, unspecified'),
    ('E11.9', 'Type 2 diabetes mellitus without complications'),
    ('E66.9', 'Obesity, unspecified'),
    ('E86',   'Volume depletion'),
    ('F41.9', 'Anxiety disorder, unspecified'),
    ('G43.9', 'Migraine, unspecified'),
    ('H10.9', 'Conjunctivitis, unspecified'),
    ('H66.9', 'Otitis media, unspecified'),
    ('I10',   'Essential (primary) hypertension'),
    ('J00',   'Acute nasopharyngitis [common cold]'),
    ('J02.9', 'Acute pharyngitis, unspecified'),
    ('J03.9', 'Acute tonsillitis, unspecified'),
    ('J06.9', 'Acute upper respiratory infection, unspecified'),
    ('J18.9', 'Pneumonia, unspecified'),
    ('J20.9', 'Acute bronchitis, unspecified'),
    ('J45.9', 'Asthma, unspecified'),
    ('K29.7', 'Gastritis, unspecified'),
    ('K30',   'Functional dyspepsia'),
    ('K59.0', 'Constipation'),
    ('L30.9', 'Dermatitis, unspecified'),
    ('M54.5', 'Low back pain'),
    ('M79.1', 'Myalgia'),
    ('N30.0', 'Acute cystitis'),
    ('N39.0', 'Urinary tract infection, site not specified'),
    ('N76.0', 'Acute vaginitis'),
    ('R05',   'Cough'),
    ('R10.4', 'Other and unspecified abdominal pain'),
    ('R11',   'Nausea and vomiting'),
    ('R21',   'Rash and other nonspecific skin eruption'),
    ('R42',   'Dizziness and giddiness'),
    ('R50.9', 'Fever, unspecified'),
    ('R51',   'Headache'),
    ('S61.9', 'Open wound of wrist and hand, part unspecified'),
    ('T14.1', 'Open wound of unspecified body region'),
    ('Z00.0', 'General medical examination'),
    ('Z34.9', 'Supervision of normal pregnancy, unspecified');
