<?php

namespace AuditTrail\Laravel;

use AuditTrail\Laravel\Console\PruneAuditLogsCommand;
use AuditTrail\Laravel\Console\ShowTraceCommand;
use AuditTrail\Laravel\Context\CorrelationContext;
use AuditTrail\Laravel\Http\Middleware\AuditRequests;
use AuditTrail\Laravel\Listeners\RecordOutboundRequest;
use AuditTrail\Laravel\Support\PayloadSanitizer;
use AuditTrail\Laravel\Support\TraceAssembler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Message\RequestInterface;
use Throwable;

class AuditLoggerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/audit-logger.php', 'audit-logger');

        $this->app->singleton(CorrelationContext::class, fn ($app) => new CorrelationContext(
            (array) $app['config']->get('audit-logger.correlation', [])
        ));

        $this->app->singleton(PayloadSanitizer::class, fn ($app) => new PayloadSanitizer(
            (array) $app['config']->get('audit-logger.redaction', []),
            (int) $app['config']->get('audit-logger.capture.max_body_size', 65536),
        ));

        $this->app->singleton(TraceAssembler::class);

        $this->app->singleton(AuditManager::class, fn ($app) => new AuditManager($app));
        $this->app->alias(AuditManager::class, 'audit-logger');
    }

    public function boot(): void
    {
        $this->registerPublishing();
        $this->registerMiddleware();
        $this->registerOutboundPropagation();
        $this->registerOutboundAuditing();
        $this->registerQueuePropagation();

        if ($this->app->runningInConsole()) {
            $this->commands([
                PruneAuditLogsCommand::class,
                ShowTraceCommand::class,
            ]);
        }
    }

    protected function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/audit-logger.php' => $this->app->configPath('audit-logger.php'),
        ], 'audit-logger-config');

        $this->publishes([
            __DIR__.'/../database/migrations/2024_01_01_000000_create_audit_logs_table.php' => $this->app->databasePath(
                'migrations/'.date('Y_m_d_His').'_create_audit_logs_table.php'
            ),
        ], 'audit-logger-migrations');
    }

    /**
     * Prepend rather than append: the middleware then wraps every other piece
     * of middleware, so a request rejected by throttling or auth is still
     * audited, and the recorded duration is the real end to end figure.
     */
    protected function registerMiddleware(): void
    {
        if (! $this->app['config']->get('audit-logger.middleware.auto_register', true)) {
            return;
        }

        try {
            $kernel = $this->app->make(Kernel::class);

            if ($kernel instanceof HttpKernel && ! $kernel->hasMiddleware(AuditRequests::class)) {
                $kernel->prependMiddleware(AuditRequests::class);
            }
        } catch (Throwable) {
            // Console-only or custom kernels: register the middleware yourself.
        }
    }

    /**
     * Stamp correlation headers onto every outgoing Http:: request so the next
     * service joins this trace. Laravel 11+ exposes a global hook; on Laravel
     * 10 use the ->withCorrelation() macro registered below.
     */
    protected function registerOutboundPropagation(): void
    {
        if (! $this->app['config']->get('audit-logger.correlation.propagate_to_outbound_http', true)) {
            return;
        }

        $context = $this->app->make(CorrelationContext::class);

        if (! PendingRequest::hasMacro('withCorrelation')) {
            PendingRequest::macro('withCorrelation', function () use ($context) {
                /** @var PendingRequest $this */
                return $this->withHeaders($context->propagationHeaders());
            });
        }

        if (! method_exists(HttpFactory::class, 'globalRequestMiddleware')) {
            return;
        }

        Http::globalRequestMiddleware(function (RequestInterface $request) use ($context) {
            $correlationHeader = $context->header('header', 'X-Correlation-ID');

            // Respect an explicit ->withCorrelation() or manual header.
            if ($request->hasHeader($correlationHeader)) {
                return $request;
            }

            foreach ($context->propagationHeaders() as $header => $value) {
                $request = $request->withHeader($header, $value);
            }

            return $request;
        });
    }

    protected function registerOutboundAuditing(): void
    {
        if (! $this->app['config']->get('audit-logger.outbound.enabled', true)) {
            return;
        }

        Event::listen(ResponseReceived::class, [RecordOutboundRequest::class, 'handleResponseReceived']);
        Event::listen(ConnectionFailed::class, [RecordOutboundRequest::class, 'handleConnectionFailed']);
    }

    /**
     * Carry the trace into queued jobs, so work dispatched by a request is
     * still linked to the request that caused it.
     */
    protected function registerQueuePropagation(): void
    {
        if (! $this->app['config']->get('audit-logger.correlation.propagate_to_queue', true)) {
            return;
        }

        $context = $this->app->make(CorrelationContext::class);

        Queue::createPayloadUsing(function () use ($context) {
            if (! $context->hasStarted()) {
                return [];
            }

            return [
                'audit' => [
                    'correlation_id' => $context->correlationId(),
                    'parent_request_id' => $context->requestId(),
                ],
            ];
        });

        Event::listen(JobProcessing::class, function (JobProcessing $event) use ($context) {
            try {
                $payload = $event->job->payload();

                if (isset($payload['audit']['correlation_id'])) {
                    $context->continueTrace(
                        $payload['audit']['correlation_id'],
                        $payload['audit']['parent_request_id'] ?? null
                    );
                }
            } catch (Throwable) {
                //
            }
        });
    }

    public function provides(): array
    {
        return [
            AuditManager::class,
            'audit-logger',
            CorrelationContext::class,
            PayloadSanitizer::class,
            TraceAssembler::class,
        ];
    }
}
