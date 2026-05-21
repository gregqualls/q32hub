<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Version
    |--------------------------------------------------------------------------
    |
    | The current version of Kinhold. This is displayed in Settings and
    | included in the public /api/v1/config response. Follows semver.
    |
    */

    'current' => env('APP_VERSION', '1.10.0'),

    /*
    |--------------------------------------------------------------------------
    | Update Check
    |--------------------------------------------------------------------------
    |
    | When enabled, Kinhold checks the GitHub Releases API once per day to
    | see if a newer version is available. No telemetry or data is sent —
    | it's a simple GET request. Set DISABLE_UPDATE_CHECK=true to opt out.
    |
    */

    'update_check' => ! env('DISABLE_UPDATE_CHECK', false),

    /*
    |--------------------------------------------------------------------------
    | GitHub Repository
    |--------------------------------------------------------------------------
    |
    | The GitHub repo used for update checks and release links.
    |
    */

    'github_repo' => 'gregqualls/kinhold',

];
