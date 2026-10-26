<?php

return [
    /*
    | Issued by the runtime operator. Keep the secret in your secret store;
    | it is never shown again and must never reach browser code.
    */
    'base_url' => env('INTEGRATION_RUNTIME_URL'),
    'app_id' => env('INTEGRATION_RUNTIME_APP_ID'),
    'key_id' => env('INTEGRATION_RUNTIME_KEY_ID'),
    'secret' => env('INTEGRATION_RUNTIME_SECRET'),

    // Seconds before an HTTP call to the runtime gives up.
    'timeout' => (float) env('INTEGRATION_RUNTIME_TIMEOUT', 15),

    'webhooks' => [
        // Register the receiver route. The operator registers its public
        // HTTPS URL for your app and gives you the endpoint key and secret.
        'enabled' => (bool) env('INTEGRATION_RUNTIME_WEBHOOKS', false),
        'path' => env('INTEGRATION_RUNTIME_WEBHOOK_PATH', 'integration-runtime/webhooks'),

        // endpoint_key => base64url secret. Two entries during a rotation.
        'secrets' => array_filter([
            env('INTEGRATION_RUNTIME_WEBHOOK_KEY_ID', 'default') => env('INTEGRATION_RUNTIME_WEBHOOK_SECRET'),
        ]),

        'max_skew_seconds' => 300,

        // Deliveries are at-least-once. A delivery_id seen within this window
        // is acknowledged without firing events again.
        'dedupe_ttl_seconds' => 7 * 24 * 3600,

        // While a delivery is being handled it holds a claim; a concurrent or
        // repeated delivery gets 429 (retried later) instead of a false 200.
        // Keep it above your longest listener run; it frees a dead worker's claim.
        'claim_ttl_seconds' => 120,
        'cache_store' => env('INTEGRATION_RUNTIME_WEBHOOK_CACHE'),

        // Extra route middleware. Do not add CSRF or session middleware.
        'middleware' => [],
    ],

    'polling' => [
        // WaitForSyncJob: first delay, growth cap, and give-up point.
        'initial_delay_seconds' => 5,
        'max_delay_seconds' => 300,
        'max_attempts' => 60,
        'queue' => env('INTEGRATION_RUNTIME_QUEUE'),
    ],
];
