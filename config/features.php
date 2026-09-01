<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    |
    | allow_registration: Self-service account creation. Disabled by default —
    | this is an internal workspace, so accounts are created by a Superadmin
    | (currently via the seeded accounts / database) and visitors who hit
    | /register get a 404. Set ALLOW_REGISTRATION=true to re-enable the form
    | temporarily (e.g. for a supervised onboarding window).
    |
    */

    'allow_registration' => env('ALLOW_REGISTRATION', false),

];
