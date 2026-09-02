# monday.com Integration + Shared cPanel Deployment — Plan (rev 6, dynamic tables + new-item detection)

Status: **Draft for review** · Applies to the MCBTSi Admin System (Laravel 13 / Livewire 3.8 / Filament 4).

Reference sources this revision reconciles against:

- `Board_System_Full_Stack_Plan.md` — original full-stack plan (board-system + Monday sync design, §6 webhook flow, §12 hosting notes).
- `Obsidian Vault/GitHub Copilot - Admin System Implementation Plan.md` — the pivot plan (managed source domains; Phase I = Monday.com integration rules).
- `Obsidian Vault/03-Tech-Notes/*` and `Obsidian Vault/02-Work-Log/2026-08-19-Session-02-Hosting-Research.md` — locked decisions, versions, hosting facts.
- The live codebase: `SourceWorkbookImportService` identity pipeline, `table_custom_columns`/`table_custom_column_values`, migrations `2026_08_24_000001` (board subsystem dropped), routes, gates, `Settings` Livewire page.
- Owner refinements (2026-08-26, final):
  1. **Only newly created monday items** are fetched — never the whole table at sync time.
  2. **No condition gate.** Every new item from a connected board is imported; correctness is handled by the column mapping, not by a filter.
  3. **Three boards connect**: monday **Tickets** → `service_requests`; monday **Product Database** → `accounts` + `installations`; monday **Technical Reports** → `technical_reports`.
  4. **Backfill = manual monday export → existing workbook import**. The live pull is *only* for new items.
  5. **Per-table UI toggle** (Settings) activates/deactivates the live pull per board.
  6. **Transport**: delta scan now, webhook at deployment.
7. **Dynamic tables (UI-built)** — implemented in M-DT: a **"New table" button** in the Tables section opens a modal to **name the table and define its columns while building it** (14-type registry), plus an **Import (backfill)** action and a **"Connect monday.com board"** action with a **live-pull toggle on the table page** — so any monday board can be fitted with a matching table the user creates (Part C).

**Field mappings** live in [`docs/monday-board-mappings.md`](monday-board-mappings.md) — the per-board column ↔ table mapping built from the real schema; confirmed column ids land there from `monday:inspect-board`.

---

## 0. Locked decisions

| Topic | Decision |
|---|---|
| Integration direction | **Pull-only, create-item scoped.** Monday boards are a *data source*; only **newly created items** are fetched into the managed tables. No condition filter, no write-back, no OAuth app. Matches pivot-plan Phase I: *"Keep Monday source identity separate from historical Excel source identity. Do not replace imported history with live synchronization."* |
| Connected boards | 1. **Tickets** → `service_requests` · 2. **Product Database** → `accounts` + `installations` · 3. **Technical Reports** → `technical_reports`. Mapping per board in `docs/monday-board-mappings.md`. |
| Backfill | **Manual**: owner exports updated tables from monday.com and imports them through the **existing workbook import pipeline** (idempotent by per-source identity). The live pull toggle does not affect this. |
| Live pull control | **Per-table UI toggle** on the Settings page — on = scheduled new-item pull for that board, off = no fetch (no quota spent). |
| Auth / transport | monday API v2 **personal token** (read queries + webhook registration). **Delta scan first** (works before a public URL); **webhook at deployment** for true real-time. |
| cPanel host | **Same account as `portal`/`customer-portal` on `mcbtsi.com`** (siblings already deployed there). Subdomain TBD, e.g. `admin.mcbtsi.com`. |

### Project history this plan must respect (why the design is what it is)

