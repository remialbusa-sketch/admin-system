# monday.com Board ↔ Admin System Table Mappings

Status: **Confirmed (M3 live) — column ids verified 2026-08-28 via `monday:inspect-board`** · Companion to `monday-integration-and-cpanel-deployment.md`.

This document defines, per connected monday.com board, how an item's columns map onto this app's managed tables. Column **ids** below are the real ids returned by `monday:inspect-board` against the live boards — always map by id, never by title.

## Confirmed live boards (M3)

| Board | monday board id | Items (first page) | Columns |
|---|---|---|---|
| Product Database | `5028296070` | 210+ | 25 |
| Service Requests | `5028514175` | 500+ | 48 |
| Technical Service Reports | `5028606690` | 500+ | 53 |

> The newer monday "Product Database" board hosts ~210 items — far smaller than the legacy PDB workbook's 8,108 rows. That's expected: this integration pulls **new items** from the live boards; the workbook remains the historical backlog/backfill (owner's manual export→import flow).

### Key confirmed column ids (map by these)

**Product Database (`5028296070`)**
| monday column id | title | type |
|---|---|---|
| `name` | Name | name |
| `lookup_mm3xk3s6` | Customer Name | mirror |
| `text_mm3xcam6` | Address | text |
| `color_mm3x5y7z` | Branch | status |
| `color_mm129h4f` | BU No. | status |
| `text_mm3xa5m6` | Brand | text |
| `text_mm5ptqr2` / `lookup_mm3xg6xx` | Model | text / mirror |
| `text_mm5pdm3j` / `lookup_mm3x18ge` | Serial Number | text / mirror |
| `color_mm1c3x7q` | Warranty Status | status |
| `color_mm1cs5sc` | Service Contract Status | status |
| `date_mm1281sy` | UNINSTALLATION DATE | date |
| `date_mm13m2jc` | INSTALLATION DATE | date |
| `color_mm12n3z2` | DEVICE STATUS | status |
| `color_mm12nv78` | DEAL TYPE | status |
| `color_mm12za4y` | PMS FREQUENCY | status — **manual-only, do NOT auto-map** |
| `multiple_person_mm12vh3k` | TSP IN-CHARGE | people — **manual-only, do NOT auto-map** |
| `date_mm3xd7kz` | Creation Date | date |

**Service Requests (`5028514175`)**
| monday column id | title | type |
|---|---|---|
| `name` | Name | name |
| `people0` | TSP | people |
| `status95` | TICKET STATUS | status |
| `board_relation_mm3rtjcf` | Customer Name | board_relation |
| `color_mm3sy9xf` | Branch | status |
| `dropdown_mm3gjs5y` | BRAND | dropdown |
| `dropdown_mm3gx0tz` | MODEL | dropdown |
| `text` | Requestor Name | text |
| `multiple_person_mm3fc1z4` | Coordinator | people |
| `phone_mm3f270h` | Contact Number | phone |
| `email` | Requestor Email | email |
| `color_mm3fgft6` | Contract Type | status |
| `color_mm3fxd93` | Type of Request | status |
| `long_text_mm3fvafg` | Special Instructions | long_text |
| `date_mm3fvch6` | Date Needed | date |
| `item_id_1` | Service No. | item_id |
| `date` | Creation Date | date |
| `color_mm3f5w60` | Device Ownership | status |
| `text_mm4tkpb6` | DEPARTMENT | text |

**Technical Service Reports (`5028606690`)**
| monday column id | title | type |
|---|---|---|
| `name` | Name | name |
| `color_mm3gbrby` | Service Status | status |
| `lookup_mm3gcja8` | Ticket Status | mirror |
| `lookup_mm3rpsf7` | Customer Name | mirror |
| `lookup_mm3tn0m0` | Branch | mirror |
| `long_text_mks8824j` | Problem and Concerns | long_text |
| `long_text_mks8y6j7` | Job Done | long_text |
| `text_mks8xtcq` | Parts Replaced | text |
| `long_text_mksdf1jb` | Recommendation | long_text |
| `date_mks8t42p` | Service Start Date & Time | date |
| `date_mks8gbw0` | Service End Date & Time | date |
| `date_mks8wqcw` | Log-In Date | date |
| `date_mks8mvb2` | Log-out Date | date |
| `pulse_id_mkwtnr0p` | Reference Number | item_id |
| `multiple_person_mks8jn7f` | TSP WORKWITH | people |
| `lookup_mm3g7nbc` | TSP ASSIGNED | mirror |
| `lookup_mm3g7mf2` | Brand | mirror |
| `lookup_mm3gc9tk` | Model | mirror |
| `board_relation_mm3f6835` | Service Number | board_relation |

---

## How to read this document

- **monday column** — by semantic title for now; the column **id** replaces the title after inspection.
- **App field** — exact column on the target table.
- **Type/transform** — conversion applied when writing (monday values arrive as text).

### 1. monday column type → stored value (applies to all boards)

| monday column type | Stored as | Notes |
|---|---|---|
| text / long_text | string / text | as-is (trimmed) |
| number | decimal(`15,2`) or int | money/hours fields → decimal |
| date | `date` | `YYYY-MM-DD`; also carries time for `*_at` fields |
| status / dropdown | string | store the **label** (`StatusValue.label`, `DropdownValue.text`) |
| checkbox | — | not mapped by default (no boolean app columns) |
| email / phone | string | requestor_email, requestor_phone |
| link | string (URL) | report_url etc. |
| people (person) | string | store display name (tsp_name, tsp_assignment, coordinator) |
| location | string | customer_address (address component) |
| files | — | not mapped by default; preserved in `raw_data` |
| mirror | resolved type first | unwrap `mirrored_value` to its source type, then use the row above |

### 2. Source identity block (all three boards)

Written on every imported row; makes imports idempotent (upsert, never duplicate):

| App field | Value |
|---|---|
| `source_system` | `monday/tickets` · `monday/pdb` · `monday/reports` |
| `source_record_id` | monday **item id** (stable) |
| `source_hash` | sha256 of the normalized column values (changed-cell detection) |
| `source_updated_at` | monday item `updated_at` |
| `import_batch_id` | the batch created for the run |
| `raw_data` | full `column_values` + item metadata (audit) |

---

## Board 1 — Tickets → `service_requests`

### Field map

| monday column (draft title) | App field | Type / transform |
|---|---|---|
| Service Request No | `service_request_number` | string |
| Service Request Code | `service_request_code` | string |
| Customer name | `customer_name` | string |
| GROUP | `group_status` | status label |
| TICKET STATUS | `ticket_status` | status label |
| Branch | `branch` | string |
| TSP assignment | `tsp_assignment` | people → name string |
| Coordinator | `coordinator` | people → name string |
| Requesting entity | `requesting_entity` | string |
| Requestor | `requestor_name` | string |
| Requestor email | `requestor_email` | email → string (sensitive) |
| Requestor phone | `requestor_phone` | phone → string (sensitive) |
| Region | `region` | string |
| Department | `department` | string |
| Contract type | `contract_type` | string/status label |
| Request type | `request_type` | string/status label |
| Service type | `service_type` | string/status label |
| Brand | `brand` | string |
| Machine type | `machine_type` | string |
| Serial number | `serial_number` | string |
| Concerns | `concerns` | long_text → text |
| Date needed | `date_needed` | date |
| Service indicator | `service_indicator` | string |
| Device ownership | `device_ownership` | string |
| **Item name** | *(configurable)* | often holds the request code; map only if no dedicated column |
| **Board group** | *(optional: `group_status`)* | if the board's group carries GROUP semantics; else audit-only |

- Sensitive (email/phone) imported but restricted per pivot-plan rules (not surfaced by default in widgets/exports).
- Unmapped ticket columns → custom columns under `table_key = 'service_requests'`.

---

## Board 2 — Product Database → `accounts` + `installations`

One monday item = one **installation** row, with customer fields promoted to its **account** row (one account → many installations).

### Account-level fields (`accounts`)

| monday column (draft title) | App field | Type / transform |
|---|---|---|
| Customer name | `customer_name` | string — **also the account business key** |
| Customer address | `customer_address` | location → address string (sensitive) |
| Hospital section | `hospital_section` | string |
| Branch | `branch` | string |
| Region | `region` | string |

- **Account identity:** same business-key rule as the workbook importer — `source_record_id = customer_name` (normalized, hash fallback) under `source_system='monday/pdb'`, so monday-sourced items for the same customer collapse onto one account. Installation rows then FK to it via `account_id`.

### Installation-level fields (`installations`)

| monday column (draft title) | App field | Type / transform |
|---|---|---|
| Device description | `device_description` | string |
| Brand | `brand` | string |
| Machine type | `machine_type` | string |
| Serial number | `serial_number` | string |
| BU No. | `bu_no` | string |
| Equipment/system type | `equipment_type` | string |
| Installation date | `installation_date` | date |
| Pulled out date | `uninstallation_date` | date |
| Device status | `device_status` | string/status label |
| Device ownership | `device_ownership` | string |
| Deal type | `deal_type` | string |
| Charge to | `charge_to` | string |
| Warranty status | `warranty_status` | string/status label |
| Warranty period (years) | `warranty_period_years` | number → unsignedSmallInt |
| Warranty end date | `warranty_end_date` | date |
| Service contract status | `service_contract_status` | string/status label |
| Service contract amount | `service_contract_amount` | number → decimal(15,2) |
| Service contract start | `service_contract_start` | date |
| Service contract end | `service_contract_end` | date |
| Annual BU charge | `annual_bu_charge` | number → decimal(15,2) |
| **Item name** | *(configurable)* | often the device description; map only if no dedicated column |

### Explicitly NOT mapped (manual-only rule — domain rule, do not break)

| monday column | Reason |
|---|---|
| PMS FREQUENCY | manual-only — importer never writes it (stays `null`) |
| TSP IN-CHARGE | manual-only — importer never writes it (stays `null`) |

- Unmapped PDB columns → custom columns under `table_key = 'installations'`.
- Cross-source merge (monday vs. workbook rows for the same physical machine) is a **deferred business decision**; per-source identity keeps both alive safely until then.

---

## Board 3 — Technical Reports → `technical_reports`

### Field map

| monday column (draft title) | App field | Type / transform |
|---|---|---|
| Reference Number | `reference_number` | string (source identity on top of item id) |
| SERVICE REQUEST NUMBER | `service_request_number` | string — also used to link `service_request_id` |
| Report name | `report_name` | string |
| TICKET STATUS | `ticket_status` | status label |
| GROUP | `group_status` | status label |
| Service status | `service_status` | status label |
| Customer | `customer_name` | string |
| Service date (start) | `service_started_at` | date+time → timestamp |
| Service date (end) | `service_completed_at` | date+time → timestamp |
| TSP | `tsp_name` | people → name string |
| (TSP display name) | `tsp_display_name` | people → name string |
| Brand | `brand` | string |
| Machine type | `machine_type` | string |
| Job done | `job_done` | long_text → text |
| Parts replaced | `parts_replaced` | long_text → text |
| Recommendation/remarks | `recommendation` | long_text → text |
| Repair time | `repair_time_hours` | number → decimal(10,2) |
| Response time | `response_time_hours` | number → decimal(10,2) |
| Report/document URL | `report_url` | link → URL string (sensitive) |
| **Item name** | *(configurable)* | often the reference number; map only if no dedicated column |

### Relationship

- **`service_request_id`** — at import time, try to match `service_request_number` against existing `service_requests` rows (any source); set the FK when found, else leave `null` (one request → many reports is preserved). Re-link command can be added later if needed.

---

## Unmapped columns → existing custom-column machinery

Columns on a board with **no canonical app field** are stored via the existing table-scoped custom columns (the survival of the original 14-type registry):

- `table_custom_columns` — `table_key` = the target's key (`service_requests` / `installations` / `technical_reports`), `name` = monday column title, `type` = inferred monday type, `settings` json.
- `table_custom_column_values` — `row_id` = the imported row id, `value` json + `value_text` / `value_number` shadow fields.

This keeps every board column visible on the managed table's grid with zero new tables.

---

## Confirmation checklist (done in M0 via `monday:inspect-board`)

For each of the three boards, capture and fold back into this document:

- [ ] Actual **column ids** (the `field_map` must key on these, never titles)
- [ ] Column titles + types (validate the type→value table above against reality)
- [ ] Item count (sanity vs. workbook baselines: PDB ~8,108 · Service Requests ~8,126 · Technical Reports ~8,156)
- [ ] Group titles (confirm/deny the optional GROUP → `group_status` mappings)
- [ ] Whether a dedicated "Item name" column exists (name collision with the monday native Name column)
- [ ] Mirror columns present? (verify unwrap behavior on a real mirrored value)

After confirmation: transcribe confirmed column ids into `config/monday.php` per domain (`field_map` arrays) and keep this document as the human-readable source.