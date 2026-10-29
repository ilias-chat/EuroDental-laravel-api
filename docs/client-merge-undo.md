# Undo client merges

Deploy the audit schema before the API code. Run the scoped migration:

```bash
php artisan migrate --path=database/migrations/2026_10_29_230000_add_undo_to_client_merge_audits.php --force
```

For phpMyAdmin, use `database/sql/client_merge_undo.sql` instead. Choose one method. Neither method changes client data or merges existing clients.

New merges record each transferred record ID, its post-merge fingerprint, the source client's original attributes, the survivor's post-merge fingerprint and any moved webshop profile. Credentials and revoked sessions are not stored or restored. Old audits remain readable but cannot be undone automatically.

In the CRM's Fusionnes tab, Annuler la fusion opens a read-only preview. Confirmation requires typing `annuler`. Both the preview and execution verify the active audit, source ID availability, survivor attributes, required city/image records, and transferred records. Deleted, edited or reassigned records result in HTTP 409. No partial restoration is committed.

Undo restores the source with its original ID and timestamps, transfers only recorded IDs back, restores the original profile fields, records the actor and reversal time, and returns the pair to pending review. New activity on the survivor stays there. A second undo is rejected. Later merges of the same pair create a fresh audit.

Reviewing, merging and undoing require the existing `clients_write` permission. Audits and undo payloads are never returned in full to the browser.
