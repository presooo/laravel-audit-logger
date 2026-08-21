<?php

namespace AuditTrail\Laravel\Drivers;

use AuditTrail\Laravel\Contracts\AuditDriver;
use AuditTrail\Laravel\Contracts\SearchableDriver;
use AuditTrail\Laravel\Data\AuditEntry;
use Closure;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * In memory driver with assertion helpers, so applications that install this
 * package can test their own auditing without touching a database or a bucket.
 *
 *   Audit::fake();
 *   $this->postJson('/orders', [...]);
 *   Audit::assertRecorded(fn ($entry) => $entry->statusCode === 201);
 */
class FakeDriver implements AuditDriver, SearchableDriver
{
    protected array $entries = [];

    public function store(AuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    public function storeMany(array $entries): void
    {
        foreach ($entries as $entry) {
            $this->store($entry);
        }
    }

    public function recorded(?Closure $filter = null): array
    {
        if ($filter === null) {
            return $this->entries;
        }

        return array_values(array_filter($this->entries, fn (AuditEntry $entry) => (bool) $filter($entry)));
    }

    public function flush(): void
    {
        $this->entries = [];
    }

    public function assertRecorded(?Closure $filter = null): void
    {
        PHPUnit::assertNotEmpty(
            $this->recorded($filter),
            'Expected an audit entry to be recorded, but none matched.'
        );
    }

    public function assertNotRecorded(?Closure $filter = null): void
    {
        PHPUnit::assertEmpty(
            $this->recorded($filter),
            'Expected no matching audit entry, but at least one was recorded.'
        );
    }

    public function assertRecordedCount(int $expected): void
    {
        PHPUnit::assertCount($expected, $this->entries);
    }

    public function assertNothingRecorded(): void
    {
        PHPUnit::assertEmpty($this->entries, 'Expected no audit entries to be recorded.');
    }

    public function findByCorrelationId(string $correlationId): array
    {
        return array_map(
            fn (AuditEntry $entry) => $entry->toArray(),
            $this->recorded(fn (AuditEntry $entry) => $entry->correlationId === $correlationId)
        );
    }

    public function findByRequestId(string $requestId): ?array
    {
        $match = $this->recorded(fn (AuditEntry $entry) => $entry->requestId === $requestId);

        return $match === [] ? null : $match[0]->toArray();
    }
}
