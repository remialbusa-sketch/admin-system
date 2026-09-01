# MCBTSi Admin System — Full Audit

**Date:** February 2026 · **Scope:** security & access control, data correctness, performance, frontend/UX, executive fitness
**Method:** full-code read of every app surface + three parallel deep-dive audits (security, data/performance, frontend/UX), findings cross-verified against source and the live SQLite database (172.9 MB; service_requests 8,126 · technical_reports 8,156 · historical_tsms 23,309 · installations 7,973 · accounts 1,142 · personnel 64). Test suite re-run: **55/55 passing, 232 assertions** (README says 54 — minor doc drift).

---

## Verdict

Solid engineering core with a genuinely good test suite, a disciplined design system, and honest data plumbing (upsert-by-source-identity, batch/failure tracking, no seeded KPIs). But the app is **not yet safe to expose beyond localhost**, has **data-truth bugs an executive will catch** (duplicated installations, a trend that measures imports instead of requests, a fake 100% SLA), and the **five-role model is decorative** — today there are exactly two access levels: "authenticated = read + export everything" and "Superadmin = edit". The executive experience is close: the home dashboard is well-designed but missing period control, print/PDF, full drill-down, and the region comparison it already computes.

---

## 1 · Security & access control

### CRITICAL

**C1. Open self-registration + disabled email verification = anonymous read access to all company data.**
- `routes/auth.php:7-9` exposes `register`; the login page advertises it ("Request access", `resources/views/livewire/pages/auth/login.blade.php:65`).
- `app/Models/User.php:5` — `// use Illuminate\Contracts\Auth\MustVerifyEmail;` is **commented out**. Laravel's `verified` middleware is a **no-op** for users that don't implement the contract, so the `['auth','verified']` group on every route (`routes/web.php:18`) is effectively `auth`-only.
- `.env:5` — `APP_URL=https://detract-amongo-spotting.ngrok-free.dev` (stale ngrok tunnel, flagged in CHANGELOG 2026-08-26 and **still not fixed**): the local app has been exposed to the public internet.
- Exploit: anyone reaching the app registers → instant access to every dashboard and table (hospital customers, device serials, requestor PII — name/email/phone in `app/Models/ServiceRequest.php:29-31`).
- **Fix:** implement `MustVerifyEmail`; replace open registration with invite/admin-approval (or disable the route); fix `APP_URL` before any deploy.

### HIGH

| # | Finding | Evidence | Fix |
|---|---|---|---|
| H1 | Filament `/admin` reachable by **every** authenticated user | `AdminPanelProvider.php:133-135` has only `Authenticate`; `User` lacks `FilamentUser`; vendor `Authenticate.php:32-37` allows all users when `APP_ENV=local` (`.env:2` is `local`) | Implement `FilamentUser::canAccessPanel()` (Superadmin/President only); set `APP_ENV=production` |
| H2 | `exportExcel()` is the **only ungated mutation** — any authenticated user bulk-exports all five tables (full dataset, pagination bypassed) | `ManagedTable.php:960-979` (no `abort_unless`; cf. 12 other gated methods) | Gate it; consider row-level scoping |
| H3 | `APP_DEBUG=true` + `APP_ENV=local` on a public tunnel → stack traces leak env/config on any 500 | `.env:3-4` | Set production values before deploy |
| H4 | `role` is mass-assignable on `User` (latent privilege escalation) | `User.php:15` `#[Fillable([...'role','region'])]` | Remove from fillable; assign via `forceFill` in admin-only paths |

### MEDIUM
- `trustProxies(at: '*')` (`bootstrap/app.php:17`) lets clients spoof `X-Forwarded-For` and rotate IPs past the login throttle (`LoginForm.php:70`).
- **Formula injection on export**: imported values are re-exported verbatim into xlsx; strings starting with `=` become formulas (`ManagedTableExport.php:32-35` + PhpSpreadsheet default binder). Sanitize `^[=+\-@]` in `buildExportRows` (`ManagedTable.php:981-999`).
- Upload validation is extension-only (`mimes:csv,txt,xls,xlsx`, `ManagedTable.php:920-921`); parsed synchronously (DoS hygiene; superadmin-only today). `setReadDataOnly(true)` is the one good mitigation.
- Session cookie flags: `SESSION_SECURE_COOKIE` unset, `SESSION_ENCRYPT=false`.

