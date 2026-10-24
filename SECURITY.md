# Security

UB-HMS holds sensitive personal data when used for real, so it is built defensively. The full review against the
OWASP Top 10 is in [docs/security-review.md](docs/security-review.md).

## Before using UB-HMS with real patients

1. Serve it over HTTPS and keep the server software patched.
2. Run `php database/create_app_user.php --password=...` and put the new account in `config/config.local.php`
   so the web application does not use `root`.
3. Delete or deactivate every demonstration account; change the administrator's one-time password.
4. Schedule `scripts/backup.ps1` every night and practise a restore.
5. Complete the hospital's data-protection registration and impact assessment (Data Protection Act, 2019).
6. Review the audit trail regularly (Administration > Audit trail, or the Hospital Manager's view).

## Reporting a problem

Tell the system administrator, or the project supervisor, and do not post details publicly.
Include what you did, what you expected, and the time. Never include real patient information in a report.
