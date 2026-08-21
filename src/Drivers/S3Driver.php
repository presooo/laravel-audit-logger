<?php

namespace AuditTrail\Laravel\Drivers;

use AuditTrail\Laravel\Contracts\AuditDriver;
use AuditTrail\Laravel\Contracts\SearchableDriver;
use AuditTrail\Laravel\Data\AuditEntry;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Writes one JSON object per entry into S3 (or any Flysystem disk), using Hive
 * style partitioning:
 *
 *   audit-logs/service=api-suite/date=2026-08-21/hour=14/01J....json
 *
 * That layout is directly queryable by Athena and Glue, and lets you set S3
 * lifecycle rules for retention rather than pruning yourself.
 *
 * S3 has per object latency, so pair this driver with queue.enabled = true on
 * anything with real traffic.
 */
class S3Driver implements AuditDriver, SearchableDriver
{
    public function __construct(
        protected FilesystemFactory $filesystem,
        protected array $config = [],
    ) {}

    public function store(AuditEntry $entry): void
    {
        $this->disk()->put(
            $this->keyFor($entry),
            $entry->toJson(),
            $this->options()
        );
    }

    public function storeMany(array $entries): void
    {
        foreach ($entries as $entry) {
            $this->store($entry);
        }
    }

    public function findByCorrelationId(string $correlationId): array
    {
        // S3 is a write optimised sink, not an index. Scanning every object to
        // find a trace would be slow and expensive, so we deliberately do not
        // pretend otherwise: query the data with Athena, or run a `stack`
        // driver with `database` alongside S3 if you want lookups in-app.
        return [];
    }

    public function findByRequestId(string $requestId): ?array
    {
        return null;
    }

    public function disk(): Filesystem
    {
        return $this->filesystem->disk($this->config['disk'] ?? 's3');
    }

    public function keyFor(AuditEntry $entry): string
    {
        $moment = Carbon::parse($entry->startedAt ?? 'now');

        $pattern = (string) ($this->config['partition']
            ?? '{prefix}/service={service}/date={Y-m-d}/hour={H}/{request_id}.json');

        return str_replace(
            ['{prefix}', '{service}', '{environment}', '{direction}', '{Y-m-d}', '{Y}', '{m}', '{d}', '{H}', '{request_id}', '{correlation_id}'],
            [
                trim((string) ($this->config['prefix'] ?? 'audit-logs'), '/'),
                Str::slug($entry->service) ?: 'service',
                Str::slug((string) $entry->environment) ?: 'unknown',
                $entry->direction,
                $moment->format('Y-m-d'),
                $moment->format('Y'),
                $moment->format('m'),
                $moment->format('d'),
                $moment->format('H'),
                $entry->requestId,
                $entry->correlationId,
            ],
            $pattern
        );
    }


    protected function options(): array
    {
        $options = ['ContentType' => 'application/json'];

        if (isset($this->config['visibility'])) {
            $options['visibility'] = $this->config['visibility'];
        }

        if (! empty($this->config['storage_class'])) {
            $options['StorageClass'] = $this->config['storage_class'];
        }

        return $options;
    }
}
