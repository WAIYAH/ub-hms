-- Migration 2026-10-24: adds the table behind the public Contact and Request access pages.
-- Safe to run more than once. Run it against an existing UB-HMS database:
--   mysql -u root uhai_bora_hms < database/migrations/2026-10-24_public_requests.sql
-- (a fresh install already includes this table through database/schema.sql).

SET NAMES utf8mb4;

-- Requests and messages sent from the public pages: a person asking for a staff account (kind 'access') or
-- sending a message from the contact page (kind 'contact'). An access request never creates an account;
-- the System Administrator reviews it and creates the account by hand.
CREATE TABLE IF NOT EXISTS public_requests (
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
