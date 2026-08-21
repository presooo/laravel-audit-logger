<?php

namespace AuditTrail\Laravel\Listeners;

use AuditTrail\Laravel\AuditManager;
use AuditTrail\Laravel\Context\CorrelationContext;
use AuditTrail\Laravel\Data\AuditEntry;
use AuditTrail\Laravel\Data\Direction;
use AuditTrail\Laravel\Support\PayloadSanitizer;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Audits calls this service makes with Laravel's Http client.
 *
 * This is what produces the "Api Suite -> Orders Service" edge in a trace: the
 * span id we stamped on the outgoing request becomes this entry's request_id,
 * and the downstream service records it as its parent_request_id.
 */
class RecordOutboundRequest
{
    public function __construct(
        protected AuditManager $manager,
        protected CorrelationContext $context,
        protected PayloadSanitizer $sanitizer,
    ) {}

    public function handleResponseReceived(ResponseReceived $event): void
    {
        try {
            if (! $this->shouldRecord($event->request)) {
                return;
            }

            $durationMs = null;

            if (isset($event->response->transferStats)) {
                $transferTime = $event->response->transferStats->getTransferTime();
                $durationMs = $transferTime === null ? null : round($transferTime * 1000, 2);
            }

            $this->manager->record($this->entry(
                request: $event->request,
                statusCode: $event->response->status(),
                durationMs: $durationMs,
                responseHeaders: $event->response->headers(),
                responseBody: $event->response->body(),
            ));
        } catch (Throwable $e) {
            $this->report($e);
        }
    }

    public function handleConnectionFailed(ConnectionFailed $event): void
    {
        try {
            if (! $this->shouldRecord($event->request)) {
                return;
            }

            $exception = property_exists($event, 'exception') ? $event->exception : null;

            $this->manager->record($this->entry(
                request: $event->request,
                statusCode: null,
                durationMs: null,
                responseHeaders: [],
                responseBody: null,
                exceptionClass: $exception === null ? 'ConnectionFailed' : get_class($exception),
                exceptionMessage: $exception?->getMessage() ?? 'Connection failed',
            ));
        } catch (Throwable $e) {
            $this->report($e);
        }
    }


    protected function entry(
        ClientRequest $request,
        ?int $statusCode,
        ?float $durationMs,
        array $responseHeaders = [],
        mixed $responseBody = null,
        ?string $exceptionClass = null,
        ?string $exceptionMessage = null,
    ): AuditEntry {
        $url = $request->url();
        $now = Carbon::now();

        return new AuditEntry(
            requestId: $this->spanId($request),
            correlationId: $this->context->correlationId(),
            // The caller of the outbound call is this service's own inbound hop.
            parentRequestId: $this->context->requestId(),
            service: (string) $this->manager->config('service_name', 'unknown-service'),
            environment: (string) config('app.env'),
            direction: Direction::OUTBOUND,
            method: strtoupper($request->method()),
            url: $url,
            path: (string) (parse_url($url, PHP_URL_PATH) ?: '/'),
            route: parse_url($url, PHP_URL_HOST) ?: null,
            statusCode: $statusCode,
            durationMs: $durationMs,
            requestHeaders: $this->sanitizer->headers($request->headers()),
            requestBody: $this->manager->config('outbound.capture_body', true)
                ? $this->sanitizer->body($request->body())
                : null,
            responseHeaders: $this->sanitizer->headers($responseHeaders),
            responseBody: $this->manager->config('outbound.capture_body', true)
                ? $this->sanitizer->body($responseBody)
                : null,
            exceptionClass: $exceptionClass,
            exceptionMessage: $exceptionMessage,
            tags: $this->context->tags(),
            startedAt: $durationMs === null
                ? $now->toIso8601String()
                : $now->copy()->subMilliseconds((int) $durationMs)->toIso8601String(),
            finishedAt: $now->toIso8601String(),
        );
    }

    /**
     * The id we stamped on the outgoing request, so this entry and the
     * downstream service's inbound entry link up. Falls back to a fresh id if
     * propagation was disabled.
     */
    protected function spanId(ClientRequest $request): string
    {
        $header = $this->context->header('span_header', 'X-Audit-Span-ID');

        $value = $request->header($header);
        $value = is_array($value) ? ($value[0] ?? null) : $value;

        return is_string($value) && $value !== '' ? $value : (string) Str::ulid();
    }

    protected function shouldRecord(ClientRequest $request): bool
    {
        if (! $this->manager->config('outbound.enabled', true)) {
            return false;
        }

        $host = parse_url($request->url(), PHP_URL_HOST) ?: '';

        foreach ((array) $this->manager->config('outbound.exclude_hosts', []) as $pattern) {
            if (is_string($pattern) && Str::is($pattern, $host)) {
                return false;
            }
        }

        return true;
    }

    protected function report(Throwable $e): void
    {
        if (! $this->manager->config('swallow_exceptions', true)) {
            throw $e;
        }

        try {
            logger()->error('Outbound audit logging failed: '.$e->getMessage());
        } catch (Throwable) {
            //
        }
    }
}
