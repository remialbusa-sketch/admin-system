<?php

return [

    /*
    |--------------------------------------------------------------------------
    | monday.com Integration
    |--------------------------------------------------------------------------
    |
    | enabled: global kill switch read by the scheduler and webhook handler.
    |          When false, no new-item pull runs anywhere (per-table toggles
    |          in monday_sync_settings are ignored). Flipping this to false is
    |          the integration's kill-switch.
    |
    | Per-domain (board id, transport, field mapping) config is consolidated
    | here as the monday.com milestones land (M0 inspect, M-DC connect). For
    | M-DT the only live value is `enabled`; board ids live on each dynamic
    | table (dynamic_tables.monday_board_id) and its monday_sync_settings row.
    |
    */

    'enabled' => env('MONDAY_SYNC_ENABLED', false),

    'token' => env('MONDAY_API_TOKEN'),

    'api_url' => env('MONDAY_API_URL', 'https://api.monday.com/v2'),

    'transport' => env('MONDAY_TRANSPORT', 'delta'),

    /*
    |--------------------------------------------------------------------------
    | Confirmed live boards (M3, 2026-08-28)
    |--------------------------------------------------------------------------
    | The three source boards. Column ids verified in
    | docs/monday-board-mappings.md. A user-created dynamic table is what
    | actually receives new items (its per-table board id + field map are set
    | via the "Connect monday.com board" action); these ids are the reference
    | catalog for operators and the manual export→import backfill flow.
    */
    'boards' => [
        'pdb' => env('MONDAY_PDB_BOARD_ID', '5028296070'),
        'requests' => env('MONDAY_TICKETS_BOARD_ID', '5028514175'),
        'reports' => env('MONDAY_REPORTS_BOARD_ID', '5028606690'),
    ],

    // HTTP behaviour for the GraphQL transport (delta-scan cadence lives per
    // domain once the sync milestones land; these are the client defaults).
    'timeout' => (int) env('MONDAY_TIMEOUT_SECONDS', 30),

    'retries' => (int) env('MONDAY_RETRIES', 3),

];
