# Uhai Bora Hospital Management System (UB-HMS)

A web-based hospital management system for Uhai Bora Hospital, Nairobi, Kenya. It is the
Diploma in Information Technology project of Susan Wanjiku Waweru (25/04035), KCA University,
September to December 2026, supervised by David Oloo Ouma.

UB-HMS keeps the hospital's patient, clinical, laboratory, pharmacy and billing information in one
secure database, and shows each member of staff only the functions their role needs.

## The six modules

| # | Module | What it does |
| --- | --- | --- |
| 1 | Patient registration and appointments | Registers patients once with a unique outpatient number, checks for duplicates, searches and corrects records, books appointments without clashes, within each doctor's working hours and leave |
| 2 | Clinical consultation | Check-in and visits, nurse triage, doctor consultation with earlier visits in view, diagnosis (ICD-10 plus free text), test requests and prescriptions |
| 3 | Laboratory management | Sends each test request to the laboratory and returns the result to the doctor who asked for it |
| 4 | Pharmacy and inventory | Shows prescriptions to the pharmacist, deducts stock by batch (first expiry first) when medicines are dispensed, and raises low-stock and expiry alerts |
| 5 | Billing and reports | Builds itemised invoices from recorded services, records full and partial payments (Cash, M-Pesa, Card, Insurance), gives money back only through a manager-approved refund, and reports on attendance, revenue, stock and laboratory workload |
| 6 | User management and security | Personal accounts, eight roles, hashed passwords, lockout, session limits, an audit trail, and a patient's right to a copy of their record or to its erasure |

Roles: System Administrator, Hospital Manager, Receptionist/Records, Nurse, Doctor/Clinical Officer,
Laboratory Technologist, Pharmacist, Cashier/Billing Clerk. The permission for every role is defined in
one place, `permission_matrix()` in [includes/auth.php](includes/auth.php).

All data in the demonstration database is synthetic (made up). No real patient information is used.

## Requirements

- PHP 8.1 or newer with `pdo_mysql` and `mbstring` (tested on PHP 8.2.12)
- MariaDB or MySQL (tested only on MariaDB 10.4.32, the version in XAMPP 8.2.12; newer MariaDB and MySQL 8 should work but have not been tested)
- Apache 2.4 with `mod_headers` and `AllowOverride All` (tested on the Apache in XAMPP)
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
4. Browse to <http://localhost/ub-hms/>. The welcome page explains the system; choose **Staff sign in** (or go to `login.php`) to sign in.

If the database is not on the default settings, create `config/config.local.php` containing only the values
you want to change (for example `<?php return ['db' => ['port' => '3307']];`), or set the environment variables
`UBHMS_DB_HOST`, `UBHMS_DB_PORT`, `UBHMS_DB_NAME`, `UBHMS_DB_USER` and `UBHMS_DB_PASS`.

**Before real use**, stop the application connecting as `root`:

```
C:\xampp\php\php.exe database\create_app_user.php --password="A-long-random-password"
```

then put `['db' => ['user' => 'ubhms_app', 'pass' => 'A-long-random-password']]` in `config/config.local.php`.
That account can read and write the data it needs, can only add rows to the audit trail, payments and stock ledger,
and cannot change the database structure.

### Backups

