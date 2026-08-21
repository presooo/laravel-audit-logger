<?php

namespace AuditTrail\Laravel;

use AuditTrail\Laravel\Context\CorrelationContext;
use AuditTrail\Laravel\Contracts\AuditDriver;
use AuditTrail\Laravel\Data\AuditEntry;
use AuditTrail\Laravel\Drivers\DatabaseDriver;
use AuditTrail\Laravel\Drivers\FakeDriver;
use AuditTrail\Laravel\Drivers\FileDriver;
use AuditTrail\Laravel\Drivers\NullDriver;
use AuditTrail\Laravel\Drivers\S3Driver;
use AuditTrail\Laravel\Drivers\StackDriver;
use AuditTrail\Laravel\Jobs\PersistAuditEntry;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Entry point for the package. Resolves drivers, applies sampling, and decides
 * whether a write happens inline or on a queue.
 *
 * Nothing in here is allowed to break the host application: every write is
 * guarded and failures are reported to the standard log channel instead.
 */
class AuditManager
{
    protected array $drivers = [];

    protected array $customCreators = [];

    protected bool $disabled = false;

    protected ?FakeDriver $fake = null;

    public function __construct(protected Container $app) {}

    /**
     * Resolve a driver by name, defaulting to the configured driver for this
     * service. This is what makes the storage target configurable per service.
     */
    public function driver(?string $name = null): AuditDriver
    {
        if ($this->fake !== null) {
            return $this->fake;
        }

        $name ??= $this->defaultDriver();

        return $this->drivers[$name] ??= $this->resolve($name);
    }

    public function defaultDriver(): string
    {
        return (string) $this->config('default', 'database');
    }

    /**
     * Register a custom driver, e.g. Elasticsearch or Kafka.
     */
    public function extend(string $name, Closure $callback): static
    {
        $this->customCreators[$name] = $callback;

        unset($this->drivers[$name]);

        return $this;
    }

    /**
     * Record an entry. Honours sampling, queueing and the master switch.
     */
    public function record(AuditEntry $entry): void
    {
        if (! $this->enabled() || ! $this->shouldSample($entry)) {
            return;
        }

        try {
            if ($this->fake === null && $this->config('queue.enabled', false)) {
                $this->dispatchToQueue($entry);

                return;
            }

            $this->write($entry);
        } catch (Throwable $e) {
            $this->handleFailure($e, $entry);
        }
    }

    /**
     * Write straight to the driver, bypassing sampling and queueing. Used by
     * the queued job so that a sampled-in entry is never dropped twice.
     */
    public function write(AuditEntry $entry, ?string $driver = null): void
    {
        $this->driver($driver)->store($entry);
    }

    public function enabled(): bool
    {
        return ! $this->disabled && (bool) $this->config('enabled', true);
    }

    /**
     * Turn auditing off for the remainder of this request or job. Useful for
     * endpoints that stream large files or handle raw card data.
     */
    public function disable(): static
    {
        $this->disabled = true;

        return $this;
    }

    public function enable(): static
    {
        $this->disabled = false;

        return $this;
    }

    /**
     * Swap in an in-memory driver for tests.
     */
    public function fake(): FakeDriver
    {
        return $this->fake = new FakeDriver;
    }

    public function isFaked(): bool
    {
        return $this->fake !== null;
    }

    /**
     * Attach metadata to every entry recorded during this request.
     */
    public function tag(string $key, mixed $value): static
    {
        $this->context()->tag($key, $value);

        return $this;
    }

    public function correlationId(): string
    {
        return $this->context()->correlationId();
    }

    public function requestId(): string
    {
        return $this->context()->requestId();
    }

    public function context(): CorrelationContext
    {
        return $this->app->make(CorrelationContext::class);
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->app->make('config')->get('audit-logger.'.$key, $default);
    }

    public function driverConfig(string $name): array
    {
        $config = $this->config('drivers.'.$name);

        if (! is_array($config)) {
            throw new InvalidArgumentException("Audit driver [{$name}] is not configured.");
        }

        return $config;
    }

    protected function resolve(string $name): AuditDriver
    {
        $config = $this->driverConfig($name);
        $type = (string) ($config['driver'] ?? $name);

        if (isset($this->customCreators[$type])) {
            return $this->customCreators[$type]($config, $this->app);
        }

        return match ($type) {
            'database' => new DatabaseDriver($this->app->make(DatabaseManager::class), $config),
            'file'     => new FileDriver($config),
            's3'       => new S3Driver($this->app->make(FilesystemFactory::class), $config),
            'stack'    => new StackDriver($this, $config),
            'null'     => new NullDriver,
            default    => throw new InvalidArgumentException("Unsupported audit driver [{$type}]."),
        };
    }

    protected function dispatchToQueue(AuditEntry $entry): void
    {
        $job = new PersistAuditEntry($entry->toArray());

        $connection = $this->config('queue.connection');
        $queue      = $this->config('queue.queue');

        if ($connection !== null) {
            $job->onConnection((string) $connection);
        }

        if ($queue !== null) {
            $job->onQueue((string) $queue);
        }

        $this->app->make(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatch($job);
    }

    /**
     * Errors and slow requests are always kept: they are exactly the ones you
     * went looking for. Everything else is subject to the sample rate.
     */
    protected function shouldSample(AuditEntry $entry): bool
    {
        if ($this->config('sampling.always_log_errors', true) && $entry->failed()) {
            return true;
        }

        $threshold = (int) $this->config('sampling.always_log_slower_than_ms', 0);

        if ($threshold > 0 && ($entry->durationMs ?? 0) >= $threshold) {
            return true;
        }

        $rate = (float) $this->config('sampling.rate', 1.0);

        if ($rate >= 1.0) {
            return true;
        }

        if ($rate <= 0.0) {
            return false;
        }

        return (mt_rand(1, 1_000_000) / 1_000_000) <= $rate;
    }

    protected function handleFailure(Throwable $e, AuditEntry $entry): void
    {
        if (! $this->config('swallow_exceptions', true)) {
            throw $e;
        }

        try {
            Log::error('Audit logging failed: '.$e->getMessage(), [
                'exception' => get_class($e),
                'correlation_id' => $entry->correlationId,
                'request_id' => $entry->requestId,
            ]);
        } catch (Throwable) {
            // If even the logger is down there is nothing sensible left to do.
        }
    }
}
