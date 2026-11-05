<?php

$defaultFrontendUrl = 'https://event-ticket-booking-system-team6-ui.onrender.com';

$configuredOrigins = env(
    'CORS_ALLOWED_ORIGINS',
    env('FRONTEND_URL', $defaultFrontendUrl)
);

$allowedOrigins = array_values(array_unique(array_filter(array_merge(
    array_map('trim', explode(',', (string) $configuredOrigins)),
    [
        (string) env('FRONTEND_URL', $defaultFrontendUrl),
        $defaultFrontendUrl,
    ]
))));

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
