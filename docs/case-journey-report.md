# Case Journey and Outcomes

This is a separate read-only report, not another dashboard status chart.

## Location

Reports → Case Journey and Outcomes, at `#/pages/reports/case-journey`.
Search, then choose **View journey** for a complete chronological case record.
PDF, Excel and Word exports use the last generated filters, not unsubmitted form edits. Summary exports contain every matching case and movements in the period. Selected-journey exports contain complete case context, with out-of-period rows marked.

## Definitions

- A case is one `patient_histories_id`, not one patient or referral number.
- Approval status retains the existing workflow codes and labels. It is not a treatment outcome.
- Finished and Death come from `hospital_letters.outcome`. A Closed referral alone proves neither.
- Each hospital referral has its own latest active visit/outcome. Visit date, then recorded letter/follow-up ID, establishes stable order. Repeated transfers are retained.
- Period visit counts use distinct letters with an actual matching follow-up row. Corrections, duplicated follow-up rows and print events do not add visits. Archived rows require the archive option.
- Activity dates include submission, workflow decisions/undo, referral creation, visits, recorded edits, boarded-out documents and permission-visible supporting records/prints/payments. Missing or malformed historical visit dates use the recorded letter date and raise a record warning.
- Destination/referral filters select cases; complete context retains all verified case referrals. Period visit metrics use selected destination hospitals.
- Referrals require an explicit matching case ID and patient ID. Parent/transfer destination links must stay within that case. Ambiguous or wrong-patient links are not repaired by reporting.
- The submitting facility follows the patient creator’s current hospital assignments, because the legacy data has no immutable case-level source-facility snapshot. Multiple assignments are listed; one is not arbitrarily chosen. Historical missing actors, decisions and previous field values are not invented.

## Permissions

Report access uses `View Report` and the existing SQL patient/hospital scope. Asking for a case ID does not bypass that scope. Basic outcomes are report data; clinical notes require existing follow-up or history access. Treatment, referral documents, travel and financial sections have separate permission checks. Print history retains its existing administrator/DG role restriction. Conversation content, private audit snapshots, document paths, IP addresses and user agents are not returned.

## Deployment

Deploy API and UI together. Apply the additive migration before enabling future change capture:

```sh
php artisan migrate --path=database/migrations/2026_10_10_120000_create_case_journey_events_table.php
```

The migration only creates `case_journey_events`; it does not rewrite clinical records or backfill guessed history. Model observers capture future follow-up creation, meaningful edits, soft deletion and restoration with before/after values and the authenticated actor. Existing explicit workflow/print events are reused. Before the migration, reporting still reads existing tables but new follow-up edit evidence is not captured. Direct SQL/bulk updates do not fire model observers and cannot reconstruct an unlogged historical edit.

No project or production migration was run during implementation. Tests use isolated temporary schemas.

## Search Report correction

`#/pages/patient/searchreport99990000` now has separate source and destination filters, table columns and PDF columns. For original referrals, source uses the submitting patient creator’s hospital assignments rather than the approval staff member in `referrals.created_by`. For verified transfers, source is the parent referral’s hospital; destination always uses the current referral’s hospital. Hospital type IDs are not hard-coded as source/destination gates. Sources, insurance and letters do not multiply referral rows. Medical-board diagnoses come from the explicitly linked case only, not every history of the patient. Active-record and existing hospital-user scope checks still apply. Unresolved case links remain visible with a separate warning.
