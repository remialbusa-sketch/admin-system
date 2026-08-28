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

## What's inside

| Area | Route | Entry points |
|---|---|---|
| Dashboard | `/dashboard` | `App\Livewire\Dashboard` |
| President dashboard | `/president` (gated by `president.view` middleware) | `App\Livewire\PresidentDashboard`, `App\Services\PresidentDashboardService` |
| Managed tables | `/tables`, `/installed-products`, `/service-requests`, `/technical-reports`, `/history-reports`, `/personnel` | `App\Livewire\*Table`, `App\Livewire\ManagedTable` |
| Analytics | `/technical-service-analysis`, `/tsp-analytics` | `App\Services\TechnicalServiceAnalysisService`, `TspAnalyticsService` |
| Import pipeline | (triggered via tables UI) | `App\Services\SourceWorkbookImportService` — batch + per-row failure tracking, stable source IDs/hashes for dedupe |

### Domain rules (do not break these)

- **Dashboards compute ONLY from managed tables.** Never hardcode, fake, or seed KPI numbers.
- **Active TSPs** = personnel whose `position` is in service/field roles (see `TspAnalyticsService`).
- **Manual-only fields** (`pms_frequency`, `tsp_in_charge` on installations) must stay `null` after auto-import — they are filled by hand, never by the workbook.
- **Source identity** (`source_system` + `source_record_id`) is unique per row; re-imports update instead of duplicating.

## Testing

```bash
php artisan test        # 54 tests, SQLite in-memory
```

Feature tests cover auth, imports (incl. the PDB manual-field rule), data normalization, every managed table, and both dashboards.

## Deployment notes

- Set `APP_URL` correctly in `.env` (it drives asset URLs and verification links). Do not leave it pointing at a stale ngrok tunnel.
- `php artisan migrate` on deploy; `npm run build` for assets.
- The `president.view` middleware gates the executive dashboard — assign roles accordingly.
