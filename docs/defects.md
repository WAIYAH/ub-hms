# UB-HMS defect log

Defects found by the automated unit tests, end-to-end tests and manual checks during development.
Each entry records how it was found and how it was fixed and retested. This log is the source for
Chapter 7 (System Test Plan), section 7.4.

Severity: Critical (security or data loss), High (feature unusable), Medium (feature wrong in some cases), Low (cosmetic).

| ID | Sprint | Found by | Severity | Description | Steps to reproduce | Root cause | Resolution | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| DEF-001 | 1 | UT-AUT-004 | Medium | Account locked after 4 wrong passwords instead of 5. | Enter a wrong password four times, then check `locked_until`. | In the SQL `UPDATE`, `failed_attempts` was incremented before `locked_until` was calculated, and MySQL/MariaDB evaluate `SET` clauses left to right, so the lock test saw the new count. | `locked_until` is now assigned before `failed_attempts` in `includes/auth.php`. | Fixed, UT-AUT-004 passes |
| DEF-002 | 1 | UT-AUT-007 | High | The per-computer sign-in throttle never blocked anyone. | Make 20 failed sign-ins from one address, then sign in correctly from it. | `audit()` recorded the web server's own view of the address instead of the address given to `attempt_login()`, so the throttle query found no matching rows. | `audit()` now accepts the address explicitly and `attempt_login()` passes it. | Fixed, UT-AUT-007 passes |
| DEF-003 | 1 | E2E-025 to E2E-027 | Low | A patient with no middle name was displayed as "Amani  Kiplagat" (two spaces), which broke text matching and would show in exported lists. | Register a patient without a middle name and open the search results. | `patient_full_name()` joined first, middle and last name with fixed spaces even when the middle name was empty. | Empty parts are now skipped when the name is built. | Fixed, E2E-025 to E2E-027 pass |
| DEF-004 | 5 | Screenshot review | Low | Table columns (Actions, Details, Expiry) were cut off, and outpatient numbers, dates and amounts wrapped onto several lines, on a 960-pixel screen. | Open User accounts, Audit trail or the Stock report in a browser window 960 pixels wide. | Wide tables with generous padding and wrapping numbers. | Table padding and text size reduced; outpatient numbers, dates and amounts kept on one line; account buttons may wrap. Screenshots retaken and all suites re-run. | Fixed, screenshots checked; 167, 185 and 185 tests pass |