`powershell -ExecutionPolicy Bypass -File scripts\backup.ps1` writes a dated, verified, compressed copy of the database to
`storage\backups` and deletes copies older than 30 days; `scripts\restore.ps1` restores one (after making a safety backup).
Schedule the backup every night with Windows Task Scheduler (the command is in the script's header).

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

## Public website

Visitors who are not signed in see a public website before the sign-in page. It has a header with a services menu, a comprehensive footer and nine pages. They show no patient data and need no database queries, except the two forms.

| Page | File | What it is |
| --- | --- | --- |
| Home | `welcome.php` | What UB-HMS does, the six modules, a patient visit, the eight roles, security and questions |
| Services | `services.php` | Each module in detail, with a jump menu |
| About | `about.php` | Purpose, principles, the people, where the project stands, questions and photo credits |
| Contact | `contact.php` | Hospital details and a message form |
| Request access | `register.php` | How staff ask for an account (see below) |
| Legal | `privacy.php`, `terms.php`, `cookies.php`, `accessibility.php` | Privacy policy, terms of use, cookie notice, accessibility statement |

The site address itself (for example `http://localhost/ub-hms/`) opens the home page for a visitor who is not signed in. A saved link such as `index.php` or `patients/` still goes straight to the sign-in page, and a signed-in user who opens a public page is sent to the dashboard.

**Request access does not create an account.** A hospital system must not let anyone create an account for themselves, so the form only sends a request. The System Administrator sees it under **Access requests**, checks the person with their supervisor, and uses **Create account**, which fills in the form from the request. The form never asks for a password, and the administrator role cannot be requested. The contact form and the request form are protected by the security token, a hidden trap field for robots, and a limit of five requests per computer per hour (`site.form_limit`). Nothing is sent by email; messages wait in the administrator's list.

**Set the hospital's own details** before live use. The telephone number, email address and address on the public pages are placeholders. Put the real values in `config/config.local.php`:

```php
<?php return ['site' => ['address' => '...', 'phone' => '+254 ...', 'email' => '...', 'privacy_email' => '...']];
```

**Existing databases** need the changes in `database/migrations/`. A fresh install already has them all. For a database that was installed earlier, take a backup, then run each file you have not run yet, in date order (every file is safe to run again):

```
C:\xampp\mysql\bin\mysql.exe -u root uhai_bora_hms < database\migrations\2026-10-24_public_requests.sql
C:\xampp\mysql\bin\mysql.exe -u root uhai_bora_hms < database\migrations\2026-10-25_schedules_refunds_data_rights.sql
```

The second one adds four tables (`doctor_schedules`, `doctor_leave`, `refund_requests`, `data_requests`) and four columns (`payments.kind`, `payments.refund_of`, `patients.erased_at`, `patients.erased_by`), so a complete database has 27 tables. If the web application uses the restricted account made by `database/create_app_user.php`, run that script again afterwards so the new tables get their grants. Check the result on the **System health** page.

The legal pages have been reviewed and approved by the hospital's lawyer. (The privacy policy sections on retention and on patient rights were updated when the copy and erasure features were added; show the new wording to the lawyer.) The photographs are free stock images from Pexels and are credited on the About page.

## Doctor schedules, refunds and patient data rights

Three features that a hospital needs every day, each with its own pages, permission and audit trail.

### Doctor schedules and leave (Hospital Manager)

**Administration > Doctor schedules** (`admin/schedules.php`, `admin/schedule.php`). The manager sets each doctor's usual weekly hours (up to two blocks a day, on the clinic's 20-minute grid) and records leave. **Booking follows these hours**: a time outside them, a day the doctor does not work, or a day of leave cannot be booked, and the booking page says which. A doctor with no personal schedule keeps the clinic opening hours, so a new doctor can be booked at once and nothing changes for existing doctors.

