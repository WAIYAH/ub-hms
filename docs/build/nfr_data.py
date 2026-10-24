"""Non-functional requirements of UB-HMS (Chapter 4, section 4.5) and how each is checked (Chapter 7).

An entry: (id, requirement, priority, how it is checked, tests)
`tests` uses the ids printed by the test programs; PERF-nn are the response-time measurements made with
tests/apache_check.ps1 -Perf; MAN-nn are the manual checks described in Chapter 7; DES means checked by
inspection of the design or code (no automated test exists for it).
"""

PERFORMANCE = [
    ('PER-01', 'With ten staff working at the same time, 95% of ordinary pages (every page except the reports) shall be served in 2 seconds or less.', 'High',
     'Response-time measurement through Apache with 10 separate sessions', 'PERF-01 to PERF-10, PERF-16'),
    ('PER-02', 'Searching for a patient shall return in 1 second or less (95% of searches) when 20,000 patients are registered.', 'High',
     'Response-time measurement on a database of 20,000 patients, 30,000 visits and 100,000 audit entries', 'PERF-02, PERF-03'),
    ('PER-03', 'A report for any period up to 12 months shall be shown in 5 seconds or less (95% of requests).', 'Medium',
     'Response-time measurement of the reports on the same database', 'PERF-11 to PERF-15'),
    ('PER-04', 'The system shall stay within these response times with at least 20,000 patients, 30,000 visits, 30,000 invoices and 100,000 audit entries in the database.', 'Medium',
     'The measurements above were taken on a database of exactly this size', 'PERF-01 to PERF-16'),
    ('PER-05', 'A nightly backup of a database of that size shall finish in 5 minutes or less.', 'Low',
     'Manual check: run scripts/backup.ps1 on the large database and time it', 'MAN-01'),
]

SAFETY = [
    ('SAF-01', 'The system shall never dispense a medicine from an expired batch, and shall show expired and soon-to-expire batches so that they can be removed from the shelf.', 'High',
     'Unit tests of dispensing and expiry', 'UT-PHA-006, UT-PHA-007, UT-PHA-014, E2E-087'),
    ('SAF-02', 'The system shall show known allergies to the staff who handle the patient and shall remind them when a prescribed medicine matches an allergy. The system shall not decide whether a medicine is safe; that remains the clinician\'s duty.', 'High',
     'Unit and end-to-end tests of the allergy reminder; design review for the limit', 'UT-PHA-011, E2E-054, E2E-059, E2E-100'),
    ('SAF-03', 'The system shall record what clinicians decide and shall not diagnose, suggest treatment or calculate doses (a stated exclusion in the proposal, section 1.7).', 'High',
     'Design review: the system holds no rules or formulas for diagnosis or dosing', 'DES'),
    ('SAF-04', 'A laboratory result shall always belong to the right request, patient and requesting doctor, and a cancelled request shall never receive a result.', 'High',
     'Unit tests of requests and results', 'UT-LAB-001, UT-LAB-007, UT-LAB-008'),
    ('SAF-05', 'The system shall refuse vital signs that cannot be correct (for example a temperature of 60 degrees or a diastolic pressure above the systolic) so that a typing slip is not stored as a measurement.', 'Medium',
     'Unit and end-to-end tests of triage validation', 'UT-TRI-002, UT-TRI-003, E2E-055'),
    ('SAF-06', 'A change of several records that belong together (dispensing several medicines, booking, payment, test request) shall be saved completely or not at all.', 'High',
     'Unit tests of transactions', 'UT-COR-014, UT-PHA-016, UT-LAB-004, UT-APT-018'),
    ('SAF-07', 'The system shall not allow a record that other records depend on to be deleted, and shall be able to detect invoices, payments, stock and prescriptions that no longer agree.', 'High',
     'Integration tests of foreign keys and of the data-integrity check', 'UT-INT-004, UT-INT-005, UT-INT-006, E2E-249'),
    ('SAF-08', 'The hospital shall be able to recover from a failed disk or a mistaken change using the nightly backup; the system shall warn the administrator when the latest backup is older than 26 hours.', 'High',
     'Manual check of backup and restore; the health page check', 'MAN-01, MAN-02, E2E-249'),
    ('SAF-09', 'Error pages shall not show file paths, program code or database messages.', 'Medium',
     'End-to-end tests with missing records and unexpected input', 'E2E-246, E2E-247'),
]

