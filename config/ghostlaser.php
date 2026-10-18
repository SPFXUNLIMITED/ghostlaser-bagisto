<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Ghost Laser customer sync
    |--------------------------------------------------------------------------
    |
    | Credentials used by the ghostlaser:sync-customers command. These are read
    | through the config repository rather than env() directly, because env()
    | returns null once `php artisan config:cache` has been run.
    |
    */

    'api_key' => env('BAGISTO_API_KEY', ''),

    'admin_token' => env('BAGISTO_ADMIN_TOKEN', ''),
];
