# Laravel Audit Logger

Request/response audit logging with cross-service correlation, for Laravel API services.

Drop it into Api Suite and every backend service behind it, and you get one id that follows a request from the Nuxt frontend, through the proxy, into every service it touches — with the payloads, headers, timings and status codes captured at each hop, and secrets stripped before anything is written.

- **Drivers**: `database`, `file`, `s3`, `stack`, `null` — configurable per service by env var
- **Correlation**: trace id + per-hop request id + parent id, propagated over HTTP and into queued jobs
- **Redaction**: header, body and query-string masking with wildcard and dot-path patterns, plus hashing
- **Fails safe**: an audit failure logs an error, it never breaks a request
- **Tested**: unit and feature coverage across drivers, redaction, correlation, queueing and pruning

---

## Installation

```bash
composer require audit-trail/laravel-audit-logger

php artisan vendor:publish --tag=audit-logger-config
php artisan vendor:publish --tag=audit-logger-migrations   # only if using the database driver
php artisan migrate
```

The middleware registers itself at the front of the global stack. Nothing else to wire up.

### Per-service configuration

Everything meaningful is env-driven, so the same package behaves differently in each service:

```dotenv
# Api Suite — the edge. High traffic.
AUDIT_LOGGER_SERVICE=api-suite
AUDIT_LOGGER_DRIVER=file
AUDIT_LOGGER_TRUST_INCOMING=false      # do not let browsers seed trace ids

# Orders Service — internal, low volume, wants SQL access to its own logs.
AUDIT_LOGGER_SERVICE=orders-service
AUDIT_LOGGER_DRIVER=database

# PIM Service — regulated, long retention, archive to S3.
AUDIT_LOGGER_SERVICE=pim-service
AUDIT_LOGGER_DRIVER=stack              # database + s3
AUDIT_LOGGER_QUEUE=true
```

---

## How correlation works

Three ids, and the distinction is what makes traces reassemblable:

| Id | Scope | Header |
|---|---|---|
| `correlation_id` | The whole journey. Constant across every service. | `X-Correlation-ID` |
| `request_id` | This hop only. Primary key of an audit entry. | `X-Request-ID` |
| `parent_request_id` | The hop that caused this one. Builds the tree. | `X-Parent-Request-ID` |

A request through your stack:

```
Nuxt  ──▶  Api Suite                            correlation=T  request=A  parent=null
              └── outbound call to Orders       correlation=T  request=B  parent=A
                     └── Orders Service         correlation=T  request=C  parent=B
                          
```

## Reading a trace

```bash
php artisan audit:trace 01HQZX4P8YQK2R7V3N6M9TBWCD
```

```
 Service               Direction   Endpoint                  Status   Duration   Request ID
 api-suite             INBOUND     GET /v1/orders            500      142ms      01HQZ...A
   `- api-suite        OUTBOUND    GET /api/orders           500      118ms      01HQZ...B
     `- orders-service INBOUND     GET /api/orders           500      96ms       01HQZ...C

  Hops ................... 3
  Services ............... api-suite, orders-service
  Failures ............... 3
  Slowest hop ............ api-suite GET /v1/orders (142ms)

  ERROR  orders-service threw QueryException: SQLSTATE[HY000] [2002] Connection refused
```

When services log to different places, merge exports in:

```bash
php artisan audit:trace 01HQZX... --file=orders.ndjson --file=billing.ndjson
php artisan audit:trace 01HQZX... --json | jq
```

Programmatically:

```php
use AuditTrail\Laravel\Support\TraceAssembler;

$entries = Audit::driver()->findByCorrelationId($id);
$tree = app(TraceAssembler::class)->tree($entries);
$summary = app(TraceAssembler::class)->summarise($entries);
```

---

## Redaction

Patterns match in two ways:

```php
'body' => [
    'password',        // any key called password, at any depth
    'card.number',     // only that exact path
    'items.*.token',   // token inside any element of items
],
'hash' => ['email'],   // replaced with a stable salted hash, so you can still
                       // group by the value without storing it
```

