# UB-HMS security review against the OWASP Top 10 (2021)

Review carried out in Sprint 5 on the integrated system. Method: reading every page and include file for each
Top 10 category, then writing tests that try to break the control (unit tests `UT-…`, end-to-end checks `E2E-…`,
and Apache server checks `APA-…`). Findings were corrected and the whole suite re-run.

Environment tested: PHP 8.2.12, MariaDB 10.4.32 and Apache 2.4 as bundled with XAMPP 8.2.12, on Windows 11.
All data used was synthetic.

## Summary

| Category | Result | Main controls | Key evidence |
| --- | --- | --- | --- |
| A01 Broken access control | Controlled; 2 findings fixed | One permission matrix; every page calls `require_permission()`; least privilege per role | E2E-200 to E2E-229, UT-COR-011, UT-COR-012 |
| A02 Cryptographic failures | Controlled; HTTPS is a deployment duty | bcrypt hashes; no secrets in code; HttpOnly, SameSite=Strict cookies; HSTS sent over HTTPS | UT-AUT-009, E2E-230, APA-020 |
| A03 Injection | Controlled | PDO prepared statements; output escaping; CSV formula guard; strict CSP | UT-COR-015, E2E-023, E2E-024, E2E-237 to E2E-245 |
| A04 Insecure design | Controlled | Business rules enforced in the server and the database | UT-APT-014, UT-APT-018, UT-PHA-019, UT-BIL-019, UT-INT-003 |
| A05 Security misconfiguration | Controlled; 1 deployment duty | Security headers, folder protection, quiet error pages, least-privilege database account | E2E-002, E2E-246, APA-002 to APA-023, E2E suite run as `ubhms_app` |
| A06 Vulnerable and outdated components | No third-party code; platform ageing noted | Nothing to patch in the application; platform versions recorded | Section A06 below |
| A07 Identification and authentication failures | Controlled; 2 defects fixed earlier | Lockout, throttle, session limits, forced password change, timing-safe sign-in | UT-AUT-001 to UT-AUT-012, E2E-231 to E2E-236 |
| A08 Software and data integrity failures | Controlled | CSRF tokens on every POST; validated CSV import; no deserialisation | UT-INT-009, E2E-003, E2E-029, E2E-102, E2E-124 |
| A09 Security logging and monitoring failures | Controlled; 3 findings fixed | Audit trail of sign-ins, changes, views, refusals and exports; health page | UT-INT-002, E2E-226, E2E-249 |
| A10 Server-side request forgery | Not applicable | The application makes no outgoing requests | Code review (no `curl`, `file_get_contents` on URLs, or `fsockopen`) |

## Findings and corrections

| ID | Category | Finding | Severity | Correction | Retest |
| --- | --- | --- | --- | --- | --- |
| SR-01 | A01 | The visit summary showed triage vital signs to receptionists, who do not need them for their work. | Medium | The triage block is shown only to roles that record triage or consult. | E2E-227, E2E-228 |
| SR-02 | A01 | The Hospital Manager role could open individual invoices, which reveals which services a named patient received. The role is meant to see reports and the audit trail. | Medium | `billing.view` removed from the manager; the manager sees totals in the revenue report. | E2E-134, E2E-229 |
| SR-03 | A07 | The dummy hash used to hide whether a username exists was cost 10 while real hashes are cost 11, so a response could be fractionally quicker for an unknown name. | Low | Dummy hash regenerated at cost 11. | UT-AUT-003, E2E-004, E2E-005 |
| SR-04 | A09 | Opening the audit trail itself was not recorded. | Low | Each opening is now logged. | E2E-031 |
| SR-05 | A09 | Invoice creation was not recorded, and medicine edits did not say which fields changed. | Low | Both are now logged (field names and price change only). | UT-INT-002 |
| SR-06 | A07 | Idle-timeout logic was inside the start-up script and could not be tested. | Low | Moved to `session_has_expired()` with a unit test and an end-to-end test using a 2-second limit. | UT-INT-007, E2E-234 |
| SR-07 | A05 | The application connected to the database as `root` by default (the XAMPP default). | Medium for real use | `database/create_app_user.php` creates a restricted account that can only add rows to the audit trail, payments and stock ledger; the whole end-to-end suite passes when run as that account. | E2E suite with `E2E_LEAST_PRIVILEGE=1` (184 of 184) |

Two defects found earlier by the automated tests also belong in this review: DEF-001 (the account lock came one
failed attempt early) and DEF-002 (the per-computer sign-in throttle did not block anyone). Both are fixed and
covered by UT-AUT-004 and UT-AUT-007. The full register is in `docs/defects.md`.