1. **Original plan** (`Board_System_Full_Stack_Plan.md`): designed a Monday.com-*style* board engine (`boards`…`column_values`, 14-type registry, `monday_*` linking fields). §6 specified the **webhook** as the correct mechanism for new-item sync ("`items_page` has no 'created after' filter … a registered webhook … is what Monday recommends for this exact case") with `triggerUuid` dedup and a 5-second-budget → queued refetch flow.
2. **Pivot** (`GitHub Copilot - Admin System Implementation Plan.md`): managed source domains, Excel workbooks read-only, idempotent source-identity imports; Monday sync deferred to Phase I with identity-separation rules.
3. **Board engine dropped** (migration `2026_08_24_000001`): `boards`/`items`/`column_values`/`monday_sync_progress`/`monday_webhook_events` removed as orphaned. The column-registry DNA survives as table-scoped custom columns.

**Consequence: this integration is a *create-only pull into the managed tables*, not a return to a board engine.** Monday items become `source_system = 'monday/{domain}'` rows via the existing identity/batch/cache machinery; webhook mechanics from the original plan are reused as *pipeline*, not as new tables.

---

## Part A — monday.com connection (create-item scoped, mapped, per-table toggle)

### A1. Where it plugs into the existing architecture

- Managed rows bear `source_system` + `source_record_id` (unique composite) — the monday **item id** becomes the source id, so retries/duplicate deliveries are idempotent (upsert, never duplicate).
- `import_batches` / `import_failures` hold batch + per-row outcomes; `monday_synced_items` records per-item state (seen/imported) for the delta diff + audit.
- `SourceWorkbookImportService::invalidateExecutiveCaches()` is extended with one condition per monday domain so a monday batch drops the same dashboard cache keys as a workbook batch of the same target table (shared helper).
- Sensitive imported fields stay restricted per pivot-plan rules (no default exposure in widgets/exports).

### A2. Connection mechanics — two transports, one pipeline, per-table switch

**a) Production path — create-item webhook (monday's recommended mechanism; original plan §6).**

- Register once per connected board: `create_webhook(board_id, url: https://<subdomain>/webhooks/monday, event: create_item)`.
- monday POSTs within ~5s of item creation: `boardId`, `pulseId`, `groupId`, `triggerUuid` (**no column values** on `create_item`).
- Controller: challenge verify → dedup `triggerUuid` → `200` immediately (5s budget) → queue `MondaySyncItemJob`.
- Job refetches the one item via `items(ids:)` (typed fragments incl. `MirrorValue`), maps it through the board's field map, imports into the target table(s).

**b) Interim path — scheduled new-id delta scan (works now, no public URL).**

- `monday:sync {domain}` on a schedule: fetch only the **item ids** of the connected board (light query, ~1 req/page), diff against `monday_synced_items`, refetch only new ids in batches, same mapping → same import path.
- **Toggle-aware:** a board with toggle **off** is skipped entirely (no ids fetched, no quota).

**Both paths share one pipeline:** refetch → map → import → bookkeeping → cache invalidation. `transport=delta|webhook` is a config flag — no rewrite. In `delta` mode the every-minute scheduler runs `monday:sync-all`; switch `transport=webhook` at deployment and the scheduler stops polling (webhooks handle new items, avoiding duplicate quota).

**Only creates.** Edits and deletions are ignored by default (extension path documented: flip on `change_column_value` events / `updated_at` diffs later). Backfill is **manual export → workbook import** and independent of the pull toggle.

### A3. New components (all in-repo, no new Composer dependency)

