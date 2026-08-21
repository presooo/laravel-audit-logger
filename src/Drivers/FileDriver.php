<?php

namespace AuditTrail\Laravel\Drivers;

use AuditTrail\Laravel\Contracts\AuditDriver;
use AuditTrail\Laravel\Contracts\PrunableDriver;
use AuditTrail\Laravel\Contracts\SearchableDriver;
use AuditTrail\Laravel\Data\AuditEntry;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Appends newline delimited JSON, one entry per line, rotated by day and
 * service. Cheap, dependency free, and directly consumable by Vector,
 * Fluent Bit, Filebeat, Loki or the CloudWatch agent.
 *
 * Appends with LOCK_EX so concurrent PHP-FPM workers do not interleave lines.
 */
class FileDriver implements AuditDriver, PrunableDriver, SearchableDriver
{
    public function __construct(protected array $config = []) {}

    public function store(AuditEntry $entry): void
    {
        $this->storeMany([$entry]);
    }

    public function storeMany(array $entries): void
    {
        $lines = [];

        foreach ($entries as $entry) {
            $lines[$this->pathFor($entry)][] = $entry->toJson();
        }

        foreach ($lines as $path => $encoded) {
            $this->ensureDirectoryExists(dirname($path));

            $existed = file_exists($path);

            file_put_contents($path, implode(PHP_EOL, $encoded).PHP_EOL, FILE_APPEND | LOCK_EX);

            if (! $existed && isset($this->config['permissions'])) {
                @chmod($path, (int) $this->config['permissions']);
            }
        }
    }

    public function findByCorrelationId(string $correlationId): array
    {
        $matches = [];

        foreach ($this->logFiles() as $file) {
            foreach ($this->readLines($file) as $entry) {
                if (($entry['correlation_id'] ?? null) === $correlationId) {
                    $matches[] = $entry;
                }
            }
        }

        usort($matches, fn ($a, $b) => strcmp((string) ($a['started_at'] ?? ''), (string) ($b['started_at'] ?? '')));

        return $matches;
    }

    public function findByRequestId(string $requestId): ?array
    {
        foreach ($this->logFiles() as $file) {
            foreach ($this->readLines($file) as $entry) {
                if (($entry['request_id'] ?? null) === $requestId) {
                    return $entry;
                }
            }
        }

        return null;
    }

    public function prune(DateTimeInterface $before): int
    {
        $removed = 0;

        foreach ($this->logFiles() as $file) {
            $modified = filemtime($file);

            if ($modified !== false && $modified < $before->getTimestamp()) {
                if (@unlink($file)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }

    public function directory(): string
    {
        return rtrim((string) ($this->config['path'] ?? sys_get_temp_dir().'/audit-logs'), '/\\');
    }

    public function pathFor(AuditEntry $entry): string
    {
        $date = Carbon::parse($entry->startedAt ?? 'now')->format('Y-m-d');

        $filename = str_replace(
            ['{service}', '{date}', '{direction}'],
            [Str::slug($entry->service) ?: 'service', $date, $entry->direction],
            (string) ($this->config['filename'] ?? '{service}-{date}.log')
        );

        return $this->directory().DIRECTORY_SEPARATOR.$filename;
    }

    protected function logFiles(): array
    {
        $files = glob($this->directory().DIRECTORY_SEPARATOR.'*.log');

        return $files === false ? [] : $files;
    }

    protected function readLines(string $file): array
    {
        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            return [];
        }

        $entries = [];

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $entries[] = $decoded;
            }
        }

        fclose($handle);

        return $entries;
    }

    protected function ensureDirectoryExists(string $directory): void
    {
        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
    }
}
