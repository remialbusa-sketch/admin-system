# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed — TSP names and donut color uniqueness
- **TSP names showed workbook IDs** (`person-77787508`) instead of real names: every TSP surface now uses the source-grounded `tsp_display_name` mapping with ID fallback — the Technical Service Analysis workload widget, the TSP drill-down chip (resolves the display name for the raw filter value), the Technical Reports grid (adds a read-only "TSP Name" column next to the raw "TSP ID"), the TSP Analytics per-TSP performance table, and its filter dropdown (display-name labels, ID values).
- **Donut colors**: brand donuts (Home + Technical Service Analysis) were reusing/cycling semantic UI tones — the TSA one was a single flat color. A dedicated 8-hue categorical palette (`--chart-1…8` in app.css, via `App\Support\ChartPalette`) now gives every segment of a categorical donut its own distinct color, consistent via the shared legend, with "Others" neutral. Status/fleet donuts keep their meaningful state colors (success/warning/error).

### Changed — Technical Service Analysis rebuilt around Technical Reports
- The TSA page is now a full **Technical Reports overview** in the Home design language: KPI band (reports / completed / assigned TSP / avg repair time) plus a secondary strip (avg response, completed-in-window, unassigned reports), a coordinated-hover status-mix donut + full status breakdown, an interactive completion trend, TSP workload rows, and a most-serviced-brands donut — **every widget deep-links into the filtered Technical Reports grid** (`?status=`, `?tsp=`, `?brand=`, `?customer=`, `?assigned=1|0`, `?completed=any|YYYY-MM-DD`, `?completed_from=&completed_to=` for weekly buckets).
- Period selector (7D/30D/90D) drives the completion trend: per-day bars for ≤14 days, weekly buckets beyond. The old per-day full-scan COUNTs were replaced with one indexed `date()` GROUP BY; summaries are cached (`tsa:summary:*`, 5 min) and invalidated by executive-dashboard imports (the Technical Reports source). The reports grid shows drill-down chips with a Clear-all, mirroring the Product Database behavior.

