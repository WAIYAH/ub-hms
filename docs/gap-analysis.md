# Phase 1: analysis and gap analysis

## 1. What the guides require

| Chapter | Guide | Required sections (in order) |
| --- | --- | --- |
| 4 | System Requirements Specification | Introduction (purpose, audience, scope); Overall description (user classes, operating environment, constraints, user documentation, assumptions); Functional requirements (REQ-n, system features with description, priority, stimulus/response); External interfaces (user, hardware, software, communications); Other non-functional requirements (performance, safety, security, quality attributes); Other requirements; References; Appendix A glossary |
| 5 | System Design Specification | Purpose and scope; System overview (DFD level 0 and level 1, each figure explained); System architecture; Software design (pseudo-code for each level 1 process, flowchart for each, structured chart); File and database design (normalised tables, ERD, physical description, access methods, size estimate, update frequency, data dictionary); Human-machine interface (input design, output design); System integrity controls; Project references; Glossary |
| 6 | System Implementation | Introduction; Architecture implementation (diagram, technology stack); Modules and components (inputs, outputs, logic, interactions, screens, user interactions, database interactions, error handling); Database implementation (schema, queries, backup and recovery, security, monitoring); User interface implementation; Development process; Security and authentication; Deployment (plan, server configuration); Appendices |
| 7 | System Test Plan | Introduction (purpose, scope); Testing approach (objectives, levels, manual vs automated, unit/integration/system/UAT); Test cases and results (ID, description, preconditions, steps, expected, actual, status); Bug fixing (tracking, resolution, known issues); User feedback and improvements; Appendices (screenshots, references) |
| 8 | System User Manual | Introduction (overview, target users, requirements); Getting started (installation, access, interface); Navigation and core functions; Advanced features and customisation; Maintenance and updates; Troubleshooting; FAQ; Glossary and index |

Notes on the guides: the Chapter 4 guide numbers "3" twice and has no "6"; sections are numbered
sequentially in the chapter while keeping the guide's order. The Chapter 8 guide is a template written
for a student enrolment system and is rewritten for UB-HMS. The data migration, training and changeover
topics requested in the brief are placed under 6.8 Deployment (the Chapter 6 guide has no separate heading for them).

## 2. What existed at the start

* The repository named `uhai-bora-hms` in `D:\SHELAX\System` is a different system (a public appointment
  booking website with three roles). It has no tests, no `docs/` folder and no six-module schema, and it
  conflicts with the proposal (patients do not use UB-HMS themselves, section 3.2.2).
* A MariaDB database `ubhms` with a compact twenty-table design and the eight roles was found on the
  XAMPP server, but no application code for it was found anywhere on the computer.
* Decision: the project owner chose to build UB-HMS fresh in a new folder, `D:\SHELAX\System\ub-hms`. The
  `ubhms` table design was used as the starting point for `database/schema.sql` (with additions), and the
  booking site and the `ubhms` database were left untouched.

## 3. Gaps closed by the build

| Area | Required by | Built in |
| --- | --- | --- |
| Authentication, eight roles, audit trail | Objective 5, Ch 4 security, Ch 5 section 7 | Sprint 1 |
| Patient registration, outpatient numbers, duplicate checks, search, correction, CSV import (data migration) | Objective 1, Ch 6.8 | Sprint 1 |
| Clash-free appointments with attended / missed / cancelled statuses | Objective 1 | Sprint 2 |
| Check-in, visits, triage, consultation, earlier visits in view | Objective 2 | Sprint 2 |
| Laboratory requests linked to the requesting doctor, results | Objective 2 | Sprint 3 |
| Prescriptions, dispensing with batch stock deduction, low-stock and expiry alerts | Objective 3 | Sprint 3 |
| Itemised invoices from recorded services, partial and full payments, receipts | Objective 4 | Sprint 4 |
| Attendance, revenue, stock and laboratory workload reports with CSV export | Objective 4 | Sprint 4 |
| Integration across modules, OWASP Top 10 review, backup and recovery scripts, health page | Objective 5, Ch 6.4 | Sprint 5 |
| Unit tests and end-to-end tests for every module | Ch 7 | Every sprint |
