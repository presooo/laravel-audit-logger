<?php

namespace AuditTrail\Laravel\Console;

use AuditTrail\Laravel\AuditManager;
use AuditTrail\Laravel\Contracts\SearchableDriver;
use AuditTrail\Laravel\Support\TraceAssembler;
use Illuminate\Console\Command;

/**
 * Rebuilds one end-to-end journey and prints it as a tree.
 *
 *   php artisan audit:trace 01J9X4...                      (from this service's driver)
 *   php artisan audit:trace 01J9X4... --file=orders.ndjson  (merge other services' exports)
 *   php artisan audit:trace 01J9X4... --json                (machine readable)
 */
class ShowTraceCommand extends Command
{
    protected $signature = 'audit:trace
        {correlation : The correlation id to rebuild}
        {--driver= : Audit driver to read from (defaults to the configured one)}
        {--file=* : Extra newline delimited JSON exports to merge in}
        {--json : Output raw JSON instead of a table}';

    protected $description = 'Rebuild a request trace across services from audit entries';

    public function handle(AuditManager $manager, TraceAssembler $assembler): int
    {
        $correlationId = (string) $this->argument('correlation');

        $entries = array_merge(
            $this->fromDriver($manager, $correlationId),
            $this->fromFiles((array) $this->option('file'), $correlationId)
        );

        $entries = $this->deduplicate($entries);

        if ($entries === []) {
            $this->components->warn("No audit entries found for correlation id [{$correlationId}].");

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($assembler->tree($entries), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->renderTable($assembler, $entries);
        $this->renderSummary($assembler, $entries);

        return self::SUCCESS;
    }


    protected function fromDriver(AuditManager $manager, string $correlationId): array
    {
        $driver = $manager->driver($this->option('driver') ? (string) $this->option('driver') : null);

        if (! $driver instanceof SearchableDriver) {
            $this->components->warn(sprintf(
                'Driver [%s] cannot be searched. Pass exports with --file, or query the data where it lives.',
                get_class($driver)
            ));

            return [];
        }

        return $driver->findByCorrelationId($correlationId);
    }

    /**
     * Merge NDJSON exports produced by other services. This is how you rebuild
     * a trace when each service writes to its own file or bucket.
     */
    protected function fromFiles(array $files, string $correlationId): array
    {
        $entries = [];

        foreach ($files as $file) {
            if (! is_readable($file)) {
                $this->components->warn("Cannot read [{$file}].");

                continue;
            }

            $handle = fopen($file, 'rb');

            if ($handle === false) {
                continue;
            }

            while (($line = fgets($handle)) !== false) {
                $decoded = json_decode(trim($line), true);

                if (is_array($decoded) && ($decoded['correlation_id'] ?? null) === $correlationId) {
                    $entries[] = $decoded;
                }
            }

            fclose($handle);
        }

        return $entries;
    }


    protected function deduplicate(array $entries): array
    {
        $seen = [];
        $unique = [];

        foreach ($entries as $entry) {
            $key = (string) ($entry['request_id'] ?? spl_object_hash((object) $entry));

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $entry;
        }

        return $unique;
    }


    protected function renderTable(TraceAssembler $assembler, array $entries): void
    {
        $rows = [];

        foreach ($assembler->flatten($entries) as $row) {

            $indent = str_repeat('  ', (int) ($row['depth'] ?? 0));
            $arrow  = ($row['depth'] ?? 0) > 0 ? '`- ' : '';
            $status = $row['status_code'] ?? '-';

            $rows[] = [
                $indent.$arrow.($row['service'] ?? '?'),
                strtoupper((string) ($row['direction'] ?? '')),
                strtoupper((string) ($row['method'] ?? '')).' '.($row['path'] ?? ''),
                (int) $status >= 400 ? "<fg=red>{$status}</>" : (string) $status,
                $row['duration_ms'] === null ? '-' : $row['duration_ms'].'ms',
                (string) ($row['request_id'] ?? ''),
            ];
        }

        $this->table(['Service', 'Direction', 'Endpoint', 'Status', 'Duration', 'Request ID'], $rows);
    }


    protected function renderSummary(TraceAssembler $assembler, array $entries): void
    {
        $summary = $assembler->summarise($entries);

        $this->newLine();
        $this->components->twoColumnDetail('Hops', (string) $summary['hops']);
        $this->components->twoColumnDetail('Services', implode(', ', array_keys($summary['services'])));
        $this->components->twoColumnDetail('Failures', (string) $summary['failures']);

        if (! empty($summary['slowest'])) {
            $this->components->twoColumnDetail(
                'Slowest hop',
                sprintf(
                    '%s %s %s (%sms)',
                    $summary['slowest']['service'] ?? '?',
                    strtoupper((string) ($summary['slowest']['method'] ?? '')),
                    $summary['slowest']['path'] ?? '',
                    $summary['slowest']['duration_ms'] ?? '?'
                )
            );
        }

        foreach ($entries as $entry) {
            if (! empty($entry['exception_class'])) {
                $this->newLine();
                $this->components->error(sprintf(
                    '%s threw %s: %s',
                    $entry['service'] ?? '?',
                    $entry['exception_class'],
                    $entry['exception_message'] ?? ''
                ));
            }
        }
    }
}
