# Column value integrity and image cells

## Confirmed incident

The workload chart read `tsp_display_name` correctly, but that field had received
signature URLs from an Excel import. The suggestion matcher allowed `TSP` to
match `TSP Signature`; the importer preferred that value over the real technician.
The read-only production check found 323 URL display names and 15 URL technician
identities among 4,024 imported technical reports. Some records had no technician
in the original source, so an attachment became the fallback identity.

## Shared fixes

- Attachment/signature labels cannot be suggested for unrelated import targets.
- Person values reject URLs and markup. Import identity and display names use
  independent valid fallbacks; missing names remain unassigned.
- Direct monday sync validates source/destination compatibility, matches column
  IDs regardless of response order, and refreshes both technician fields.
- monday returns type `file` (singular), which the old map did not recognize.
  The API now fetches FileValue metadata and raw values. Legacy file destinations
  typed as text are promoted when synced. Incompatible same-name columns are
  kept separate. Invalid saved core mappings fail visibly without writing a row.
- Links, numbers (including zero), locations, email, phone, and checkboxes use
  their typed/raw values rather than assuming display text is the stored value.
- File cells and existing text/link cells containing image URLs render thumbnails.
  Non-image attachments render named links. The original data remains exportable.
- monday protected resource IDs resolve through an authenticated, table-scoped
  file route. Expiring public asset URLs are cached for 45 minutes, never saved
  as durable database values. Images load lazily; page rendering makes no API calls.

## Repair existing reports

Run `php artisan monday:repair-technicians` to review counts without changes.
After approval, run `php artisan monday:repair-technicians --apply`.
The repair restores valid names from existing identity/display/raw source fields.
If none exist, it clears the false assignment and reports an unassigned count;
it does not invent a person. Raw source data and signature columns are retained.
The repair is transactional, idempotent, and invalidates the relevant dashboard caches.
No database migration is required.

Regression checks: `php vendor/bin/phpunit tests/Feature/MondayValueIntegrityTest.php tests/Feature/TableFileRenderingTest.php tests/Feature/RepairTechnicianNamesTest.php`
and `node scripts/test-table-files.mjs` (requires the installed Playwright browser).

If a saved monday core map points a file column at a person field, correct it
in **Map columns**. Saving increments the mapping revision, making the next sync
replay previously imported items under the corrected map. Do not silently rebuild
all mappings: owners may have deliberately chosen a mirror instead of an empty
same-name writable column.

Reference: [monday file values](https://developer.monday.com/api-reference/reference/files-1)
and [asset URLs](https://developer.monday.com/api-reference/reference/assets-1).