Hours and leave never touch appointments that are already booked. They are listed on **Appointments > Appointments to move** for the receptionists (with the patient's name and phone, to call them), the bell and sidebar show how many there are, and the manager sees the same count with outpatient numbers only. Receptionists are told that a doctor is "away", never why. A doctor sees their own hours and leave on their profile. A walk-in cannot be given to a doctor who is away today.

### Refunds (Cashier asks, Hospital Manager decides)

Money already taken is never edited or deleted. A refund is a **new, negative line in the payments ledger** that points at the payment it reverses, so the invoice balance, the cash-up and the revenue report all stay in step. On the invoice, the cashier presses **Refund** beside a payment and gives the amount and the reason. The request waits for the Hospital Manager (**Billing > Refunds**), who sees the invoice number, amount and reason but **not the patient's name**. Nobody can decide a refund they asked for (checked by the application and by the database). Approving writes the refund line, lowers the amount paid on the invoice and prints a **refund voucher** (REF-year-number); rejecting needs a reason. A request that is still waiting reserves its amount, so the same money cannot be asked for twice. The revenue report shows collected money **net of refunds** and the refunded amount separately. To take a charge off an invoice that was already paid, refund first (the message says so).

### Patient data: a copy of the record, and erasure (Records asks, Hospital Manager decides erasure)

**Patient record > Data requests** (`patients/data.php`), for Receptionist / Records only. The officer first checks who is asking and writes down how (a request cannot be recorded until the identity box is ticked).

- **A copy** is completed at once: a printable page and a JSON file with the personal details, appointments, visits (triage, consultation, tests, medicines), invoices, payments and refunds. Every time it is opened or downloaded is audited. The hospital's own security log is not part of it.
- **Erasure** means **anonymisation**, because invoices and payments must still add up. The manager (**Data protection > Patient data requests**), who does not see the patient's name, approves or refuses. Approval blanks the name, identity number, phone, address, next of kin and allergies, keeps only the year of birth, gives the record a reference that points to nobody, replaces the free text of visits, appointments, consultations, nurse and laboratory notes with "[erased]", and keeps the coded data (diagnosis code, vital signs, which tests and medicines) and every money line. It is **refused automatically** while there is an open visit, a booked appointment, money owed, a refund waiting, or while the retention period applies. Set the period in `config/config.local.php`, from the hospital's own legal advice: `<?php return ['privacy' => ['retention_years' => 10]];` (0, the default, means the system applies no period of its own).
- What erasure cannot reach: audit entries written before it still show the old outpatient number (never a name), and backups taken earlier still hold the original record until they are replaced. The hospital must deal with paper files and old backups under its own procedures.

Code: `includes/schedules.php`, `includes/refunds.php`, `includes/datarights.php`.

## Dashboards, header and sidebar

Every signed-in page has the same shell:

- **Header:** menu button, patient search (for roles that may search), a **Quick add (+)** menu, a **notification bell** with a count, and a **user menu** (View profile, Settings, Change password, Sign out). The menus are native `<details>` elements, so they work without JavaScript; Sign out stays a POST form with the security token.
- **Sidebar:** an icon on every item, grouped headings, the current page marked, and small badges for work waiting. It shrinks to icons (the choice is remembered in the browser) and becomes a drawer on a phone.
- **Notifications** are live alerts read from the records (patients waiting for triage, medicines out of stock, locked accounts, new access requests and so on). They are not stored messages, so there is nothing to mark as read: an alert goes away when the work is done. Each role sees only what it can act on.

Each of the eight roles has its own dashboard (greeting, coloured quick-action buttons, coloured KPI cards, charts and short live lists). The charts are drawn on the server as SVG, because the content security policy forbids scripts and outside libraries; each chart has a text summary and a hidden table of its numbers for screen readers.

| Role | Charts | Lists |
| --- | --- | --- |
| System Administrator | Sign-ins (successful and failed), activity, accounts by role | Latest audit entries |
| Hospital Manager | Money collected, revenue by kind, visits, payment methods | Busiest doctors, most requested tests, stock alerts |
| Receptionist / Records | New patients, how appointments ended | Today's appointments, who is in the clinic |
| Nurse | Triage done per day, where patients are now | Waiting for triage |
| Doctor / Clinical Officer | My consultations, what I diagnose most | Waiting for me, test results ready |
| Laboratory Technologist | Requested and completed, most requested tests | Worklist, oldest first |
| Pharmacist | Units dispensed, stock status, dispensed most | Prescriptions waiting, stock alerts |
| Cashier / Billing Clerk | My collections, my payment methods | Invoices waiting for payment |

**Profile** (`profile.php`) shows a person's own details, what their role allows and their last ten actions. **Settings** (`settings.php`) lets a person change only their own phone number (the audit trail records that it changed, not the number), links to the password page, and offers two display choices saved in the browser (menu shrunk to icons, compact tables). Names, usernames and roles can be changed only by the System Administrator.

Dashboard code: `includes/dashboard.php` (cards and the eight role dashboards), `includes/analytics.php` (the queries behind the charts), `includes/charts.php` (SVG charts), `includes/notifications.php` (alerts and sidebar badges), `includes/shell.php` (header and sidebar), and `assets/css/dash.css`, `assets/js/dash.js`. No database change was needed. On the 20,040-patient test database every dashboard loads in under 0.5 seconds.

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
| `welcome.php` | Public welcome page (what the system does; no patient data, no database queries) |
| `index.php`, `login.php`, `logout.php`, `password.php`, `profile.php`, `settings.php` | Dashboard and account pages |
| `patients/`, `appointments/`, `visits/`, `lab/`, `pharmacy/`, `billing/`, `privacy/`, `reports/`, `admin/` | One folder per area of work; every page starts by requiring a permission |
| `includes/` | Bootstrap, database access (PDO), authentication and permissions, CSRF, audit, layout, the public website shell (`site.php`), public requests (`requests.php`), the signed-in shell (`shell.php`), dashboards (`dashboard.php`, `analytics.php`, `charts.php`, `notifications.php`), and one file of business rules per module |
| `database/` | `schema.sql`, `install.php`, `demo_data.php`, and `migrations/` for databases installed earlier |
| `assets/` | Stylesheets (`app.css` for the system, `dash.css` for the signed-in shell and dashboards, `site.css` for the public website and sign-in page), small scripts, images and icons (no external fonts, scripts or trackers) |
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