| Component | Purpose |
|---|---|
| Migration: `monday_sync_settings` | Per-domain toggle + state: `domain` (unique), `board_id`, `enabled` (bool), `last_synced_at`, `last_item_id_seen`. Read by scheduler + Settings page; toggle off = no pull. |
| Migration: `monday_synced_items` | Per-item audit/idempotency: `domain`, `item_id` (unique per domain), `state` (`seen`/`imported`/`failed`), `last_seen_at`. |
| Migration: `monday_webhook_events` | Re-created minimal (was dropped): `trigger_uuid` unique, `event_type`, `item_id`, `domain`, `payload` json, `processed_at`. |
| `config/monday.php` | Per-domain: transport (`delta`/`webhook`), target table, field map (from `docs/monday-board-mappings.md`), cadence, HTTP timeouts/retries. Enabled flag (global kill switch). |
| `app/Services/MondayApiClient.php` | Thin GraphQL client over `Illuminate\Http\Client`: `items_page` (ids-only), `items(ids:)` refetch, typed/mirror unwrap, `create_webhook` helper, retry/backoff on `429`/`5xx`, `401` = fatal (stop + alert). |
| `app/Services/MondayBoardImportService.php` | Refetch → map (per-board field map) → upsert into target(s) (accounts+installations for PDB) → batch bookkeeping → cache invalidation (shared helper with workbook path). |
| `app/Services/MondayWebhookController.php` | Challenge verify, `triggerUuid` dedup, instant `200`, dispatch `MondaySyncItemJob` (production). |
| `app/Jobs/MondaySyncItemJob.php` | Queued refetch + map + import (queue cron line per hosting B7). |
| `app/Console/Commands/MondaySyncCommand.php` | `monday:sync {domain}` delta scan; `--dry-run`; checks the toggle before doing anything. |
| `app/Console/Commands/MondayInspectBoardCommand.php` | `monday:inspect-board {board_id}` — dump columns (id/title/type) + item count; output feeds `docs/monday-board-mappings.md` and seeds `monday_sync_settings`. |
| Livewire: Settings page section | Per-domain toggle UI (Superadmin gate) — flips `monday_sync_settings.enabled`. Settings page is an empty shell; clean mount point. |
| `tests/Feature/MondaySyncTest.php` | Map pass, delta diff, toggle off = no fetch, webhook challenge + dedup, refetch idempotency, cache invalidation, manual-field rule, `Http::fake`. |

Gate: webhook route = challenge + dedup endpoint (monday IP allow-list in production); Settings toggles Superadmin-gated; CLI Superadmin-invoked.

### A4. Per-domain config shape (mapping in `docs/monday-board-mappings.md`)

```php
// config/monday.php (field maps confirmed from monday:inspect-board output)
'domains' => [
    'requests' => [
        'board_id' => env('MONDAY_TICKETS_BOARD_ID'),
        'transport' => env('MONDAY_TRANSPORT', 'delta'),   // delta → webhook at deploy
        'target'   => 'service_requests',
        'field_map' => [ /* column id => canonical field — see mappings doc */ ],
    ],
    'pdb' => [
        'board_id' => env('MONDAY_PDB_BOARD_ID'),
        'target'   => ['accounts', 'installations'],
        'field_map' => [ /* account_fields + installation_fields */ ],
    ],
    'reports' => [
        'board_id' => env('MONDAY_REPORTS_BOARD_ID'),
        'target'   => 'technical_reports',
        'field_map' => [ /* column id => canonical field */ ],
    ],
],
```

- Map by **column id** (stable under renames), never by title; `column_values` only returns non-empty values.
- **All new items are imported** (no condition); values are mapped through the board's field map. Unknown columns → the existing `table_custom_columns`/`table_custom_column_values` (by target `table_key`) — the 14-type registry DNA.
- Manual-only fields (`pms_frequency`, `tsp_in_charge`) never written by the importer.
- Real import errors (bad date, missing required identity) → `import_failures` (error_type `monday`), never silently dropped.

### A5. Sync semantics

- **Create-only + toggle-gated**: per board — new items only; toggle must be on. No condition filter.
- **Toggle off** = scheduler skips the domain (no ids fetched, no quota). Manual export/import backfill is **unaffected**.
- **Idempotent**: monday item id is identity; retries/duplicates safe (upsert).
- **No edits, no deletions** propagate (extendable later; safer for an operations DB; honors "never replace imported history").
- **Cache**: a completed/failed monday batch drops the dashboard keys of its target domain (shared helper).
- **Global kill switch**: `MONDAY_SYNC_ENABLED=false` — webhooks stop dispatching, delta scans stop.

### A6. Validation & rollout order

