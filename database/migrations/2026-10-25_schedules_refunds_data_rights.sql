-- Migration 2026-10-25: doctor schedules and leave, refunds, and patient data export / erasure.
-- Adds four tables (doctor_schedules, doctor_leave, refund_requests, data_requests) and changes two:
--   payments  gets "kind" and "refund_of" so a refund can be recorded as a new, negative row (nothing is edited);
--   patients  gets "erased_at" and "erased_by" to mark an anonymised record.
-- Every existing row stays valid: all existing payments become kind 'Payment', and a doctor with no schedule rows
-- keeps the clinic's opening hours.
--
-- Safe to run more than once. Take a backup first (scripts/backup.ps1 or mysqldump), then:
--   mysql -u root uhai_bora_hms < database/migrations/2026-10-25_schedules_refunds_data_rights.sql
-- (a fresh install already includes all of this through database/schema.sql).
--
-- After running it, if the application connects with the restricted account made by database/create_app_user.php,
-- run that script again so the new tables get their grants.
--
-- Needs MariaDB 10.4 or later (it uses ADD COLUMN IF NOT EXISTS and DROP CONSTRAINT IF EXISTS).

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Doctor schedules and leave
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS doctor_schedules (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    doctor_id  INT UNSIGNED NOT NULL,
    weekday    TINYINT UNSIGNED NOT NULL,
    start_time TIME NOT NULL,
    end_time   TIME NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sched_block (doctor_id, weekday, start_time),
    KEY fk_sched_user (created_by),
    CONSTRAINT fk_sched_doctor FOREIGN KEY (doctor_id)  REFERENCES users (id),
    CONSTRAINT fk_sched_user   FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT chk_sched_day   CHECK (weekday BETWEEN 1 AND 7),
    CONSTRAINT chk_sched_times CHECK (start_time < end_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS doctor_leave (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    doctor_id  INT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    end_date   DATE NOT NULL,
    type       ENUM('Annual leave','Sick leave','Training','Other') NOT NULL DEFAULT 'Annual leave',
    reason     VARCHAR(255) NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_leave_doctor (doctor_id, start_date, end_date),
    KEY fk_leave_user (created_by),
    CONSTRAINT fk_leave_doctor FOREIGN KEY (doctor_id)  REFERENCES users (id),
    CONSTRAINT fk_leave_user   FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT chk_leave_dates CHECK (end_date >= start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Refunds: the payments ledger learns about refund rows
-- ---------------------------------------------------------------------------

ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS kind ENUM('Payment','Refund') NOT NULL DEFAULT 'Payment' AFTER invoice_id,
    ADD COLUMN IF NOT EXISTS refund_of INT UNSIGNED NULL AFTER kind;
ALTER TABLE payments ADD KEY IF NOT EXISTS fk_pay_refund_of (refund_of);
ALTER TABLE payments ADD CONSTRAINT fk_pay_refund_of FOREIGN KEY IF NOT EXISTS (refund_of) REFERENCES payments (id);
-- The old rule (amount > 0) is replaced by one that also allows a negative amount, but only on a refund row.
ALTER TABLE payments DROP CONSTRAINT IF EXISTS chk_pay_amount;
ALTER TABLE payments DROP CONSTRAINT IF EXISTS chk_pay_kind;
ALTER TABLE payments ADD CONSTRAINT chk_pay_kind CHECK ((kind = 'Payment' AND amount > 0 AND refund_of IS NULL)
                                                    OR (kind = 'Refund'  AND amount < 0 AND refund_of IS NOT NULL));

CREATE TABLE IF NOT EXISTS refund_requests (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference         VARCHAR(20)  NULL,
    payment_id        INT UNSIGNED NOT NULL,
    invoice_id        INT UNSIGNED NOT NULL,
    amount            DECIMAL(12,2) NOT NULL,
    reason            VARCHAR(255) NOT NULL,
    refund_reference  VARCHAR(40)  NULL,
    status            ENUM('Pending','Approved','Rejected','Cancelled') NOT NULL DEFAULT 'Pending',
    requested_by      INT UNSIGNED NOT NULL,
    requested_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_by        INT UNSIGNED NULL,
    decided_at        DATETIME NULL,
    decision_note     VARCHAR(255) NULL,
    refund_payment_id INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rr_reference (reference),
    UNIQUE KEY uq_rr_refund_payment (refund_payment_id),
    KEY ix_rr_status (status, requested_at),
    KEY fk_rr_payment (payment_id),
    KEY fk_rr_invoice (invoice_id),
    KEY fk_rr_requester (requested_by),
    KEY fk_rr_decider (decided_by),
    CONSTRAINT fk_rr_payment   FOREIGN KEY (payment_id)        REFERENCES payments (id),
    CONSTRAINT fk_rr_invoice   FOREIGN KEY (invoice_id)        REFERENCES invoices (id),
    CONSTRAINT fk_rr_requester FOREIGN KEY (requested_by)      REFERENCES users (id),
    CONSTRAINT fk_rr_decider   FOREIGN KEY (decided_by)        REFERENCES users (id),
    CONSTRAINT fk_rr_refund    FOREIGN KEY (refund_payment_id) REFERENCES payments (id),
    CONSTRAINT chk_rr_amount   CHECK (amount > 0),
    CONSTRAINT chk_rr_decided  CHECK (status = 'Pending' OR (decided_by IS NOT NULL AND decided_at IS NOT NULL)),
    CONSTRAINT chk_rr_approved CHECK (status <> 'Approved' OR refund_payment_id IS NOT NULL),
    CONSTRAINT chk_rr_two_person CHECK (status = 'Cancelled' OR decided_by IS NULL OR decided_by <> requested_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Patient data rights: export and erasure
-- ---------------------------------------------------------------------------

ALTER TABLE patients
    ADD COLUMN IF NOT EXISTS erased_at DATETIME NULL AFTER updated_at,
    ADD COLUMN IF NOT EXISTS erased_by INT UNSIGNED NULL AFTER erased_at;
ALTER TABLE patients ADD KEY IF NOT EXISTS fk_patients_eraser (erased_by);
ALTER TABLE patients ADD CONSTRAINT fk_patients_eraser FOREIGN KEY IF NOT EXISTS (erased_by) REFERENCES users (id);

CREATE TABLE IF NOT EXISTS data_requests (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference          VARCHAR(20)  NULL,
    patient_id         INT UNSIGNED NOT NULL,
    kind               ENUM('Export','Erasure') NOT NULL,
    requester_name     VARCHAR(120) NOT NULL,
    requester_relation VARCHAR(40)  NOT NULL,
    identity_checked   TINYINT(1)   NOT NULL DEFAULT 0,
    identity_note      VARCHAR(255) NULL,
    reason             VARCHAR(255) NULL,
    status             ENUM('Open','Completed','Refused','Cancelled') NOT NULL DEFAULT 'Open',
    decision_note      VARCHAR(255) NULL,
    created_by         INT UNSIGNED NOT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_by         INT UNSIGNED NULL,
    decided_at         DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dr_reference (reference),
    KEY ix_dr_status (kind, status, created_at),
    KEY fk_dr_patient (patient_id),
    KEY fk_dr_creator (created_by),
    KEY fk_dr_decider (decided_by),
    CONSTRAINT fk_dr_patient FOREIGN KEY (patient_id)  REFERENCES patients (id),
    CONSTRAINT fk_dr_creator FOREIGN KEY (created_by)  REFERENCES users (id),
    CONSTRAINT fk_dr_decider FOREIGN KEY (decided_by)  REFERENCES users (id),
    CONSTRAINT chk_dr_identity CHECK (identity_checked = 1),
    CONSTRAINT chk_dr_two_person CHECK (status IN ('Open', 'Cancelled') OR decided_by IS NULL OR decided_by <> created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
