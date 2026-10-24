# Uhai Bora Hospital Management System (UB-HMS)

A web-based hospital management system for Uhai Bora Hospital, Nairobi, Kenya. It is the
Diploma in Information Technology project of Susan Wanjiku Waweru (25/04035), KCA University,
September to December 2026, supervised by David Oloo Ouma.

UB-HMS keeps the hospital's patient, clinical, laboratory, pharmacy and billing information in one
secure database, and shows each member of staff only the functions their role needs.

## The six modules

| # | Module | What it does |
| --- | --- | --- |
| 1 | Patient registration and appointments | Registers patients once with a unique outpatient number, checks for duplicates, searches and corrects records, books appointments without clashes |
| 2 | Clinical consultation | Check-in and visits, nurse triage, doctor consultation with earlier visits in view, diagnosis (ICD-10 plus free text), test requests and prescriptions |
| 3 | Laboratory management | Sends each test request to the laboratory and returns the result to the doctor who asked for it |
| 4 | Pharmacy and inventory | Shows prescriptions to the pharmacist, deducts stock by batch (first expiry first) when medicines are dispensed, and raises low-stock and expiry alerts |
| 5 | Billing and reports | Builds itemised invoices from recorded services, records full and partial payments (Cash, M-Pesa, Card, Insurance), and reports on attendance, revenue, stock and laboratory workload |
| 6 | User management and security | Personal accounts, eight roles, hashed passwords, lockout, session limits and an audit trail |

Roles: System Administrator, Hospital Manager, Receptionist/Records, Nurse, Doctor/Clinical Officer,
Laboratory Technologist, Pharmacist, Cashier/Billing Clerk. The permission for every role is defined in
one place, `permission_matrix()` in [includes/auth.php](includes/auth.php).

All data in the demonstration database is synthetic (made up). No real patient information is used.

## Requirements

- PHP 8.1 or newer (tested on 8.2) with `pdo_mysql` and `mbstring`
- MariaDB 10.4+ or MySQL 8 (XAMPP provides both)
- A modern browser (Chrome, Firefox or Edge)

## Install on XAMPP (Windows)

1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Copy this folder to `C:\xampp\htdocs\ub-hms`.
3. Open a terminal in that folder and run:

   ```
   C:\xampp\php\php.exe database\install.php --admin-user=sysadmin --admin-pass="ChooseA#Strong1Password" --demo
   ```

   This creates the database `uhai_bora_hms`, loads [database/schema.sql](database/schema.sql), creates the
   first administrator and (because of `--demo`) loads synthetic demonstration data. Leave out `--demo`
   for a real installation. The installer refuses to run over an existing database unless you add `--force`.
4. Browse to <http://localhost/ub-hms/> and sign in.

If the database is not on the default settings, copy `config/config.php` to `config/config.local.php`,
keep only the values you want to change, or set the environment variables `UBHMS_DB_HOST`,
`UBHMS_DB_PORT`, `UBHMS_DB_NAME`, `UBHMS_DB_USER` and `UBHMS_DB_PASS`.

### Demonstration accounts (only with `--demo`)

All demonstration accounts use the password `UbHms#Demo2026`. They are for testing only: delete them
before the system holds real information.

| Username | Role |
| --- | --- |
| `manager1` | Hospital Manager |
| `reception1`, `reception2` | Receptionist / Records |
| `nurse1`, `nurse2` | Nurse |
| `doctor1`, `doctor2`, `doctor3` | Doctor / Clinical Officer |
| `lab1` | Laboratory Technologist |
| `pharmacist1` | Pharmacist |
| `cashier1` | Cashier / Billing Clerk |

The administrator account is the one you created during installation.

## Tests

```
php tests/run_tests.php          # unit and module tests (database: uhai_bora_hms_test)
bash tests/e2e.sh                # end-to-end tests through the web server (database: uhai_bora_hms_e2e)
```

Both build their own database from `database/schema.sql`, so the live data is never touched. The
unit-test runner refuses to run against the live database name. Results are written to `tests/output/`.

## Project layout

| Path | Purpose |
| --- | --- |
| `index.php`, `login.php`, `logout.php`, `password.php` | Dashboard and account pages |
| `patients/`, `appointments/`, `visits/`, `lab/`, `pharmacy/`, `billing/`, `reports/`, `admin/` | One folder per area of work; every page starts by requiring a permission |
| `includes/` | Bootstrap, database access (PDO), authentication and permissions, CSRF, audit, layout, and one file of business rules per module |
| `database/` | `schema.sql`, `install.php`, `demo_data.php` |
| `assets/` | Stylesheet, small script, icon (no external fonts, scripts or trackers) |
| `tests/` | `run_tests.php` and `unit/*_test.php`, `e2e.sh` |
| `docs/` | Gap analysis, defect log, security review and the dissertation chapters in `docs/chapters/` |

## Security summary

PDO prepared statements for every query; output escaping on every page; a CSRF token on every form;
role-based permissions checked on every page; bcrypt password hashing; account lockout after five wrong
passwords; automatic sign-out after 30 minutes idle; a strict Content-Security-Policy and other security
headers; and an audit trail of sign-ins and of every create, change, view and payment. See
`docs/security-review.md` for the review against the OWASP Top 10.

## Legal framework

Designed with the Health Act, 2017, the Data Protection Act, 2019 and the Digital Health Act, 2023 in mind:
individual accounts, role-based access, minimised data, correctable records and an audit trail. A
hospital must still complete its own data protection registration and impact assessment before live use.
