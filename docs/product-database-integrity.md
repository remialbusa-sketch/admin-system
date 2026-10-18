# Product Database: live inspection and repair

The signed-in production inspection confirmed 8,203 products: 7,847 imported
and 356 monday-sourced rows. All 356 monday rows had empty native Brand,
Serial Number, BU No., and Device Status while the mapped source cells held
the values. Device Description held the item title (`SN-...`). The records and
saved mapping were both revision 0, so the newer mapper never replayed them.

The live dry run proposes 1,442 product updates / 3,874 field updates:
356 monday rows and 1,086 imported rows with recoverable uninstallation dates.
Ten invalid source dates are skipped. Counts must be reviewed again before apply.

## Corrections

- `monday:repair-products` plans from stored typed cells without network I/O.
  It fills missing canonical fields and replaces an old `SN-...` title only
  when it matches the stored Connection reference and a real Model is present.
  Existing differing native values, ambiguous sources, and invalid dates are
  preserved. Queries scope cells to the product table and original row ID.
- `--apply` makes the repair transactional and invalidates product/dashboard
  caches. A changed monday row advances the saved mapping revision once so a
  future owner-enabled sync can replay old items. The live-pull toggle is retained.
- The product import recognizes Model, System Type, Address, and Uninstallation
  Date aliases. The resolver excludes aliases from competing custom targets.
  All exact header/key/alias matches are reserved before fuzzy matching, preventing
  Installation Date from consuming Uninstallation Date.
- Canonical fields backed by source columns remain available even if all current
  native values are empty. Native source twins default to hidden and receive a
  `(source)` label when shown. Users can reveal them; freeze/reorder operations
  preserve visibility. Account/source branch and address differences stay visible
  for review because accounts are shared across products and imports.
- Eight live QR cells contain comma-separated URLs. The shared attachment decoder
  splits at URL boundaries and validates each URL, allowing several image assets
  to render in the existing image-cell formatter and authenticated file route.

## Verification

`ProductDatabaseIntegrityTest` covers the live shapes, native import targets,
default/source visibility, preserved manual values, dry-run/idempotent repair,
invalid dates, and cross-table row-ID isolation. The QR, alias, and duplicate-column
regressions fail against production commit `4962e28` before the corrections.

Production changes require approval. Before applying, retain a private backup of
the affected native fields, confirm the current dry-run counts, deploy the code,
then run `php artisan monday:repair-products --apply` and inspect the signed-in grid.
No schema migration is added.
