# MCBTSi Admin System

Internal operations workspace for MCBTSi (medical/diagnostic equipment service). Imports the managed Excel workbooks (Installed Products, Service Requests, Technical Reports, History/TSMS, Personnel) into a normalized database, then presents dashboards and analytics computed **only** from that imported data — KPIs are never hardcoded or faked.

## Stack

- Laravel 13 + Livewire 3.8 (Breeze auth stack)
- Tailwind CSS 4 + DaisyUI 5 (light/dark theme toggle preserved)
- Tabulator Tables (dense operational grids) + Chart.js (analytics)
- maatwebsite/excel for workbook imports
- SQLite by default (`DB_CONNECTION=sqlite` in `.env`)

## Quickstart

```bash
composer install
npm install

cp .env.example .env          # then edit APP_URL / DB as needed
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed

npm run dev      # Vite dev server (or: npm run build for production assets)
php artisan serve
```

Login at `http://127.0.0.1:8000` with a seeded admin account.

### Sharing with ngrok

Dev-server asset URLs (`http://[::1]:5173/...` via `public/hot`) only resolve on
the machine running `npm run dev` — through an ngrok tunnel every visitor's
browser tries to load them from its own localhost and the frontend breaks.
Share the **built** frontend instead:

```bash
npm run share    # vite build + removes public/hot
ngrok http 8000
```

`.env` must also trust the local ngrok agent (`TRUSTED_PROXIES=127.0.0.1`),
or Laravel generates `http://` asset URLs that https browsers block as mixed
content. Hot reload is not available through the tunnel — restart `npm run dev`
when you're back to local-only development. On the free plan, visitors see
ngrok's one-time "Visit Site" warning page; clicking it sets a bypass cookie
for the rest of the session.

## What's inside

| Area | Route | Entry points |
|---|---|---|
| Dashboard | `/dashboard` — **Product Database overview** (installed base, warranty/contracts, fleet state, brands, machine mix, installation momentum; region + period scopes, print-ready; every widget drills into the filtered Product Database grid; `/president` redirects here) | `App\Livewire\Dashboard`, `App\Services\ProductDashboardService` |
| Managed tables | `/tables`, `/installed-products`, `/service-requests`, `/technical-reports`, `/history-reports`, `/personnel` | `App\Livewire\*Table`, `App\Livewire\ManagedTable` |
| Analytics | `/technical-service-analysis` — **Technical Reports overview** (completion trend, status mix donut, TSP workload, brand mix; every widget drills into the filtered Technical Reports grid; period selector 7D/30D/90D), `/tsp-analytics` | `App\Livewire\TechnicalServiceAnalysis`, `App\Services\TechnicalServiceAnalysisService`, `TspAnalyticsService` |
| Administration | `/users` (Superadmin-only) | `App\Livewire\UserManagement` |
| Import pipeline | (triggered via tables UI or `source:import` CLI) | `App\Services\SourceWorkbookImportService` — batch + per-row failure tracking, business-identity upsert + post-import dedupe |
| Maintenance | (CLI) | `php artisan app:dedupe-installations`, `php artisan app:send-exec-digest` (scheduled Mondays 07:00) |

### Access model

- Accounts are provisioned by a Superadmin; self-registration is disabled by default (`ALLOW_REGISTRATION`, see `config/features.php`) and email verification is enforced (`User` implements `MustVerifyEmail`).
- Only **Superadmin** can edit records / run imports; every other role is read-only. Exports require a role (they fail closed for role-less accounts). The Filament `/admin` panel is limited to Superadmin/President via `User::canAccessPanel()`.

### Domain rules (do not break these)

- **Dashboards compute ONLY from managed tables.** Never hardcode, fake, or seed KPI numbers.
- **The Home dashboard is a Product Database overview.** Operations metrics (service requests, reports, history, personnel) belong to their own tables/analytics pages — do not add them back to Home.
- **Active TSPs** = personnel whose `position` is in service/field roles (see `TspAnalyticsService`).
- **Manual-only fields** (`pms_frequency`, `tsp_in_charge` on installations) must stay `null` after auto-import — they are filled by hand, never by the workbook.
- **Source identity** (`source_system` + `source_record_id`) is unique per row; re-imports update instead of duplicating. Installation identity is the business key (customer, serial, description) — never hash mutable cells; `dedupeProductRows()` collapses superseded versions after each import.
- **Dashboard cache** (`exec:summary:{region}`) is dropped by `SourceWorkbookImportService` on every batch completion/failure — invalidate it if you add another write path to the source tables.

## Testing

```bash
php artisan test        # 95 tests, SQLite in-memory
```

Feature tests cover auth (incl. registration-disabled and non-mass-assignable roles), user management, imports (incl. the PDB manual-field rule and re-import dedupe), data normalization, every managed table, the edit audit trail, the executive digest, and the executive dashboard (periods, drill-down, region scoping).

## Deployment notes

- Set `APP_URL` correctly in `.env` (it drives asset URLs and verification links). Do not leave it pointing at a stale ngrok tunnel.
- Production values: `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, and a real SMTP mailer (verification/reset mail must actually send). See `.env.example`.
- Set `TRUSTED_PROXIES` to your actual proxy IPs/CIDRs when behind ngrok or a load balancer — never `*` (it defeats login throttling).
- `php artisan migrate` on deploy; `npm run build` for assets.