### Role model — what actually exists
- **Superadmin**: the only enforced role — solely via `ManagedTable::canEdit()` (`ManagedTable.php:85-88`), gating all 12 mutation entry points (inline edits, create, delete, bulk delete, custom columns/options, imports).
- **President**: `PresidentView` middleware (`app/Http/Middleware/PresidentView.php`) is aliased (`bootstrap/app.php:18-20`) but **attached to no route**; `/president` redirects to `/dashboard` (`routes/web.php:20`).
- **VpOperations / RegionalManager / NationalManager**: referenced **nowhere** outside the factory.
- Note: Help Center states "Only Superadmin can edit; Presidents and managers see tables read-only" (`HelpCenter.php:17`) — but `.impeccable.md` envisions *field coordinators* updating statuses/assignments. **The intended editor role model is an unresolved product decision.** Today nothing below Superadmin can maintain data, so all operational upkeep falls on one account.
- Default seeded credential: `test@example.com` / `Password!123` (`DatabaseSeeder.php:19-23`) — rotate before any shared deployment.

---

## 2 · Data correctness (bugs that lie to executives)

1. **Installations silently accumulate duplicates on re-import (HIGH).** `source_record_id` embeds a hash of the entire row (`SourceWorkbookImportService.php:808-813`), so *any changed cell* produces a new identity → `updateOrCreate` inserts a new row; the old is never removed. Live DB: **556 serial numbers on multiple rows, up to 12 copies** (e.g. serial `12266` ×12). Product/warranty/active KPIs drift upward with every re-import. *Fix:* natural-key upsert (`no` or serial+customer, without the row hash) + one-time dedupe. Personnel has the same scheme (`:311`).
2. **The 12-month "service request intake" trend measures import runs, not requests.** It counts `created_at` (row-insert time); the real business date is imported into `source_updated_at` (`SourceWorkbookImportService.php:141`). The MoM ▲/▼% on the exec home is import activity. It also **ignores the selected region** while every other widget respects it.
3. **The TSP "SLA" metric is always 100%.** `TspAnalyticsService.php:73` — `min(100, round(($completed / max(1, $completed)) * 100))` is a tautology. Violates the project's own "never fake a KPI" rule. Remove or compute against a real SLA target.
4. **Number fidelity on the exec home:** Avg repair time renders through `number_format()` with 0 decimals → **3.7h shows as "4h"** (`dashboard.blade.php:132` vs service float `PresidentDashboardService.php:263`); completion shows "93%" in the KPI but "93.4%" in the donut center (`:197`).
5. **Four divergent status-literal lists** keep counts agreeing only by luck: `['Active','ACTIVE']` (`PresidentDashboardService.php:49`, duplicated `ExecutiveDashboardService.php:18`); `whereNotIn('group_status', ['Completed','Resolved','Closed'])` whose 'Resolved'/'Closed' literals **can never match** the canonical normalized values (`PresidentDashboardService.php:85` vs `normalizeStatus()`, `SourceWorkbookImportService.php:433-452`); `whereNotIn('ticket_status', 6 casing literals)` on the raw column (`ExecutiveDashboardService.php:17`); a third variant in `TspAnalyticsService.php:39`. Measured consequences: open-request KPI (758, includes Rejected) ≠ TSP Analytics "open records" (681, excludes Rejected); NULL `group_status` rows fall out of region counts due to SQL `NOT IN` semantics; `''` values (48 `warranty_status=''` rows) vanish from every status bucket.
6. **TSP Analytics maps branch→region with a hardcoded exact-case const** (`TspAnalyticsService.php:12-17,41`) — unknown/renamed branches silently disappear from all regional widgets; the importer already stores a canonical `region` column — query it instead.

---

## 3 · Performance (measured)

| Render | Queries | Wall time |
|---|---|---|
| `PresidentDashboardService::summary()` | **44** | 358–419 ms |
| `ExecutiveDashboardService::summary()` | 20 | 160 ms |
| `TechnicalServiceAnalysisService::summary()` | 15 | 419 ms |
| TSP Analytics full page | 15 | 513 ms |

