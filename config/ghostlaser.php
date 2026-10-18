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

    /*
    | Base URL of this Bagisto installation. The Admin REST API is called at
    | {base_url}/api/admin/customers, so this should point at the store itself
    | (defaults to APP_URL).
    */

    'bagisto_url' => env('BAGISTO_URL', env('APP_URL', 'http://localhost')),

    /*
    | Source endpoint that returns the Ghost Laser customer list.
    */

    'source_url' => env(
        'GHOSTLASER_CUSTOMERS_URL',
        'https://ghostlaser.com/spfx_ghostlaser/routes/api/bagisto-customers.php'
    ),

    /*
    | Customer group assigned to synced customers. Bagisto seeds 1 = guest,
    | 2 = general, 3 = wholesale, so registered customers belong in group 2.
    */

    'customer_group_id' => (int) env('GHOSTLASER_CUSTOMER_GROUP_ID', 2),
];
