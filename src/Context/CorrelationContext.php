<?php

namespace AuditTrail\Laravel\Context;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Holds the identity of the current trace for the lifetime of one request or
 * one queued job.
 *
 * Three ids, and the difference between them matters:
 *
 *   correlation_id     Constant for the entire user journey. Nuxt -> Api Suite
 *                      -> Orders Service -> Billing Service all share this.
 *   request_id         Unique to this hop. The primary key of an audit entry.
 *   parent_request_id  The request_id of whatever caused this hop. This is the
 *                      edge that lets you rebuild the call tree.
 */
class CorrelationContext
{
    protected ?string $correlationId = null;

    protected ?string $requestId = null;

    protected ?string $parentRequestId = null;

    protected array $tags = [];

    public function __construct(protected array $config = []) {}

    /**
     * Seed the context from an incoming HTTP request.
     */
    public function startFromRequest(Request $request): void
    {
        $this->reset();

        $trust = (bool) ($this->config['trust_incoming'] ?? true);

        if ($trust) {
            $this->correlationId   = $this->clean($request->header($this->header('header', 'X-Correlation-ID')));
            $this->parentRequestId = $this->clean($request->header($this->header('parent_header', 'X-Parent-Request-ID')));
            $this->requestId       = $this->clean($request->header($this->header('request_id_header', 'X-Request-ID')));
        }

        $this->correlationId ??= $this->newId();
        $this->requestId     ??= $this->newId();

        $this->shareWithLogger();
    }

    /**
     * Continue an existing trace, e.g. inside a queued job.
     */
    public function continueTrace(?string $correlationId, ?string $parentRequestId = null): void
    {
        $this->reset();

        $this->correlationId   = $this->clean($correlationId) ?? $this->newId();
        $this->parentRequestId = $this->clean($parentRequestId);
        $this->requestId       = $this->newId();

        $this->shareWithLogger();
    }

    public function reset(): void
    {
        $this->correlationId   = null;
        $this->requestId       = null;
        $this->parentRequestId = null;
        $this->tags            = [];
    }

    /**
     * The trace id. Generated lazily so console commands and jobs that never
     * went through the middleware still produce linkable entries.
     */
    public function correlationId(): string
    {
        return $this->correlationId ??= $this->newId();
    }

    public function requestId(): string
    {
        return $this->requestId ??= $this->newId();
    }

    public function parentRequestId(): ?string
    {
        return $this->parentRequestId;
    }

    public function hasStarted(): bool
    {
        return $this->correlationId !== null;
    }

    /**
     * Attach arbitrary metadata to every entry recorded for this request.
     * e.g. Audit::tag('tenant_id', $tenant->id)
     */
    public function tag(string $key, mixed $value): static
    {
        $this->tags[$key] = $value;

        return $this;
    }


    public function tags(array $tags = []): array
    {
        if ($tags !== []) {
            $this->tags = array_merge($this->tags, $tags);
        }

        return $this->tags;
    }

    /**
     * Headers to attach to an outgoing request so the next service downstream
     * joins this trace instead of starting its own.
     *
     * A fresh span id is minted per call; it becomes the request_id of the
     * outbound audit entry AND the parent_request_id of the downstream inbound
     * entry, which is what stitches the two services together.
     *
     */
    public function propagationHeaders(?string $spanId = null): array
    {
        $spanId ??= $this->newId();

        return [
            $this->header('header', 'X-Correlation-ID')           => $this->correlationId(),
            $this->header('parent_header', 'X-Parent-Request-ID') => $spanId,
            $this->header('span_header', 'X-Audit-Span-ID')       => $spanId,
        ];
    }

    /**
     * Headers echoed back to the caller so a frontend can surface the trace id
     * in a bug report.
     */
    public function responseHeaders(): array
    {
        return [
            $this->header('header', 'X-Correlation-ID')        => $this->correlationId(),
            $this->header('request_id_header', 'X-Request-ID') => $this->requestId(),
        ];
    }

    public function newId(): string
    {
        return (string) Str::ulid();
    }

    public function header(string $key, string $default): string
    {
        $value = $this->config[$key] ?? $default;

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * Push the trace ids into the standard Laravel logger so that ordinary
     * Log::info() calls line up with audit entries without any extra work.
     */
    protected function shareWithLogger(): void
    {
        if (! ($this->config['share_with_logger'] ?? true)) {
            return;
        }

        try {
            if (method_exists(Log::getFacadeRoot(), 'shareContext')) {
                Log::shareContext([
                    'correlation_id' => $this->correlationId(),
                    'request_id' => $this->requestId(),
                ]);
            }
        } catch (Throwable) {
            // Logging context is a nicety, never a reason to fail a request.
        }
    }

    /**
     * Ids arrive from the outside world, so treat them as hostile: cap the
     * length and allow only safe characters. Anything else is discarded and a
     * fresh id is generated instead.
     */
    protected function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || strlen($value) > 64) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9._\-]{8,64}$/', $value) === 1 ? $value : null;
    }
}