### Added — interactive drill-downs (widget → source of truth)
- Every Product Database overview widget now deep-links to the exact rows behind its number in the Product Database grid: KPI cards (`?status=Active`, `?warranty=covered|expiring_90d`, `?contract=1`, `?pms=missing`, `?status=Pulledout`), region cards (`?region=…`), fleet-state donut segments + legend (`?status=…`), brand donut (`?brand=…`), machine-type rows (`?machine_type=…`), trend dots per month (`?installed=YYYY-MM`), and top-account rows (`?customer=…`).
- The grid hydrates those URL-bound filters (with a mount guard so deep links survive the status default), shows them as visible "Drill-down from dashboard" chips, and offers a Clear-all. Covers the read path end to end with 10 new tests.
- **Hover polish across the dashboard**: both donuts get coordinated Alpine-driven focus (hover a segment → its legend row lights up while the rest dim and the segment's stroke thickens; hover a legend row → same, with a scaling color dot), trend points glow + grow on hover (CSS-animated halo + radius), KPI cards lift with a soft brand shadow, region cards lift with shadow, machine-type rows slide in a chevron and brighten their bar, account rows tint and reveal an arrow, and attention cards glow in their own tone (error/warning/success). Footer notes the click-through affordance.

### Changed — Home is now a Product Database overview
- The executive home was rebuilt around the **installed base** instead of all tables: lead KPI (installed products) plus active/warranty/contract posture, annual BU charges, missing-PMS data quality, warranty outlook (expiring ≤90d / expired) and pulled-out counts, a regional position strip (installed/active/warranty per region), device-status fleet donut, installations-per-month trend on the real `installation_date` with window-over-window delta, leading-brands donut, machine-type mix, and largest installed accounts. Service-request, TSP and TSMS widgets moved out to their own pages/analytics.
- `PresidentDashboardService` renamed to `ProductDashboardService`; fleet/status buckets use casing-safe `lower(trim(…))` matching against the real workbook values. Digest (`app:send-exec-digest`) keeps its own compact ops+product computation so the leadership summary still covers service operations. Cache keys moved to `product:summary:*` and are now dropped only by product_database imports and the dedupe.

### Added (P1/P2 roadmap)
- **Period selector** on the executive dashboard (Last 6M / 12M) driving the intake trend, and the intake delta is now window-over-window ("vs prev") instead of month-over-month.
- **Full KPI drill-down**: all 8 KPI cards link to their most relevant table, and the "Work by status" rows deep-link to `/service-requests?status=…` (statusFilter is now URL-bound via Livewire's `#[Url(as: 'status')]`).
- **Print/PDF board pack**: print stylesheet (chrome hidden, surfaces kept whole, light tokens) + a "Print report" action on the dashboard header.
- **Region-scoped executives**: Regional Managers are automatically scoped to their own region (`users.region`) with the selector locked; national/president roles keep the global view.
- **User & role management** (`/users`, Superadmin-only, `can:manageUsers` gate): create accounts, assign roles/regions, reset passwords — roles applied via explicit `forceFill` since they are not mass-assignable.
- **Weekly executive digest**: `app:send-exec-digest` emails leadership (President/VP Ops/Superadmin) the headline numbers, intake momentum, attention signals and region table; scheduled Mondays 07:00 (`routes/console.php`). Works with any configured mailer.
- **Manual-edit audit trail**: `record_edit_logs` records who created/updated/deleted which record and the old → new values; the Tables page shows the 12 most recent manual edits. Import-driven changes remain tracked via import batches.
- **Import robustness**: batch row counters are flushed per chunk instead of per row (one UPDATE per chunk, not per row), structural failures now mark the batch `failed` instead of leaving it stuck in `processing` forever, chunk reads raised to 1000 rows (4× fewer workbook re-parses), and fallback row identities use the actual file row number.

### Deferred (need external dependencies)
- Queued imports (requires a running `queue:work` worker; the synchronous path is now much cheaper) and the Monday.com API pull integration (requires API credentials) are documented as future work in `AUDIT.md`.

### Security
- Access model overhauled from the audit (see `AUDIT.md`): `User` now implements `MustVerifyEmail` (the `verified` middleware was a silent no-op) and Filament's `FilamentUser` contract (`/admin` limited to Superadmin/President in every environment). Self-registration is disabled by default (`ALLOW_REGISTRATION=false`, `config/features.php`); `role`/`region` are no longer mass-assignable; `exportExcel()` is role-gated; `trustProxies('*')` replaced with a `TRUSTED_PROXIES` env (empty by default) so login throttling can't be bypassed with spoofed `X-Forwarded-For`. `.env` `APP_URL` fixed (was a stale ngrok tunnel) and `.env.example` documents the production checklist.
- Exports neutralize spreadsheet formula injection (`=`, `+`, `-`, `@`, tab, CR prefixed with `'`).

### Fixed
- Installations no longer accumulate duplicate rows on re-import: the PDB source id now hashes the business identity (customer, serial, device description) instead of the whole row, and `dedupeProductRows()` collapses superseded versions after each import (also available as `php artisan app:dedupe-installations` — removed 273 stale rows from the live database). Personnel source ids keyed on name.
- The executive "Service request intake" trend now measures the real business date (`source_updated_at`, the workbook's Date Created) and respects the region filter — previously it counted row-insert times (import activity) and ignored the region scope. The 12 per-month COUNT scans were replaced with one indexed GROUP BY.
- TSP Analytics: regional counts come from the canonical `region`/`group_status` columns instead of an exact-case branch→region PHP map that silently dropped unknown branches; the tautological "SLA" metric (always 100%) was removed.
- Avg repair time and completion-rate KPIs render with one decimal (3.7h displayed "4h" before); the lead KPI card no longer renders a dead `href="#"` fallback.
- Widget honesty: decorative Settings toggles (unwired, non-persistent) replaced with real preferences; the sidebar's hardcoded "All systems normal" chip now shows actual last-import freshness; Help Center FAQ corrected/expanded.

### Changed
- Dashboard summaries are cached per region scope (`exec:summary:*`, 5 min) and invalidated by the import service on batch completion/failure. Measured on the live database: 44 queries / ~384 ms per render → 35 queries / 130 ms cold, 0.6 ms cached.
- New migration adds the indexes the dashboards aggregate on (`service_requests.region/source_updated_at/created_at`, `technical_reports.service_completed_at/service_started_at/tsp_name/repair_time_hours`, `historical_tsms_reports.service_type/status`, `installations.device_status/warranty_status/pms_frequency`, `technical_personnel.region`); SQLite connection now uses WAL + 5 s busy_timeout so imports don't block dashboard reads.
- Dashboard home gained a "Regional position" strip (per-region products/TSPs/open + RAG dot) using data the service already computed; attention cards reduced to real actions; section numbering updated.
- Dead layer removed: `PresidentDashboard` component + view, `ExecutiveDashboardService`, the unused `president.view` middleware, `ManagedTable::$pmsFrequencyFilter`.

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
