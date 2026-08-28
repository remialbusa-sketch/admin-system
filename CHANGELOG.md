# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
- PDB (product database) import wrote `pms_frequency` and `tsp_in_charge` as empty strings instead of `null`, polluting manual-only fields on auto-imported installation records. They now stay `null` until filled by hand (`SourceWorkbookImportService::nullableValue()`). Restores the invariant asserted by `SourceDomainSchemaTest`.
- `README.md` replaced Laravel boilerplate with real project documentation (quickstart, domain rules, testing, deployment notes).
- Region resolution across import paths unified into a single `resolveRegion(array $data, array $keys)` resolver with explicit per-source fallback priority (PDB: `branch → region`; Executive SR: `branch → regions → assign_region`; Personnel: `branch`). Behavior preserved; call sites can no longer drift apart.

### Changed
- Dashboard "Record intake" chart rebuilt on Chart.js (real axes, tooltips, theme-aware) replacing a hand-rolled div chart that mislabeled values (`value * 10`) and hardcoded a fake "3,892 total". Total is now computed from imported-batch data; values shown are the real row counts; an SR-only text + `<noscript>` table are the accessible fallback. Empty/zero-data state added.
- Dashboard metric cards: replaced the repeated "Live" label on every card with real per-metric context (imported totals, latest-import freshness, batch counts) and semantic open-count tone.
- Status badges: added `statusTone()` mapping all canonical service-request statuses (completed/resolved/closed → success; open/new/unassigned → info; in-progress/ongoing/for-continuation → warning; rejected/cancelled/for-escalation → danger) so no status renders ambiguous gray.
- Mobile touch targets: grid delete/edit/list action buttons raised to >=44px under 640px (WCAG 2.5.5) while staying dense on desktop.
- Re-designed the operational surface (grounded in NN/g dashboard research + Pencil&Paper/UX Planet/LogRocket enterprise-table guidance):
  - Grid density control (Condensed/Standard/Comfortable = 36/48/58px), per-table, persisted to localStorage.
  - Row multi-select with a frozen checkbox column (select-all header) + a bulk "Delete N selected" button that appears only when rows are selected (editors only).
  - Decluttered grid: removed per-cell vertical borders, added zebra striping, softened header & row chrome.
  - Search term is highlighted inline in matching grid cells (live, as you type).
  - Rounder theme tokens (radii + crisp indigo-slate primary) kept in the existing DaisyUI light/dark token pair; page-header eyebrow now a pill.

### Fixes (from integration)
- Dashboard intake chart now renders: chart init hooks `livewire:navigated` (was DOMContentLoaded-only, which never fires after SPA in-app navigation) and trend JSON is embedded via Blade `@json()` so quotes aren't HTML-escaped (`json_encode` had produced `&quot;`, breaking `JSON.parse`).
- Tabulator 6.5 row-selection options corrected (`selectableRowsRangeMode`, explicit `rowSelection` formatter column; `selectableRowsCheck`/`selectableRowsCheckbox` are 6.5 callbacks, not booleans).

### Audit notes (2026-08-26)
- Full test suite green: 54/54 (230 assertions).
- Verified: no hardcoded/faked KPIs in dashboard services; Active TSPs correctly derived from service/field personnel positions.
- Flagged, not yet changed: `.env` `APP_URL` pointed at a stale ngrok URL — update before deploying. Region resolution inconsistency since resolved (see Fixed).
