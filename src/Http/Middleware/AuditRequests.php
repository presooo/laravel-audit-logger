<?php

namespace AuditTrail\Laravel\Http\Middleware;

use AuditTrail\Laravel\AuditManager;
use AuditTrail\Laravel\Context\CorrelationContext;
use AuditTrail\Laravel\Http\RequestRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The single integration point for inbound auditing.
 *
 * handle()    starts the trace (adopting an upstream correlation id if there is
 *             one) and echoes the ids back on the response.
 * terminate() builds and persists the entry AFTER the response has been sent to
 *             the client, so auditing costs the user nothing under PHP-FPM.
 */
class AuditRequests
{
    protected const START_ATTRIBUTE = 'audit_logger.started_at';

    public function __construct(
        protected AuditManager $manager,
        protected CorrelationContext $context,
        protected RequestRecorder $recorder,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            // Reset any per-request disable flag left behind by a previous request
            // on a long-lived worker (Octane, Swoole, RoadRunner).
            $this->manager->enable();
            $this->context->startFromRequest($request);
            $request->attributes->set(self::START_ATTRIBUTE, microtime(true));

        } catch (Throwable) {
            // Correlation is best effort; never block the request over it.
        }

        $response = $next($request);

        return $this->addCorrelationHeaders($response);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if (! $this->manager->enabled()) {
                return;
            }

            if (! $this->recorder->shouldRecord($request, $response)) {
                return;
            }

            $startedAt = (float) $request->attributes->get(self::START_ATTRIBUTE, microtime(true));

            $this->manager->record($this->recorder->build($request, $response, $startedAt));

        } catch (Throwable $e) {
            $this->report($e);
        }
    }

    protected function addCorrelationHeaders(Response $response): Response
    {
        try {
            if (! $this->manager->config('correlation.echo_response_header', true)) {
                return $response;
            }

            foreach ($this->context->responseHeaders() as $header => $value) {
                $response->headers->set($header, $value);
            }
        } catch (Throwable) {
            //
        }

        return $response;
    }

    protected function report(Throwable $e): void
    {
        if (! $this->manager->config('swallow_exceptions', true)) {
            throw $e;
        }

        try {
            logger()->error('Audit middleware failed: '.$e->getMessage(), ['exception' => get_class($e)]);
        } catch (Throwable) {
            //
        }
    }
}