- **Zero caching anywhere** (`grep Cache::` = none) though the database cache store is configured. Every Livewire region change / property update re-runs the full 44-query summary. Dashboard data only changes on import — ideal cache candidate.
- **Trend loops:** 12 per-month COUNT queries = **162 ms (42% of the exec render)**, each a full scan via non-sargable `strftime`; same pattern (7-day loop) in `TechnicalServiceAnalysisService.php:43-50` and `ExecutiveDashboardService.php:29-35`.
- **Missing indexes** for routinely-queried columns: `service_requests.created_at/region`, `technical_reports.service_completed_at/service_started_at/tsp_name/repair_time_hours`, `historical_tsms_reports.service_type` (slowest measured query: **42.7 ms**)/`status`, `installations.device_status/warranty_status/pms_frequency`, `technical_personnel.region`. One migration ≈ 3–10× on affected aggregates.
- **Imports run synchronously in the web worker** (`ManagedTable.php:950`) with per-row transactions + retries (`SourceWorkbookImportService.php:665-679`) ≈ 8k transactions / ~30k queries for an 8k-row sheet; `QUEUE_CONNECTION=database` is configured but **no job classes exist**. A timeout leaves the batch stuck in `processing` (no `finally`).
- **Chunked XLSX read re-parses the whole workbook per 250-row chunk** (`processChunks`, `:620-644`) — ~93 full parses for a 23k-row sheet.
- **Exports are unbounded and fully in-memory**: `$query->get()` hydrates every row *including the `raw_data` JSON cast* (`ManagedTable.php:970`), then builds a second PHP array and an in-memory PhpSpreadsheet workbook (`FromArray` + `ShouldAutoSize`). Not streamed/queued.
- Portability traps: hand-written `strftime('%Y', …)` (`PresidentDashboardService.php:128`) breaks on MySQL/Postgres; `position LIKE '%Service%'` changes meaning on Postgres (case-sensitive); SQLite connection sets no `journal_mode=WAL`/`busy_timeout` (`config/database.php:35-45`) — import writers block dashboard readers.
- Repo hygiene: `db_accts.php` / `db_regions.php` are unguarded debug dump scripts at repo root; `.phpunit.result.cache` and `public/hot` present on disk.

---

## 4 · Frontend / UX (executive lens)

