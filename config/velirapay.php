<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API Key
    |--------------------------------------------------------------------------
    |
    | A secret key from the VeliraPay dashboard, under Developers > API keys.
    | Keys starting with "vp_test_" work in test mode, "vp_live_" in live mode.
    |
    */

    'api_key' => env('VELIRAPAY_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | The signing secret of your endpoint, under Developers > Webhooks, with
    | several separated by commas, and the path VeliraPay posts to. A null
    | path leaves the route for you to register.
    |
    */

    'webhook' => [
        'secret' => env('VELIRAPAY_WEBHOOK_SECRET'),
        'path' => env('VELIRAPAY_WEBHOOK_PATH', 'velirapay/webhook'),
        'tolerance' => (int) env('VELIRAPAY_WEBHOOK_TOLERANCE', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Requests
    |--------------------------------------------------------------------------
    |
    | How many seconds a request may take, and how many times it is tried
    | again after a network error, a rate limit or an outage.
    |
    */

    'timeout' => (float) env('VELIRAPAY_TIMEOUT', 30),

    'max_retries' => (int) env('VELIRAPAY_MAX_RETRIES', 2),

    'base_url' => env('VELIRAPAY_BASE_URL', 'https://api.velirapay.com'),

];
