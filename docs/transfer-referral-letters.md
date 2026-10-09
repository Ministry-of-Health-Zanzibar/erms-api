# Transfer referral letters

When a follow-up outcome is `Transferred`, the existing flow creates a child
referral for the new hospital. It now also creates that child's own referral
letter in the same transaction and stores `hospital_letters.transferred_referral_id`.
Patient, medical-history case, parent referral, reason and diagnoses are retained.
No medical-board/DG stage is changed, and no additional medical history is created.
The original referral, letter and print history are not modified.

The Patient Referral Information and Medical History Details page prints the
original hospital's letter, following verified parent links back to the first
referral. Its PDF, language, flight information and print tracking belong to that
original referral. This also applies when medical-history mode displays a newer
transfer. Broken or cross-case ancestry is not guessed.

The Transferred follow-up print action prints the transferred hospital's separate
letter. Both actions reuse the existing official template and File viewer.
Hospital type determines the language. Flights belong to the destination referral; old flight details are not
copied. The letter date is the child referral creation date, not the original
letter date or a future appointment date. Follow-up appointment dates are retained.

Ordinary Follow-up printing still uses the follow-up template. Finished and Death
do not gain an attendance print action. Uploaded supporting files remain separate
from the official transfer letter and cannot mark that official letter as printed.
Transfer printing requires the existing referral-letter permission; the legacy
follow-up PDF/print endpoints also enforce it for Transferred entries.

## Deploy

Back up the database, deploy the API, then run:

```sh
php artisan migrate --force
php artisan optimize:clear
php artisan referrals:repair-transfer-letters
```

Deploy the updated UI after the migration. The migration adds nullable unique
transfer and submission links and conservatively repairs existing missing letters.
The audit command is read-only by default; `--apply` saves verified repairs.
Do not use `migrate:fresh`.

A legacy child letter can be prepared only with a matching parent, patient and
medical-history case, an active hospital, and an existing approved source letter.
A legacy follow-up can be linked automatically only when there is exactly one
possible transferred child and exactly one transfer event under the parent, or
when an explicit link already exists. Ambiguous IDs are reported for manual
review; the system never picks the newest referral/history or returns a letter
addressed to the wrong hospital. Archived letters are not recreated.

New UI submissions carry a UUID submission key. Retrying an identical request
returns the saved result rather than creating another transfer. Reusing the key
with different details is rejected. Editing a linked transfer does not create
another referral or change its destination; record a new transfer for a new
hospital. These keys are optional for existing API clients.

PDF GET requests do not create or modify letters. An unresolved legacy transfer
returns a useful review message instead of a misleading missing-route error.
