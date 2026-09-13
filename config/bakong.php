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

    // The Bakong-registered account ID (email/phone format, e.g. "user@bkrt").
    // BakongService falls back to BAKONG_MERCHANT_ID when this is empty.
    'account_id' => env('BAKONG_ACCOUNT', ''),

    // Human-readable merchant display name shown on the KHQR code.
    // MUST NOT be an email address — use a plain name like "Aok Sothatt".
    // Reads from BAKONG_MERCHANT_NAME first; BakongService falls back to
    // BAKONG_MERCHANT (ignored when it looks like an email) and finally to
    // APP_NAME so checkout never fails on an empty display name.
    'merchant_name' => env('BAKONG_MERCHANT_NAME', ''),

    // Backwards-compatible merchant display-name alias.
    'merchant' => env('BAKONG_MERCHANT', ''),

    // The Bakong merchant email/ID used for routing (e.g. "aok_sothatt@bkrt").
    'merchant_id' => env('BAKONG_MERCHANT_ID', ''),

    // City shown on the KHQR code (e.g. "Phnom Penh").
    'merchant_city' => env('BAKONG_MERCHANT_CITY', 'Phnom Penh'),

    'currency' => env('BAKONG_CURRENCY', 'USD'),

    // Maps the configured currency code (USD/KHR) to the numeric ISO-4217
    // code required by the KHQR SDK (840 = USD, 116 = KHR).
    'currency_codes' => [
        'USD' => 840,
        'KHR' => 116,
    ],

    'timeout' => env('BAKONG_TIMEOUT', 30),

    'callback_url' => env('BAKONG_CALLBACK_URL', ''),

    'webhook_secret' => env('BAKONG_WEBHOOK_SECRET', ''),

    'qr_expiration_minutes' => env('BAKONG_QR_EXPIRATION_MINUTES', 15),

    // Optional branding used in the wallet-deeplink "sourceInfo" payload.
    // If none are set, the deeplink request is sent without sourceInfo.
    'app_icon_url' => env('BAKONG_APP_ICON_URL', ''),

    'app_name' => env('BAKONG_APP_NAME', ''),

    'app_deep_link_callback' => env('BAKONG_APP_DEEP_LINK_CALLBACK', ''),

    /*
    |--------------------------------------------------------------------------
    | Payment Status Mapping
    |--------------------------------------------------------------------------
    | Maps the transaction status strings returned by the Bakong Open API
    | (check_transaction_by_md5 / check_transaction_by_id) to internal payment
    | statuses.
    |
    | Bakong returns data.status values of:
    |   SUCCESSFUL / COMPLETED  → transaction completed
    |   PROCESSING / PENDING    → still in flight
    |   FAILED                  → transaction failed
    |   TIMEOUT                 → transaction timed out
    |   INVALID                 → no transaction found for this MD5 yet
    |                             (stays pending so the customer can still pay
    |                              before the QR's expires_at passes)
    */

    'status_map' => [
        'COMPLETED' => 'paid',
        'SUCCESSFUL' => 'paid',
        'PROCESSING' => 'pending',
        'PENDING' => 'pending',
        'FAILED' => 'failed',
        'TIMEOUT' => 'expired',
        'INVALID' => 'pending',
    ],

];