1. **M0–M2**: client, delta scan, tracking tables, toggle, mapping from the doc — all runnable locally against real boards (token only), no deployment needed.
2. **M3 live dry-run**: create a new test item on each board → confirm it is the *only* item pulled and lands in the right table/fields; toggle off → nothing fetched.
3. Backfill via the **existing export→import flow** whenever the owner re-exports from monday.
4. **At deployment**: register webhooks per board, switch `transport=webhook`, keep delta scan as `--check` fallback. True near-real-time, zero polling on hot paths.

### A7. How we find newly created items on a connected board

**Core idea: track every monday item id we have ever seen, and treat "new" as "id we haven't seen yet."** monday item ids are globally unique and assigned sequentially, so this is exact — no timestamps involved.

**a) Interim (delta scan, works now):**
1. Fetch only the board's **item ids** (lightweight `items_page` query — `id`/`created_at` only; ~1 request per 500 ids). While paginating, we can **stop early once we hit an id we already know** (ids are monotonic — everything after is older); worst case is a full pass (~17 pages for ~8k items).
2. Diff against `monday_synced_items` → the set of ids that are **not seen yet**.
3. Refetch only those new ids in batches (`items(ids: [...])`) → map through the board's field map → import → mark seen (row inserted with `state='imported'`).
4. An id that fails to import is marked `state='failed'`, so the **next run retries it** (never silently lost).

This catches **every** item created since the last run (even many at once), because each run re-reads the whole id set — no reliance on `created_at` filters (which monday does **not** support on `items_page`; that's exactly why the original plan §6 said a webhook is the right mechanism).

**b) Production (create-item webhook, at deployment):**
- Register `create_webhook(board_id, url: https://<subdomain>/webhooks/monday, event: create_item)` per connected board.
- monday POSTs within ~5s of item creation: `boardId`, `pulseId` (item id), `groupId`, `triggerUuid`.
- Endpoint: echo the registration challenge → dedup on `triggerUuid` (unique) → return `200` immediately → queue `MondaySyncItemJob` → job refetches that one item via `items(ids:)`, maps, imports, marks seen.
- True real-time, zero polling; per-item cost only.

**Honest caveats of the interim scan (why webhook is still the end state):**
- An item created **and deleted between two runs** is never seen — acceptable (it had no meaningful life on the board), and irrelevant once webhooks are on.
- A run boundary can split a burst; the next run catches the stragglers.
- The scan still costs quota (id-list reads every tick); webhooks drop that to zero.

---

## Part C — Dynamic tables (user-created, monday-fitting)

### C1. What the user builds

The Tables section (currently a hardcoded list in `TablesList.php`; the page header already holds an "Import table" action — **"New table" sits right beside it**, same header actions slot) gains:

1. **"New table" button** (header actions, next to "Import table") → opens a **modal**:
   - **Table name** (slugified to a `table_key`), optional description.
   - **Column builder** while building: add N columns, each with **name + type** from the existing 14-type `ColumnTypeRegistry` (text, number, date, status, dropdown, person, email, phone, link, location, checkbox, files, formula, long_text), optional type settings (e.g. status/dropdown options) and position.
   - Save → creates a `tables` registry row + `table_custom_columns` rows (reusing the existing machinery). The table appears in the list and opens a working grid (Tabulator surface from `ManagedTable`).
2. On the **table detail page** (or table card), actions:
   - **Import (backfill)** — reuses the existing Excel/CSV import pipeline (the owner exports the monday board and imports it here as the historical backfill; idempotent by source identity).
   - **Connect monday.com board** — pick a board id → `monday:inspect-board` returns its real columns → **auto-create any missing columns** (typed from the registry) so the table "fits" the board; stores the field map (`monday column id → this table's column key`) on the `tables` row.
   - **Live pull toggle** — flips `enabled` for that table's domain in `monday_sync_settings`; on = scheduled/webhook new-item import runs (A7), off = nothing fetches.
3. **Manual rows too:** dynamic tables support hand-entered rows in the grid (the generic row store makes this free) — not only monday-fed rows.

### C2. What this adds to the schema/code (small, no new dependencies)

