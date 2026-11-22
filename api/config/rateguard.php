<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Invoice storage disk
    |--------------------------------------------------------------------------
    |
    | The filesystem disk used to store uploaded carrier invoices. This is the
    | ONLY place in the codebase that names a disk: everything else resolves the
    | disk through this config value (see App\Domain\Invoices\Support\InvoiceStorage).
    | Change the env var to move uploads without touching any code.
    |
    */

    'invoice_disk' => env('INVOICE_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Dimensional weight
    |--------------------------------------------------------------------------
    |
    | Default volumetric divisor used when a contract does not define its own.
    | Dimensional weight (kg) = length(cm) * width(cm) * height(cm) / divisor.
    |
    */

    'dim_divisor_default' => 5000,

    /*
    |--------------------------------------------------------------------------
    | Weight rounding
    |--------------------------------------------------------------------------
    |
    | How the expected billable weight is rounded before it is compared with the
    | weight the carrier billed. "ceil_kg" rounds up to the next whole kilogram.
    |
    */

    'weight_rounding' => 'ceil_kg',

    /*
    |--------------------------------------------------------------------------
    | Content gateway (headless WordPress)
    |--------------------------------------------------------------------------
    |
    | Laravel is the ONLY service the React app talks to: it fetches from the
    | WordPress REST API, maps the payload to DTOs and caches it. The base URL
    | is read from configuration exclusively - never from request input - so a
    | caller can never redirect the fetch to another host (no SSRF).
    |
    | Credentials come from the environment; they are never hardcoded and never
    | logged or cached.
    |
    */

    'content' => [

        // e.g. http://wordpress (the Compose service name on the shared net).
        'base_url' => env('WP_BASE_URL', 'http://wordpress'),

        // WordPress user + application password (Basic auth for the REST API).
        'user' => env('WP_USER'),
        'app_password' => env('WP_APP_PASSWORD'),

        'timeout' => (int) env('WP_TIMEOUT', 3),
        'connect_timeout' => (int) env('WP_CONNECT_TIMEOUT', 2),
        'retries' => (int) env('WP_RETRIES', 2),
        'retry_delay_ms' => (int) env('WP_RETRY_DELAY_MS', 200),

        // Stale-while-revalidate window: serve fresh for `fresh_ttl`, revalidate
        // in the background up to 2x, and keep a long-lived copy for `stale_ttl`
        // to fall back on when WordPress is unreachable.
        'fresh_ttl' => (int) env('WP_FRESH_TTL', 300),
        'stale_ttl' => (int) env('WP_STALE_TTL', 86400),

        // Circuit breaker: after this many consecutive failures, stop calling
        // WordPress for `circuit_cooldown` seconds and serve stale instead.
        'circuit_threshold' => (int) env('WP_CIRCUIT_THRESHOLD', 5),
        'circuit_cooldown' => (int) env('WP_CIRCUIT_COOLDOWN', 30),

    ],

    /*
    |--------------------------------------------------------------------------
    | SEO shell (SPA bootstrap)
    |--------------------------------------------------------------------------
    |
    | Laravel serves the HTML for /blog and /docs so crawlers get a real <head>
    | and content; the React SPA then mounts over it.
    |
    |  - index_path: the BUILT React index.html. Laravel reads it, injects the
    |    SEO head tags and the initial data, and returns it (the file read is
    |    cached and revalidated against the file mtime). Only used when the file
    |    exists - i.e. after a production build.
    |  - dev_url: the Vite dev-server entry used by the minimal Blade fallback
    |    shell when there is no build yet (development). Never loaded from the
    |    request: both values come from configuration only.
    |
    */

    'spa' => [

        'index_path' => env('SPA_INDEX_PATH') ?: public_path('spa/index.html'),

        'dev_url' => env('SPA_DEV_URL', 'http://localhost:5173/src/main.tsx'),

    ],

    /*
    |--------------------------------------------------------------------------
    | Billing plans
    |--------------------------------------------------------------------------
    |
    | The catalogue of plans the Billing API advertises. It is the SINGLE place
    | plan definitions live: the API serves them, the organization quota reads
    | them, and no code hardcodes a price or a limit.
    |
    | The values below are PLACEHOLDERS, not final pricing. They are deliberately
    | kept in configuration so the product owner can change prices or limits
    | without a code change or a deploy of application logic.
    |
    |  - price_cents: an integer number of cents (never a float), matching the
    |    rest of the money handling.
    |  - invoice_limit: the number of invoices allowed per billing period; null
    |    means unlimited (enterprise).
    |  - features: free-form boolean flags for the UI.
    |
    | Producing a checkout or customer-portal URL requires a payment provider,
    | which is NOT integrated: /billing/checkout and /billing/portal return
    | 501 until one is wired up.
    |
    */

    'billing' => [

        // Every organization without a subscription is treated as being on this
        // plan (see Organization::currentPlan()).
        'default_plan' => 'free',

        'plans' => [

            [
                'code' => 'free',
                'name' => 'Free',
                'price_cents' => 0,
                'invoice_limit' => 10,
                'features' => [
                    'audit' => true,
                    'csv_export' => false,
                    'api_access' => false,
                    'priority_support' => false,
                ],
            ],

            [
                'code' => 'pro',
                'name' => 'Pro',
                'price_cents' => 9900,
                'invoice_limit' => 200,
                'features' => [
                    'audit' => true,
                    'csv_export' => true,
                    'api_access' => false,
                    'priority_support' => false,
                ],
            ],

            [
                'code' => 'enterprise',
                'name' => 'Enterprise',
                'price_cents' => 49900,
                'invoice_limit' => null,
                'features' => [
                    'audit' => true,
                    'csv_export' => true,
                    'api_access' => true,
                    'priority_support' => true,
                ],
            ],

        ],

    ],

];
