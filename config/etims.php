<?php

/**
 * KRA eTIMS / Gava Connect SDK Configuration
 *
 * This file is the single source of truth for all SDK settings.
 * Publish with: php artisan vendor:publish --tag=etims-config
 *
 * All sensitive values (credentials, secrets) should be stored in .env
 * and referenced here — never hardcoded.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Environment Mode
    |--------------------------------------------------------------------------
    |
    | Controls which KRA eTIMS endpoint the SDK targets.
    |
    | 'sandbox'    → Use during development and testing. Points to KRA's
    |               non-production environment. No real invoices are submitted.
    |
    | 'production' → Live KRA environment. Real tax submissions. Use with care.
    |
    | The SDK will refuse to use production credentials if APP_ENV is 'testing',
    | adding a safety net against accidental live submissions in test suites.
    |
    */
    'mode' => env('ETIMS_MODE', 'sandbox'),

    /*
    |--------------------------------------------------------------------------
    | API Endpoints
    |--------------------------------------------------------------------------
    |
    | Base URLs for the Gava Connect / eTIMS API environments.
    | These are resolved automatically based on the 'mode' setting above.
    |
    | Override only if KRA changes endpoint URLs between SDK releases,
    | or if you are running a local mock server for integration tests.
    |
    */
    'endpoints' => [
        'sandbox' => env('ETIMS_SANDBOX_URL', 'https://etims-api.kra.go.ke/sandbox/connect'),
        'production' => env('ETIMS_PRODUCTION_URL', 'https://etims-api.kra.go.ke/connect'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | Your KRA-issued credentials for the eTIMS/Gava Connect system.
    |
    | PIN     → Your company's KRA PIN (e.g. P000000000A)
    | Branch  → Branch code as registered with KRA (default '00' for HQ)
    | Secret  → API secret key issued during device initialization
    | DeviceS → Device serial number used during initialization
    |
    | Store these in .env — never commit credentials to version control.
    |
    */
    'credentials' => [
        'pin'           => env('ETIMS_PIN'),
        'branch_id'     => env('ETIMS_BRANCH_ID', '00'),
        'device_serial' => env('ETIMS_DEVICE_SERIAL'),
        'secret'        => env('ETIMS_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Settings
    |--------------------------------------------------------------------------
    |
    | Controls timeouts and retry behavior for outbound HTTP requests.
    |
    | connect_timeout → Maximum seconds to wait for TCP connection
    | timeout         → Maximum seconds to wait for full HTTP response
    | retries         → Number of synchronous retries before throwing
    | retry_delay_ms  → Base delay between retries in milliseconds
    |                   (exponential backoff multiplies this per attempt)
    |
    */
    'http' => [
        'connect_timeout' => env('ETIMS_CONNECT_TIMEOUT', 10),
        'timeout'         => env('ETIMS_TIMEOUT', 30),
        'retries'         => env('ETIMS_HTTP_RETRIES', 3),
        'retry_delay_ms'  => env('ETIMS_RETRY_DELAY_MS', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | The SDK uses Laravel queues for asynchronous invoice submission.
    | This protects your application from API latency and transient failures.
    |
    | connection  → Laravel queue connection to use (null = default connection)
    | queue       → Queue name for eTIMS jobs
    | max_tries   → Maximum job attempts before marking as failed
    | backoff      → Delay in seconds between job retries (exponential)
    | timeout     → Job execution timeout in seconds
    |
    | Recommended: Use a dedicated Redis queue for eTIMS jobs in production.
    | This isolates eTIMS queue failures from your main application queues.
    |
    */
    'queue' => [
        'connection' => env('ETIMS_QUEUE_CONNECTION', null),
        'queue'      => env('ETIMS_QUEUE_NAME', 'etims'),
        'max_tries'  => env('ETIMS_MAX_TRIES', 5),
        'backoff'    => env('ETIMS_BACKOFF', [10, 30, 60, 120, 300]), // seconds
        'timeout'    => env('ETIMS_JOB_TIMEOUT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Cache
    |--------------------------------------------------------------------------
    |
    | Auth tokens from KRA are cached to avoid re-authenticating on every
    | request. Configure which cache store to use and the TTL buffer.
    |
    | store      → Laravel cache store (null = default)
    | ttl_buffer → Seconds before token expiry to trigger a refresh.
    |              Set this to at least 60 to avoid using expired tokens.
    |
    */
    'cache' => [
        'store'      => env('ETIMS_CACHE_STORE', null),
        'ttl_buffer' => env('ETIMS_TOKEN_TTL_BUFFER', 300), // 5 minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | The SDK produces structured logs for all API interactions.
    | Logs are essential for debugging submission failures in production.
    |
    | channel         → Laravel log channel (null = default channel)
    | log_requests    → Log outgoing request payloads (disable in prod if
    |                   invoice data is PII-sensitive)
    | log_responses   → Log raw API responses
    | log_failed_only → Only log when a request fails (reduces noise)
    |
    */
    'logging' => [
        'channel'         => env('ETIMS_LOG_CHANNEL', null),
        'log_requests'    => env('ETIMS_LOG_REQUESTS', true),
        'log_responses'   => env('ETIMS_LOG_RESPONSES', true),
        'log_failed_only' => env('ETIMS_LOG_FAILED_ONLY', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Tables
    |--------------------------------------------------------------------------
    |
    | Table names used by the SDK for audit logs, idempotency tracking,
    | and failed invoice recovery. Change these only if they conflict
    | with your existing schema — run migrations after changing.
    |
    */
    'tables' => [
        'invoices'        => 'etims_invoices',
        'audit_logs'      => 'etims_audit_logs',
        'failed_invoices' => 'etims_failed_invoices',
        'stock_items'     => 'etims_stock_items',
        'stock_movements' => 'etims_stock_movements',
        'branches'        => 'etims_branches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for inbound KRA eTIMS webhook notifications.
    |
    | verify_signature → Verify HMAC-SHA256 signature on every inbound webhook.
    |                    Set to false ONLY in local development. Never in prod.
    | secret           → Shared secret for signature verification.
    |                    Must match what you set in the KRA eTIMS portal.
    | path             → The URL path KRA will POST webhooks to.
    |                    Register this URL in the KRA eTIMS portal.
    |
    */
    'webhooks' => [
        'verify_signature' => env('ETIMS_WEBHOOK_VERIFY_SIGNATURE', true),
        'secret'           => env('ETIMS_WEBHOOK_SECRET'),
        'path'             => env('ETIMS_WEBHOOK_PATH', '/api/webhooks/etims'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-Tenancy
    |--------------------------------------------------------------------------
    |
    | Enable this when building SaaS systems where multiple businesses share
    | the same Laravel installation but have different KRA credentials.
    |
    | When enabled:
    | - The SDK resolves credentials per-tenant via the tenant_resolver
    | - All DB records include a tenant_id column
    | - Queue jobs carry the tenant context
    |
    | tenant_resolver → A class that implements TenantResolverContract.
    |                   The SDK will call it to get the current tenant's config.
    |
    */
    'multi_tenancy' => [
        'enabled'         => env('ETIMS_MULTI_TENANCY', false),
        'tenant_resolver' => null, // e.g. App\Services\EtimsTenantResolver::class
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    |
    | Prevents duplicate invoice submissions even when jobs are retried.
    | The SDK generates a unique key per invoice and checks it before submitting.
    |
    | enabled       → Turn idempotency checking on/off
    | ttl_hours     → How long to remember a submitted invoice key
    |
    */
    'idempotency' => [
        'enabled'   => env('ETIMS_IDEMPOTENCY', true),
        'ttl_hours' => env('ETIMS_IDEMPOTENCY_TTL', 24),
    ],

];
