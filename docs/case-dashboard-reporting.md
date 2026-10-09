# Case dashboard and reporting

One medical history ID is one case. A patient can have several cases, and a
case can have several hospital referrals. These are different measures.

The report table shows the Matibabu card instead of internal case/patient IDs.
The referred hospital column lists distinct destination names for that exact
case; several destinations are separated by a semicolon. IDs remain internal
so case links and counts stay unchanged.

The dashboard and Case Workflow Report use the same query and access rules.
Dates mean **case submission date**. Status means the case's **current** status,
not its status at a past date. Archived patients and histories are excluded
unless the archive option is selected. Source hospital uses the existing
mapping from the patient creator to their hospital assignment; it is not a
historical snapshot of the submitting facility.

The dashboard shows all active cases without a filter card. Date, hospital,
status and archive filters remain on the report page. A dashboard card or
chart stage opens the matching active-case report.

Dashboard percentages show the share of cases. Individual `status_tracking`
and `progress_percentage` still describe the case's workflow stage. The
Workflow overview / Status Tracking panels and their actions are retained.

Reports added to the report selector:

- Case Workflow Report: one row per case.
- Boarded-out Case Report: one row per boarded-out case, including standalone
  cases without a hospital referral.
- Patient Summary: one row per patient, using the latest eligible case within
  the selected filters. This is not the total case count.

PDF, Excel and Word exports contain every matching row, not just the preview
page. Exports reuse the last generated filter selection.

## Deployment

Back up the database first. Deploy the API before the updated dashboard UI.
Pause writes during the case-link migration, then run the pending migrations
from the API directory:

```sh
php artisan migrate --force
php artisan optimize:clear
php artisan cases:audit-links
```

The pending migrations for this change are:

1. `2026_10_09_000000_add_workflow_dashboard_indexes.php`
2. `2026_10_09_010000_add_case_boarded_out_status_and_referral_link.php`
3. `2026_10_09_020000_link_referrals_to_medical_history_cases.php`

The last migration links legacy referrals only when there is unambiguous
evidence: an explicit workflow event, a linked boarded-out letter, a parent
referral's verified link, or exactly one history for that patient. It never
chooses the patient's latest history simply because it is newest.

Unresolved or conflicting links are listed by the read-only audit command.
Review the original case and decision evidence before assigning them. Their
referral screen shows a warning and prevents a case decision without a verified
link. They do not change the case count in the dashboard.

Cancelled DG decisions now set the linked case to `rejected`, rather than
`confirmed`. Existing historical decisions are not silently rewritten.

Rollback of the boarded-out status migration converts `boarded_out` back to
`confirmed`; use a database backup if the original decision must be restored.

## Checks

```sh
php artisan test --filter='CaseReportTest|ReportModuleTest|ReportingControllerTest|TopDiagnosesTest'
```

The database regression tests use an isolated SQLite in-memory database by
default. On PostgreSQL-only PHP they can use a disposable local cluster with
`CASE_REPORT_TEST_PG_SOCKET` set to a socket under
`/private/tmp/eris-case-tests.*` (port 55439, database `postgres`, user
`case_tests`). They never use the application's configured database.

After deploying, compare dashboard total cases against the Case Workflow
Report using the same dates, hospital, status and archive selection. The
referrals list and patient list may have different totals because they count
different things.