SECURITY = [
    ('SEC-01', 'Authentication: every user shall have a personal account; passwords shall be stored as bcrypt hashes, shall follow the password rule, and accounts shall lock after five wrong passwords (REQ-063 to REQ-068).', 'High',
     'Unit and end-to-end tests of sign-in', 'UT-AUT-001 to UT-AUT-012, UT-COR-008, UT-COR-009, E2E-004 to E2E-009, E2E-036, E2E-236'),
    ('SEC-02', 'Authorisation: every page shall check the user\'s role before it runs, and each role shall hold only the permissions its work needs (REQ-072).', 'High',
     'Unit test of the permission table; end-to-end test of all 192 role-and-page combinations', 'UT-COR-011, UT-COR-012, E2E-200 to E2E-224'),
    ('SEC-03', 'Injection: every database query shall use prepared statements so that typed text can never become SQL.', 'High',
     'Unit and end-to-end tests with SQL typed into forms, searches and filters', 'UT-COR-015, UT-PAT-016, E2E-024, E2E-237, E2E-238, E2E-240'),
    ('SEC-04', 'Cross-site scripting: all text shall be escaped when shown, no page shall contain inline scripts, and a strict Content Security Policy shall be sent.', 'High',
     'Unit and end-to-end tests with markup typed into every kind of field', 'UT-COR-001, E2E-023, E2E-062, E2E-081, E2E-241, E2E-242, E2E-243, E2E-002'),
    ('SEC-05', 'Cross-site request forgery: every page that changes data shall require a POST with a token that belongs to the current session (REQ-075).', 'High',
     'Unit and end-to-end tests with missing and foreign tokens', 'UT-COR-010, UT-INT-009, E2E-003, E2E-064, E2E-233'),
    ('SEC-06', 'Sessions: the session cookie shall be HttpOnly and SameSite=Strict, a new session number shall be issued at sign-in, and sessions shall end after 30 idle minutes or 8 hours (REQ-067).', 'High',
     'End-to-end tests; Apache check of the cookie', 'E2E-230 to E2E-235, UT-INT-007, APA-020'),
    ('SEC-07', 'Browser storage: pages shall be sent with no-store caching so that records are not kept in the browser after sign-out.', 'Medium',
     'End-to-end test of the response headers', 'E2E-244'),
    ('SEC-08', 'Server protection: internal files (code, configuration, schema, logs, backups, scripts) shall not be served to browsers; directory listing shall be off; the PHP version shall not be advertised.', 'High',
     'Integration test of the folder rules; Apache checks on a real Apache server', 'UT-INT-008, APA-002 to APA-015, APA-019, APA-021'),
    ('SEC-09', 'Least privilege in the database: the account used by the application shall not be able to change the database structure, and shall only add rows to the audit trail, payments and stock ledger (REQ-079).', 'High',
     'The whole end-to-end suite re-run while connected as the restricted account', 'E2E-001 to E2E-252 with E2E_LEAST_PRIVILEGE=1'),
    ('SEC-10', 'Privacy by design: staff shall see only the patient information their role needs, and the audit trail shall hold no health information (REQ-024, REQ-077).', 'High',
     'End-to-end tests of what each role sees; audit content checks', 'E2E-033, E2E-068, E2E-115, E2E-146, E2E-227, E2E-228, E2E-229'),
    ('SEC-11', 'Accountability: every important action shall be traceable to a person, a computer and a time (REQ-076).', 'High',
     'Unit and end-to-end tests of the audit trail', 'UT-COR-013, UT-AUT-008, UT-INT-002, E2E-031, E2E-032, E2E-226, E2E-252'),
    ('SEC-12', 'Transport: where the hospital network is not private the system shall be served over HTTPS; the health page shall warn when it is not.', 'Medium',
     'Health page check; HTTPS itself is a server set-up task (Chapter 6)', 'E2E-249, DES'),
]

QUALITY = [
    ('QUA-01', 'Reliability: a failure in the middle of an action shall leave no half-saved data, and the system shall show a calm error page and keep working for other users.', 'High',
     'Unit tests of transactions; end-to-end tests of error handling', 'UT-COR-014, UT-PHA-016, E2E-246, E2E-247'),
    ('QUA-02', 'Availability: during clinic hours (08:00 to 17:00, Monday to Saturday) the system should be available 99% of the time. This depends on the server computer, the UPS and the network, so it can be confirmed only after installation.', 'Medium',
     'Not measurable before installation; supported by the UPS, the nightly backup and the health page', 'DES'),
    ('QUA-03', 'Usability: screens shall use plain words and the forms the staff already know; every field shall have a visible label; every error shall be shown beside the field with a way to correct it; colour shall never be the only signal (statuses are also written in words); pages shall also work on a narrow screen. Ease of use is preferred to ease of learning.', 'High',
     'Design review and screenshots (Chapter 5); user acceptance testing is still to be done', 'DES, UAT'),
    ('QUA-04', 'Maintainability: the code shall be arranged one file per module with the business rules apart from the pages, shall use meaningful names and short comments, and shall be covered by automated tests that a new developer can run in minutes.', 'Medium',
     'Code review; the unit and end-to-end suites', 'DES, UT-INT-006'),
    ('QUA-05', 'Portability: the system shall run on Windows or Ubuntu with XAMPP (Apache, PHP 8, MariaDB) and shall work in current versions of Chrome, Edge and Firefox. Only the combination Windows 11, PHP 8.2.12 and MariaDB 10.4.32, browsed with Microsoft Edge, has been tested.', 'Medium',
     'Tested on the stated combination only; other combinations are listed as untested in Chapter 7', 'APA-001, DES'),
    ('QUA-06', 'Interoperability: the system shall use ICD-10 for diagnoses and CSV files for import and export so that it can exchange data with spreadsheets and, later, with national systems. Direct connection to national systems is outside the scope.', 'Low',
     'Unit tests of ICD-10 lookup, CSV import and CSV export', 'UT-CON-008, UT-PAT-018, UT-REP-007'),
    ('QUA-07', 'Integration: information entered in one module shall reach the next without being typed again, so that one patient can be followed from registration to payment and the invoice, stock and audit records agree at the end.', 'High',
     'Integration tests of a complete patient journey', 'UT-INT-001, UT-INT-002, UT-INT-003'),
    ('QUA-08', 'Testability: each business rule shall be callable without the web pages, so that it can be tested directly.', 'Medium',
     'The unit suite calls the business rules directly', 'All 167 unit tests'),
    ('QUA-09', 'Accuracy of dates, ages and money: dates shall be real dates, ages shall be whole years counted from the date of birth, and money shall be exact to the cent.', 'High',
     'Unit tests of the helper functions', 'UT-COR-004, UT-COR-006, UT-COR-007'),
]