Also handled without configuration: uploaded files are recorded as name/size/mime rather than contents; non-UTF-8 strings become `[BINARY n bytes]`; oversized payloads are truncated to a preview with the original byte count; deeply nested structures stop at `max_depth`.

Test your redaction rules before you trust them — `tests/Unit/PayloadSanitizerTest.php` is a template. A good habit is asserting the raw stored row contains no known-secret substring, not just that a particular key is masked.

---

## Performance

- Entries are built and written in `terminate()`, after the response is flushed to the client under PHP-FPM.
- Set `AUDIT_LOGGER_QUEUE=true` for S3 or a remote database, so the write leaves the request entirely.
- Sampling: `AUDIT_LOGGER_SAMPLE_RATE=0.1` keeps 10% of successful requests. Errors and anything slower than `AUDIT_LOGGER_ALWAYS_LOG_SLOWER_THAN` are **always** kept — the sample never hides the thing you were looking for.
- `max_body_size` (default 64KB) caps payload storage.

Exclude noisy or heavy endpoints:

```php
'exclude' => ['paths' => ['health*', 'up', 'metrics', 'v1/exports/*']],
```

Or opt in explicitly with `include_only.paths`.

---

## S3 layout

Objects are written Hive-partitioned so Athena can query them with no ETL:

```
audit-logs/service=api-suite/date=2026-08-21/hour=14/01HQZX....json
```

```sql
SELECT service, path, status_code, duration_ms
FROM audit_logs
WHERE date = '2026-08-21' AND correlation_id = '01HQZX...'
ORDER BY started_at;
```

Use an S3 lifecycle rule for retention rather than `audit:prune`.

---

## Retention

```php
// routes/console.php or app/Console/Kernel.php
Schedule::command('audit:prune')->dailyAt('03:00');
```

Deletes in chunks so a large table is never locked for long.

---

## Testing your integration

```php
use AuditTrail\Laravel\Facades\Audit;

public function test_it_audits_the_order_endpoint(): void
{
    $audit = Audit::fake();

    $this->postJson('/api/orders', ['sku' => 'ABC', 'password' => 'hunter2'])
        ->assertCreated();

    $audit->assertRecorded(fn ($entry) =>
        $entry->path === '/api/orders'
        && $entry->statusCode === 201
        && $entry->requestBody['password'] === '[REDACTED]'
    );
}
```

Available: `assertRecorded`, `assertNotRecorded`, `assertRecordedCount`, `assertNothingRecorded`, `recorded()`.

---

## API

```php
Audit::tag('tenant_id', $tenant->id);   // attach metadata to this request's entry
Audit::disable();                       // skip auditing for this request
Audit::correlationId();                 // current trace id
Audit::requestId();                     // current hop id
Audit::driver('s3')->store($entry);     // write to a specific driver
Audit::extend('kafka', fn ($config) => new KafkaDriver($config));
```

Querying stored entries (database driver):

```php
use AuditTrail\Laravel\Models\AuditLog;

AuditLog::forCorrelation($id)->get();
AuditLog::forService('orders-service')->failed()->slowerThan(1000)->latest('id')->get();
```

---

## Custom drivers

Implement `AuditDriver`; optionally add `SearchableDriver` (so `audit:trace` can read from it) and `PrunableDriver` (so `audit:prune` can clean it).

```php
Audit::extend('elasticsearch', fn (array $config) => new ElasticsearchDriver($config));
```

```php
// config/audit-logger.php
'drivers' => [
    'elasticsearch' => ['driver' => 'elasticsearch', 'index' => 'audit-logs'],
],
```

---

## Notes on trust

Set `AUDIT_LOGGER_TRUST_INCOMING=false` on any service reachable from the internet. Incoming ids are validated (`[A-Za-z0-9._-]{8,64}`) and discarded if malformed, but on a public edge you generally want to mint your own id rather than let a client choose it. Internal services should keep it `true` — that's what joins them to the trace.