| Piece | Notes |
|---|---|
| Migration `tables` | registry: `key` (unique), `name`, `description`, `monday_board_id`, `monday_field_map` (json: monday column id → table column), `enabled`, `created_by`, timestamps. (The old `boards` table, trimmed and re-purposed.) |
| Migration `dynamic_rows` | generic row store: `table_key`, `name`, `source_system`, `source_record_id` (unique per source), `position`, timestamps. Identity pipeline identical to managed tables. |
| Migration `table_custom_column_values.value_date` | the original plan's missing shadow column — date columns sort/filter properly in dynamic tables. |
| `DynamicTable` Livewire component + `/tables/{key}` route | reuses `ManagedTable`'s grid, inline editing, filters, import/export; row store swapped to `dynamic_rows` + custom-column values. |
| `TablesList` rework | reads `tables` registry + the core tables; "New table" button + create modal (Livewire) with the column builder. |
| Connect-board action | inspect → auto-create columns → field map → seed `monday_sync_settings`. |

### C3. Guardrail (from the project's own history)

**Core analytics domains stay fixed tables** — `installations`, `service_requests`, `technical_reports`, `historical_tsms_reports` keep their typed physical columns because their dashboards (Product Dashboard, Technical Service Analysis) run SQL aggregation over those columns; EAV dynamic tables are poor at that. **Dynamic tables are for boards with no core home** (i.e. the user's "fit it to my monday table" use case) and for operational tracking. Any monday board whose data *is* a core domain keeps the fixed mapping in `docs/monday-board-mappings.md`; anything else attaches to a dynamic table the user creates.

---

## Part B — Shared cPanel deployment (facts now concrete, from the vault)

### B1. Hosting facts (proven on this account via `customer-portal` live deployment)

- **Same cPanel account as `portal` / `customer-portal`, on `mcbtsi.com`.** SSH + composer + artisan work on the server.
- **External DNS via OrderBox (reseller)** — a new subdomain's **A record must be added at the OrderBox DNS panel first**; cPanel's Subdomains tool alone will not make it resolve (already happened once for `customer-portal`).
- **Document root points at Laravel `public/`** — no `public_html` copy-out.
- **No Node.js in production** — assets are Vite-built locally/CI and shipped as `public/build` (or CDN). Node is build-time only.
- **MySQL in production**; standard Laravel JSON columns supported.
- **Runtime envelope: 512MB memory / 300s execution** — chunked processing assumed; monday sync stays paginated/streamed. Webhook job is tiny (single-item refetch).

### B2. Target layout

```
/home/USER/
├── admin-system/            # Laravel app root (outside webroot)
│   ├── app/ public/ … storage/ …
│   └── .env                 # production values; never in git; chmod 600
└── (domain docroot)         # → /home/USER/admin-system/public
```

### B3. Subdomain setup order (OrderBox DNS first)

1. Add A record at **OrderBox** DNS panel for the new subdomain (e.g. `admin.mcbtsi.com`).
2. Create the subdomain in cPanel; point its docroot at `~/admin-system/public`.
3. **Pin PHP to 8.3** via MultiPHP Manager — it will **not** inherit `customer-portal`'s 8.5.
4. In MultiPHP INI Editor under the **8.3** handler, confirm: `ext-zip` (maatwebsite/excel), `ext-gd`, `ext-intl` (only proven under 8.5 so far) + `pdo_mysql`, `mbstring`, `xml`, `curl`, `openssl`, `bcmath` (cross-check local `php -m` vs server).
5. PHP limits: `upload_max_filesize=300M`, `post_max_size=300M`, `memory_limit=512M`, `max_execution_time=300`.
6. SSL: AutoSSL/Let's Encrypt for the subdomain; force HTTPS (webhook URL must be HTTPS).

### B4. Production database: SQLite → MySQL

- Create MySQL DB + user via cPanel wizard; wire into server `.env`. Sessions/jobs/cache follow the connection.
- **Data migration = re-import after go-live** (PDB 8,108 / SR 8,126 / TR 8,156 / TSMS 23,309 rows re-import into MySQL; monday backfills come from the owner's exports). SQLite→MySQL dump only as fallback for hand-entered rows (users, custom columns). No SQLite in production.

### B5. Build & assets

- Build locally/CI: `npm ci && npm run build` → ship `public/build` (commit or rsync). No `public/hot` in production.
- CI (GitHub Actions) later to build `public/build` on release — remember composer **Tencent mirror lag** workaround (`COMPOSER_HOME` temp) applies locally and on the server.

### B6. `.env` production matrix

| Key | Value |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_URL` | `https://admin.mcbtsi.com` (assets + verification links + webhook base) |
| `APP_KEY` | `php artisan key:generate` on server |
| `SESSION_DRIVER` / `SESSION_SECURE_COOKIE` | `database` / `true` |
| `DB_CONNECTION` | `mysql` + creds (B4) |
| `QUEUE_CONNECTION` | `database` (webhook job exists) — worker via cron (B7) |
| `CACHE_STORE` / `LOG_CHANNEL` | `database` / `stack` |
| `MAIL_MAILER` | `smtp` via cPanel mailbox |
| `TRUSTED_PROXIES` | actual proxy IPs/CIDRs — never `*` |
| `ALLOW_REGISTRATION` | `false` |
| `MONDAY_SYNC_ENABLED`, `MONDAY_TRANSPORT` | global kill switch; `delta` → `webhook` at deploy |
| `MONDAY_API_TOKEN`, `MONDAY_*_BOARD_ID` | server-only; board IDs per domain in config |

Pin `APP_TIMEZONE` so the digest (Mon 07:00) + sync fire at the intended local time.

### B7. Cron / scheduler / queue

- One every-minute line: `* * * * * php /home/USER/admin-system/artisan schedule:run >> /home/USER/admin-system/storage/logs/scheduler.log 2>&1` — drives digest + enabled delta scans from `config/monday.php` + DB toggles.
- **Queue worker (required for webhook path):** second cron line `cd /home/USER/admin-system && php artisan queue:work --stop-when-empty` (shared-hosting pattern; original plan §5) with failed-job monitoring. `QUEUE_CONNECTION=database`.
- Cadence per board from config; `withoutOverlapping` guards against slow runs stacking.

### B8. Deploy procedure

**First deploy:** clone/push to `~/admin-system` (git `remialbusa-sketch/admin-system`, `main`); `composer install --no-dev -o`; `.env` per B6 + `key:generate`; `migrate --force`; `optimize`; writable `storage/` + `bootstrap/cache/`; docroot + SSL (B3); smoke: login, tables, export, import, digest mail, `monday:sync --dry-run` then live, webhook registration + live create-item test.

**Subsequent deploys (idempotent `scripts/deploy.sh`, no secrets):**
`git pull` → `composer install --no-dev -o` → ship `public/build` → `migrate --force` → `optimize` → permissions → health check. Validation gates: tests after every phase, no commit/push without approval, verify Filament v4 on PHP 8.3 at first deploy (local runs 8.5.7).

### B9. Security checklist

- `APP_DEBUG=false`, HTTPS forced, `SESSION_SECURE_COOKIE=true`; `TRUSTED_PROXIES` real IPs only.
- `.env` chmod 600, git-ignored; `MONDAY_API_TOKEN` server-only.
- Webhook endpoint: allowed monday.com IPs (verify current list); dedup makes replays harmless; no secrets returned.
- Registration disabled; import/edit Superadmin-gated; Settings toggles Superadmin-gated; `/admin` via `User::canAccessPanel()`; sensitive imported fields restricted.
- `storage/` outside docroot; cPanel Directory Privacy / `.htaccess` defense in depth.

### B10. Rollback & kill switches

- Keep previous git ref; cPanel DB backup **before every** `migrate --force` (forward-only).
- **Integration kill switches:** `MONDAY_SYNC_ENABLED=false` (global); per-table toggle off (per board). Data untouched.
- Post-deploy smoke list: login, dashboards from real data, `/tables`, export, import batch, digest mail, monday create-item → appears in target table, toggle off → no fetch.

---

## Milestones & acceptance criteria

| # | Milestone | Acceptance |
|---|---|---|
| M0 | `monday:inspect-board` on the three real boards | Command + `MondayApiClient::boardColumns/itemsPage` built and tested (`Http::fake`). Live board dumps pending board ids+token. **Code DONE (M0-c)** |
| M1 | `MondayApiClient` + tests (`Http::fake`) | Cursor pagination, typed/mirror unwrap, retry/backoff, 401 fatal → **DONE, 8 feature tests** (incl. `monday:inspect-board`, `monday:sync`, `monday:sync-all` + `monday_synced_items` new-item detection) |
| M-DT | **Dynamic tables** — `dynamic_tables` registry + `dynamic_rows` + `value_date` + "New table" modal (name + column builder) + `tables/{key}` grid + **live-pull toggle + board-connect on the table page** | Create a table with defined columns in the UI → opens a working grid; add/delete rows/columns; toggle persists to `monday_sync_settings`; board id stored on the table **→ DONE, 9 feature tests** |
| M-DC | **Connect monday board to a dynamic table** — inspect → auto-create missing columns (fast auto-map, incl. status/dropdown options from `settings_str`) → field map → live-pull toggle → new items written into `dynamic_rows` + custom columns | **DONE — `MondayItemMapper` (autoCreateColumns + mapItem), `MondaySyncService` writes new items, `connectBoard` auto-creates columns, 3 feature tests** |
| M2 | `MondayBoardImportService` create-only path + `monday:sync` delta scan (A7) + tracking tables + cache invalidation | **DONE — new-item detection by unseen id; **failed items are recorded `failed` (not `seen`/`imported`) and are retried next run** (verified by test). A connected-but-unmapped table is reported as "not ready" rather than retry-looping. Full suite + feature tests green** |
| M3 | Live validation (three core boards + one dynamic table) | New item on each → lands in correct table/fields; dry-run clean |
| M4 | Hosting groundwork (OrderBox A record → subdomain → PHP 8.3 → extensions → SSL) | Subdomain live, PHP 8.3 + extensions confirmed |
| M-W | **Webhook production path** (controller, dedup, job, queue cron) | **DONE — `MondayWebhookController` (challenge + triggerUuid dedup + instant 200), `MondaySyncItemJob` (queued refetch→map), `monday_webhook_events` table, route, plus `monday:register-webhook {board_id}` deployment helper (+ `createWebhook`/`boardWebhooks` client methods). Live registration pending public URL/IP allow-list. 4 feature tests (+2)** |
| M5 | MySQL DB + deploy pipeline (`scripts/deploy.sh`) | Scripts/env templates prepared **`scripts/deploy.sh` (idempotent) + `.env.production.example` (B6 matrix)**; execution + production re-import pending host access |
| M6 | First production deploy + cron + webhook switch | Smoke list B10 green; `transport=webhook` live |
| M7 | Security & governance pass | B9 checklist green; approval-gated releases documented |

## Open questions
1. Exact subdomain name and the three board ids (`MONDAY_TICKETS_BOARD_ID`, `MONDAY_PDB_BOARD_ID`, `MONDAY_REPORTS_BOARD_ID`).
2. Digest timezone (server TZ vs. local) for the Monday-07:00 schedule.
3. Whether "new item" should also include *edited* items later (extension path — default: creates only).

## Priorities guarded (do not break)
- Dashboards compute **only** from managed tables — never hardcoded KPIs.
- Manual-only fields stay `null` after imports.
- Source identity per source stays unique; recurring fetches update, never duplicate.
- **Only newly created items are pulled; real import errors are logged, never silently dropped.**
- The pull toggle only affects live new-item pulling — never the manual export→import backfill.
- Monday never replaces imported history automatically; deletions never auto-propagate.
- Do not proceed without explicit approval per phase (project policy).