<?php

namespace AuditTrail\Laravel\Drivers;

use AuditTrail\Laravel\Contracts\AuditDriver;
use AuditTrail\Laravel\Contracts\PrunableDriver;
use AuditTrail\Laravel\Contracts\SearchableDriver;
use AuditTrail\Laravel\Data\AuditEntry;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;

/**
 * Writes to a relational table. Best default for a single service where you
 * want to query audit data with plain SQL.
 *
 * Uses the query builder rather than Eloquent so that auditing adds no model
 * events, observers or extra queries to the request.
 */
class DatabaseDriver implements AuditDriver, PrunableDriver, SearchableDriver
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected DatabaseManager $db,
        protected array $config = [],
    ) {}

    public function store(AuditEntry $entry): void
    {
        $this->connection()->table($this->table())->insert($this->toRow($entry));
    }

    public function storeMany(array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $this->connection()->table($this->table())->insert(
            array_map(fn (AuditEntry $entry) => $this->toRow($entry), $entries)
        );
    }

    public function findByCorrelationId(string $correlationId): array
    {
        return $this->connection()
            ->table($this->table())
            ->where('correlation_id', $correlationId)
            ->orderBy('started_at')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => $this->fromRow((array) $row))
            ->all();
    }

    public function findByRequestId(string $requestId): ?array
    {
        $row = $this->connection()
            ->table($this->table())
            ->where('request_id', $requestId)
            ->first();

        return $row === null ? null : $this->fromRow((array) $row);
    }

    public function prune(DateTimeInterface $before): int
    {
        $total = 0;

        // Chunked so pruning a large table does not lock it for minutes.
        do {
            $deleted = $this->connection()
                ->table($this->table())
                ->where('created_at', '<', Carbon::parse($before)->toDateTimeString())
                ->limit(1000)
                ->delete();

            $total += $deleted;
        } while ($deleted > 0);

        return $total;
    }

    public function connection(): ConnectionInterface
    {
        return $this->db->connection($this->config['connection'] ?? null);
    }

    public function table(): string
    {
        return (string) ($this->config['table'] ?? 'audit_logs');
    }

    protected function toRow(AuditEntry $entry): array
    {
        $data = $entry->toArray();

        foreach (['request_headers', 'request_body', 'query', 'response_headers', 'response_body', 'tags'] as $jsonColumn) {
            $data[$jsonColumn] = $this->encode($data[$jsonColumn] ?? null);
        }

        $data['duration_ms'] = $data['duration_ms'] === null ? null : (int) round((float) $data['duration_ms']);
        $data['path']        = mb_substr((string) $data['path'], 0, 512);
        $data['user_agent']  = $data['user_agent'] === null ? null : mb_substr((string) $data['user_agent'], 0, 512);
        $data['created_at']  = Carbon::now()->toDateTimeString();
        $data['started_at']  = $this->toDateTime($data['started_at'] ?? null);
        $data['finished_at'] = $this->toDateTime($data['finished_at'] ?? null);

        return $data;
    }

    protected function fromRow(array $row): array
    {
        foreach (['request_headers', 'request_body', 'query', 'response_headers', 'response_body', 'tags'] as $jsonColumn) {
            if (isset($row[$jsonColumn]) && is_string($row[$jsonColumn])) {
                $decoded          = json_decode($row[$jsonColumn], true);
                $row[$jsonColumn] = json_last_error() === JSON_ERROR_NONE ? $decoded : $row[$jsonColumn];
            }
        }

        return $row;
    }

    protected function encode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $encoded === false ? null : $encoded;
    }

    protected function toDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->toDateTimeString();
    }
}