- **The region comparison went missing in a refactor.** The live dashboard drops the per-region table, personnel register and recent-history log that `PresidentDashboardService` still computes and ships every render (`$regions`, `$attention`, `$personnelTable`, `$historyTable`) — they survive only in the orphaned `president-dashboard.blade.php`. An exec's #1 question ("which region is slipping?") currently requires visiting four table pages.
- **No period selector and no print support on Home.** The only scope control is the region `<select>` (`dashboard.blade.php:82-86`); TSP Analytics has date presets but its view only exposes a hardcoded "Last 30 days" button — the 7/90-day code branches are unreachable. No `@media print` exists anywhere in `app.css`; browser-print clips the app shell.
- **Partial drill-down:** only 4 of 8 KPI cards carry `href`; the lead card's "Open table" falls back to a dead `href="#"` (`dashboard.blade.php:117`).
- **Bulk delete is offered to read-only users** (button outside the `@if ($editable)` guard, `managed-table.blade.php:76-79`) → guaranteed 403 (`ManagedTable.php:288`).
- **Dead / orphaned UI:** `PresidentDashboard` + its view (test-only), `ExecutiveDashboardService` (zero references, duplicated `statusTone()`), `president.view` middleware, `x-admin.drawer` (built, never dispatched — contradicts `.impeccable.md`'s "detailed item drawer"), `ManagedTable::$pmsFrequencyFilter`, and the whole parallel Filament panel at `/admin`.
- **Fake UI erodes trust:** Settings toggles ("Record activity notifications" etc.) have no `wire:model` and persist nothing (`settings.blade.php:19,26,33,48-52`); sidebar footer "All systems normal" is hardcoded (`sidebar.blade.php:153-167`).
- **Grid reality vs. promises:** no Tabulator keybindings (keyboard claims unmet; sort/freeze are pointer-only via `headerClick`/`headerContextMenu`); row-click binds both selection *and* editing; stale search highlights never unwrapped (`managed-table.js:612-643`, XSS-safe though); only the import button has `wire:loading`; save failures surface as an unattributed "Save failed".
- **Accessibility:** light-mode warning text ~2.5:1 contrast; pervasive `text-base-content/35–45` labels below AA for 10–11px text (exactly what execs read); KPI cards/nav links have no visible focus treatment; no skip link or `aria-live` anywhere; donut SVGs and sparklines lack `role="img"`.
- **Duplication:** status/tone mapping copy-pasted across 6+ PHP/Blade/JS sites; region list triplicated.
- Help Center: 4 FAQs, omits the grid's core interactions; no Superadmin contact affordance.

---

## 5 · What's genuinely good (keep)

- Healthy, meaningful test suite (55/55) covering auth, imports (incl. the PDB manual-field invariant), normalization, every table and both dashboards.
- Real data plumbing: `source_system + source_record_id` unique identity, batch + per-row failure tracking, natural keys on SR/TR/TSMS, manual-only-field rules enforced and tested.
- No hardcoded dashboard KPIs (the SLA/intake bugs above are computation errors, not faked data).
- Grid engineering: server-side pagination/sort/filter, custom columns with indexed shadow columns, per-user layout persistence, escaped formatters (XSS-safe), debounced search, one-batch `saveGridChanges` to dodge a Livewire 3 batching bug.
- Design system discipline: DaisyUI tokens, theme-aware server-rendered SVG charts (zero JS re-init on theme toggle), consistent `admin-*` components, theme pre-paint snippet.
- Changelog discipline and a prior self-audit trail.

---

## 6 · Recommended roadmap

### P0 — Trust & safety (before any rollout beyond localhost)
1. Lock down auth: `MustVerifyEmail` on `User`; close/replace registration with invite or admin approval; remove `role`/`region` from fillable; `canAccessPanel()` for Filament; production `.env` values (`APP_ENV`, `APP_DEBUG=false`, real `APP_URL` — still the stale ngrok URL, `SESSION_SECURE_COOKIE=true`); configure real mail (verification/password reset currently go to a log file).
2. Gate `exportExcel()`; decide an export policy per role.
3. Fix data-truth bugs: dedupe installations + natural-key upsert; intake trend on `source_updated_at` (+ respect region filter); delete or implement the SLA metric; render hour/percent KPIs with 1 decimal.
4. Wire or remove the fake UI (Settings toggles, "All systems normal" chip).

### P1 — Executive dashboard fitness
5. Period selector (12M/6M/90D/30D) + prior-period deltas on KPI cards.
6. Restore the region comparison strip from already-computed data; each row links to the filtered table.
7. Complete drill-down: `href` on all 8 KPIs (no dead `#`), click-through on donut/segments.
8. Board-pack export: `@media print` stylesheet (hide chrome, force light tokens, avoid breaks) + "Print report" action; or a dashboard→xlsx/pdf snapshot.
9. Performance: `Cache::remember` per-region summary invalidated on import; one `GROUP BY` per trend; add the ~10 missing indexes. 44 queries/~400 ms → effectively instant.
10. Real role scoping: auto-scope RegionalManager/NationalManager dashboards to `users.region`; enforce `president.view` on a proper route (or delete it); one shared `statusTone()` source; resolve the "who may edit?" product decision (expand `canEdit()` to a permission, not a role-name check) so coordinators can maintain data if that's the intent.

### P2 — Betterment
11. **User & role management UI** (create/deactivate users, assign roles/regions) — currently DB-only; required to actually onboard executives.
12. **Notifications & digests:** escalation alerts (open-request thresholds, stale escalations, import failures) + a weekly executive email summary; needs the mail fix first.
13. **Queued imports** with progress UI (queue is configured, unused), chunked `upsert()`, single-pass XLSX reader, `finally` batch-state handling.
14. **Monday.com API integration** — the workbooks (`MCBTSI_Executive_Dashboard_Updated.xlsx` etc.) appear to be manual exports from Monday.com; a pull integration would remove the whole manual export/upload loop while keeping the imported-data source-of-truth rule.
15. **Audit trail of manual edits** (who changed which cell — only import batches are tracked today; the first question after any number moves is "who changed this?").
16. Hygiene: streamed/queued exports, SQLite WAL + `busy_timeout`, remove debug scripts/dead code, FTS5 search when tables grow, keyboard bindings for the grid.

---

*Findings sourced from a full-repo audit on the dates above; file:line references were verified against the working tree. Performance figures measured on the live SQLite database; treat absolute times as indicative (they grow with data).*