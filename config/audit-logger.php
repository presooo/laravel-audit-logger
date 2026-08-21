<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Master Switch
    |--------------------------------------------------------------------------
    | Set to false to disable all auditing without removing the package.
    */

    'enabled' => env('AUDIT_LOGGER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Service Identity
    |--------------------------------------------------------------------------
    | The name written on every entry. This is what lets you tell an "Api Suite"
    | log apart from an "Orders Service" log when you merge traces together.
    | Keep it stable and machine friendly, e.g. "api-suite", "orders-service".
    */

    'service_name' => env('AUDIT_LOGGER_SERVICE', env('APP_NAME', 'unknown-service')),

    /*
    |--------------------------------------------------------------------------
    | Default Driver
    |--------------------------------------------------------------------------
    | Which storage driver this service uses. Configurable per service via the
    | AUDIT_LOGGER_DRIVER env var: database, file, s3, stack or null.
    */

    'default' => env('AUDIT_LOGGER_DRIVER', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Drivers
    |--------------------------------------------------------------------------
    */

    'drivers' => [

        'database' => [
            'driver' => 'database',
            'connection' => env('AUDIT_LOGGER_DB_CONNECTION'),
            'table' => env('AUDIT_LOGGER_DB_TABLE', 'audit_logs'),
        ],

        'file' => [
            'driver' => 'file',
            // Newline delimited JSON, one entry per line. Ship it with Vector,
            // Fluent Bit, Filebeat or the CloudWatch agent if you want.
            'path' => env('AUDIT_LOGGER_FILE_PATH', storage_path('logs/audit')),
            'filename' => env('AUDIT_LOGGER_FILE_NAME', '{service}-{date}.log'),
            'permissions' => 0664,
        ],

        's3' => [
            'driver' => 's3',
            'disk' => env('AUDIT_LOGGER_S3_DISK', 's3'),
            'prefix' => env('AUDIT_LOGGER_S3_PREFIX', 'audit-logs'),
            // Hive style partitioning so Athena / Glue can query it directly.
            'partition' => '{prefix}/service={service}/date={Y-m-d}/hour={H}/{request_id}.json',
            'visibility' => 'private',
        ],

        'stack' => [
            'driver' => 'stack',
            'channels' => ['database', 's3'],
            // If one channel blows up, keep writing to the others.
            'continue_on_failure' => true,
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Queueing
    |--------------------------------------------------------------------------
    | Writes happen in terminate() so they are already off the response path for
    | FPM. For S3 or a remote database you probably still want a queue.
    */

    'queue' => [
        'enabled' => env('AUDIT_LOGGER_QUEUE', false),
        'connection' => env('AUDIT_LOGGER_QUEUE_CONNECTION'),
        'queue' => env('AUDIT_LOGGER_QUEUE_NAME', 'audit'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Correlation
    |--------------------------------------------------------------------------
    | correlation_id    = the trace. Constant for the whole user journey.
    | request_id        = this hop. Unique per service per request.
    | parent_request_id = the hop that caused this one. Builds the tree.
    */

    'correlation' => [
        'header' => 'X-Correlation-ID',
        'request_id_header' => 'X-Request-ID',
        'parent_header' => 'X-Parent-Request-ID',
        'span_header' => 'X-Audit-Span-ID',

        // Trust ids arriving from upstream. Turn OFF on internet facing edges
        // if you do not want clients seeding your trace ids.
        'trust_incoming' => env('AUDIT_LOGGER_TRUST_INCOMING', true),

        // Echo the ids back on the response so a browser / Nuxt app can report them.
        'echo_response_header' => true,

        // Inject the ids into outgoing Http:: calls.
        'propagate_to_outbound_http' => true,

        // Carry the trace into queued jobs dispatched during this request.
        'propagate_to_queue' => true,

        // Add correlation_id to every Log:: line via Log::shareContext().
        'share_with_logger' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | What To Capture
    |--------------------------------------------------------------------------
    */

    'capture' => [
        'request_body' => true,
        'response_body' => true,
        'request_headers' => true,
        'response_headers' => true,
        'query' => true,
        'user' => true,
        'ip' => true,
        'user_agent' => true,
        'memory' => true,

        // Bodies bigger than this (bytes, after encoding) are truncated to a preview.
        'max_body_size' => 65536,

        // Only capture response bodies for these content types. Anything else is
        // recorded as omitted so you never store a 4MB PDF in your database.
        'response_content_types' => [
            'application/json',
            'application/problem+json',
            'application/vnd.api+json',
            'application/xml',
            'text/xml',
            'text/plain',
            'text/html',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Filtering
    |--------------------------------------------------------------------------
    | Patterns support * wildcards and are matched against the request path with
    | and without a leading slash.
    */

    'exclude' => [
        'paths' => [
            'up',
            'health*',
            'horizon*',
            'telescope*',
            '_debugbar*',
            'livewire*',
        ],
        'methods' => ['OPTIONS'],
        'status_codes' => [],
    ],

    // If non-empty, ONLY these paths are audited (whitelist mode).
    'include_only' => [
        'paths' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Redaction
    |--------------------------------------------------------------------------
    | Bare names ("password") match that key at ANY depth.
    | Dotted names ("user.card.number", "items.*.token") match by full path.
    */

    'redaction' => [
        'mask' => '[REDACTED]',

        'headers' => [
            'authorization',
            'proxy-authorization',
            'cookie',
            'set-cookie',
            'x-api-key',
            'x-auth-token',
            'x-csrf-token',
            'x-xsrf-token',
            'php-auth-pw',
        ],

        'body' => [
            'password',
            'password_confirmation',
            'current_password',
            'new_password',
            'secret',
            'client_secret',
            'token',
            'access_token',
            'refresh_token',
            'id_token',
            'api_key',
            'authorization',
            'card_number',
            'cardnumber',
            'cvv',
            'cvc',
            'card.number',
            'iban',
            'sort_code',
            'account_number',
            'national_insurance_number',
            'ssn',
            'date_of_birth',
        ],

        'query' => [
            'token',
            'api_key',
            'signature',
            'password',
        ],

        // Replaced with a hash instead of a mask, so you can still group
        // by the value (e.g. find every request for one email) without storing it.
        'hash' => [
            'email',
        ],
        'hash_algo' => 'sha256',
        'hash_salt' => env('AUDIT_LOGGER_HASH_SALT', env('APP_KEY', '')),

        'max_depth' => 12,
        'max_string_length' => 8192,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sampling
    |--------------------------------------------------------------------------
    | 1.0 logs everything. Drop it on high traffic services; errors and slow
    | requests are always kept regardless of the rate.
    */

    'sampling' => [
        'rate' => (float) env('AUDIT_LOGGER_SAMPLE_RATE', 1.0),
        'always_log_errors' => true,
        'always_log_slower_than_ms' => (int) env('AUDIT_LOGGER_ALWAYS_LOG_SLOWER_THAN', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbound Requests
    |--------------------------------------------------------------------------
    | Audit calls this service makes with Laravel's Http client. This is what
    | gives you "Api Suite -> Orders Service" edges in the trace tree.
    */

    'outbound' => [
        'enabled' => true,
        'capture_body' => true,
        'exclude_hosts' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    | Auto register prepends the middleware to the global stack so it wraps
    | everything (including exceptions thrown in other middleware). Set to false
    | if you would rather register it yourself on specific route groups.
    */

    'middleware' => [
        'auto_register' => env('AUDIT_LOGGER_AUTO_MIDDLEWARE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Failure Handling
    |--------------------------------------------------------------------------
    | Auditing must never take the application down. Failures are swallowed and
    | reported to the standard log channel instead.
    */

    'swallow_exceptions' => env('AUDIT_LOGGER_SWALLOW_EXCEPTIONS', true),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    | Used by `php artisan audit:prune`. For S3 prefer an S3 lifecycle rule.
    */

    'retention' => [
        'days' => (int) env('AUDIT_LOGGER_RETENTION_DAYS', 30),
    ],

];