## A01 Broken access control

* Every page begins with `require_permission('...')` (or `require_login()` for the dashboard), so access never
  depends on hiding a menu link. The permission table is a single function, `permission_matrix()` in
  `includes/auth.php`.
* Least privilege: the System Administrator can manage accounts, prices, the audit trail and system health but
  cannot open patient records; the cashier cannot see clinical data; the laboratory sees only the patient identity
  needed to run a test; doctors cannot dispense; the pharmacist cannot enter results.
* **Systematic test:** E2E-200 to E2E-224 request every page as every role (192 combinations) and compare the
  result with what the permission table says. All 192 agree.
* Object-level rules: only the doctor who recorded a consultation can change it (UT-CON-003); lab results return to
  the requesting doctor (UT-LAB-008); only that doctor can cancel a test (UT-LAB-009).
* Refused requests are written to the audit trail as `access_denied` (E2E-226).
* A deactivated user loses access on the next request, not at the next sign-in (E2E-235).

## A02 Cryptographic failures

* Passwords are stored only as bcrypt hashes (cost 11) and are never logged or echoed (UT-AUT-009, UT-USR-007).
  One-time passwords are shown once and are not written to the audit trail.
* The session cookie is `HttpOnly` and `SameSite=Strict`, and `Secure` when the site is served over HTTPS.
  `Strict-Transport-Security` is sent over HTTPS (E2E-230, APA-020).
* No secret is held in source control: `config/config.php` has no password and local settings go in
  `config/config.local.php`, which Git ignores (UT-INT-008).
* **Deployment duty:** the hospital network is private, but TLS should be enabled on the server wherever staff
  connect over wireless links. The system health page warns when it is served over plain HTTP.

## A03 Injection

* Every query uses PDO prepared statements with emulation switched off. User text is never joined into SQL;
  `LIMIT` values are cast to integers and `LIKE` patterns escape `%` and `_` (UT-COR-015, UT-PAT-016).
* SQL injection attempts in the sign-in form, patient search, invoice filters, audit filters and report dates
  return nothing and break nothing (E2E-024, E2E-237 to E2E-240).
* Output escaping: `e()` is applied to every value printed into a page. Markup typed into patient fields, user
  names, medicine names and clinical text is shown as plain text (E2E-023, E2E-062, E2E-081, E2E-241, E2E-242).
* A strict Content-Security-Policy (`default-src 'self'`, no inline script or style) is a second line of defence;
  E2E-243 confirms no page contains inline scripts or event handlers.
* CSV exports prefix cells that begin with `=`, `+`, `-` or `@` so a spreadsheet cannot run them as formulas
  (UT-REP-007, E2E-245).

## A04 Insecure design

Business rules are enforced on the server and again by the database, so a bug or a bypassed form cannot break them:

| Rule | Server check | Database guard |
| --- | --- | --- |
| One doctor, one slot | Transaction with a row lock (UT-APT-004, UT-APT-018) | Unique key on `appointments.slot_key` (UT-APT-014) |
| Stock never negative | `UPDATE ... WHERE quantity >= ?` inside a transaction (UT-PHA-016) | `CHECK (quantity >= 0)` (UT-PHA-019) |
| Never dispense expired stock | First-expiry-first batch selection (UT-PHA-014) | None (policy rule) |
| A charge cannot be billed twice | Idempotent invoice sync (UT-BIL-006) | Unique key on invoice item source (UT-BIL-006) |
| Never pay more than the balance | Row-locked check (UT-BIL-009) | `CHECK (amount > 0)` and the invoice paid-at-most rule (UT-BIL-019) |
| One invoice per visit | Locked sync | Unique key on `invoices.visit_id` (UT-BIL-019) |
| One open visit per patient | Check at check-in (UT-VIS-003) | Integrity check on the health page (UT-INT-004) |
| Records cannot be orphaned | Foreign keys with no cascading deletes (UT-INT-005) | Foreign keys |

The health page also runs a reconciliation of invoices, payments and the stock ledger (`data_integrity_problems()`),
which detects corrupted records (UT-INT-003, UT-INT-004).

## A05 Security misconfiguration

* Security headers on every response: Content-Security-Policy, X-Frame-Options DENY, nosniff, Referrer-Policy,
  Permissions-Policy and `Cache-Control: no-store` so records are not kept by the browser (E2E-002, E2E-244).
  `X-Powered-By` is removed (APA-019).
