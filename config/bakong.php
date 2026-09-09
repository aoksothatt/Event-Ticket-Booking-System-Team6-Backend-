<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bakong Open API Configuration
    |--------------------------------------------------------------------------
    | Configuration for the Bakong (NBC) payment gateway integration.
    | All credentials are loaded from environment variables.
    */

    'base_url' => env('BAKONG_BASE_URL', 'https://api-bakong.nbc.gov.kh/v1'),

    'client_id' => env('BAKONG_CLIENT_ID', ''),

    'client_secret' => env('BAKONG_CLIENT_SECRET', ''),

    'api_key' => env('BAKONG_API_KEY', ''),

    'token' => env('BAKONG_TOKEN', ''),

    'account_id' => env('BAKONG_ACCOUNT', ''),

    'merchant_name' => env('BAKONG_MERCHANT', ''),

    'currency' => env('BAKONG_CURRENCY', 'USD'),

    'timeout' => env('BAKONG_TIMEOUT', 30),

    'callback_url' => env('BAKONG_CALLBACK_URL', ''),

    'webhook_secret' => env('BAKONG_WEBHOOK_SECRET', ''),

    'qr_expiration_minutes' => env('BAKONG_QR_EXPIRATION_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Payment Status Mapping
    |--------------------------------------------------------------------------
    | Maps Bakong transaction statuses to internal payment statuses.
    */

    'status_map' => [
        'COMPLETED' => 'paid',
        'FAILED' => 'failed',
        'PENDING' => 'pending',
        'TIMEOUT' => 'expired',
    ],

];