* Errors are logged with a reference number and never shown; users see a calm page (E2E-246, E2E-247).
* `.htaccess` files deny web access to `config`, `includes`, `database`, `tests`, `storage`, `scripts` and `docs`,
  and to file types such as `.sql`, `.md`, `.log` and `.ps1`. **Checked on a real Apache** (the PHP built-in server
  ignores `.htaccess`): APA-002 to APA-015. Directory listing is off (APA-015).
* The application can run as a least-privilege database account (SR-07).
* Demonstration accounts are flagged on the system health page until they are deleted.

## A06 Vulnerable and outdated components

* The application has **no third-party PHP or JavaScript libraries**, no CDN and no fonts or trackers loaded from
  other sites, so there is nothing of its own to patch.
* Platform versions tested: PHP 8.2.12, MariaDB 10.4.32, Apache 2.4 (XAMPP 8.2.12).
* **Recommendation:** MariaDB 10.4 has reached the end of its support life and PHP 8.2 receives security fixes only.
  Before real use the hospital should install a current XAMPP (or equivalent) with a supported MariaDB release.
  The schema uses standard features but has been tested only on MariaDB 10.4.32.

## A07 Identification and authentication failures

* Personal accounts only; one-time passwords at account creation and reset; the user must choose a new password
  before any page opens (E2E-006, E2E-007, E2E-016).
* Password policy: at least 10 characters with upper case, lower case and a digit, and not containing the username
  (UT-COR-008, E2E-008).
* An account locks for 15 minutes after five wrong passwords, even for the correct password afterwards
  (UT-AUT-004, E2E-036). More than 20 failures from one computer in 15 minutes blocks that computer (UT-AUT-007).
* The sign-in message is identical for a wrong password and an unknown user, and response time is evened out with a
  dummy hash (UT-AUT-003, E2E-004, E2E-005).
* A new session id is issued at sign-in (fixation defence, E2E-231); signing out destroys the session so a copied
  cookie is useless (E2E-232); sessions end after 30 idle minutes or 8 hours (UT-INT-007, E2E-234).
* Changing a password requires the current password (E2E-236).

## A08 Software and data integrity failures

* Every state-changing request is a POST carrying a per-session CSRF token that is compared in constant time.
  A source check (UT-INT-009) confirms every such page calls `require_post()`; E2E-003, E2E-029, E2E-048,
  E2E-064, E2E-102, E2E-124 and E2E-233 prove requests without a token, or with another session's token, are refused.
* The patient CSV import accepts only `.csv` files up to 1 MB, validates every row with the same rules as the
  registration form, shows a preview before saving and stores the upload under a random name that is deleted
  afterwards (UT-PAT-018 to UT-PAT-020).
* No `unserialize()`, `eval()` or remote includes are used anywhere.

## A09 Security logging and monitoring failures

* The audit trail records who, what, which record, from which computer and when, for sign-ins and failures, account
  changes, patient registration, correction, search and viewing, appointments, check-in, triage, consultations,
  laboratory requests and results, prescriptions, dispensing, stock receipts and adjustments, invoices, payments,
  price changes, report views and exports, refused requests and rejected tokens (UT-INT-002).
* The trail holds identifiers and short descriptions only: no names, diagnoses, results, phone numbers, M-Pesa codes
  or passwords (UT-INT-002, E2E-033, E2E-115, E2E-146).
* With the least-privilege account the trail, the payment table and the stock ledger are append-only at the
  database level.
* The system health page shows failed sign-ins and refused requests in the last 24 hours and locked accounts, and
  warns when backups are missing or old (UT-INT-010).

## A10 Server-side request forgery

Not applicable. UB-HMS makes no outgoing network requests and fetches no URLs supplied by users.

## Residual risks and recommendations

1. Enable TLS on the production server and keep the server patched (A02, A06).
2. Replace the demonstration passwords and delete the demonstration accounts before real use (A07).
3. Run `database/create_app_user.php` and stop using `root` for the web application (A05).
4. Schedule `scripts/backup.ps1` every night and test a restore each quarter.
5. Test concurrent use on Apache with several browsers before go-live. The automated tests prove the locking rules
   but the Windows PHP built-in server used by `e2e.sh` handles one request at a time.
6. The appointment diary marks old bookings as missed when it is opened. This is a harmless, repeatable
   housekeeping step that uses no user input, and it was left as a GET-time action by design.
7. A password-reset-by-email feature is deliberately absent: the hospital network may have no mail service and an
   administrator resets passwords in person.
8. A full independent penetration test was out of scope for a diploma project and is recommended before the
   system holds real patient information.
