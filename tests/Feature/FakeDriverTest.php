<?php

namespace AuditTrail\Laravel\Tests\Feature;

use AuditTrail\Laravel\Data\AuditEntry;
use AuditTrail\Laravel\Facades\Audit;
use AuditTrail\Laravel\Tests\TestCase;

/**
 * Exercises the testing helpers that consumers of this package will use in
 * their own suites.
 */
class FakeDriverTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->post('/api/orders', fn () => response()->json(['id' => 7], 201));
    }

    public function test_it_captures_entries_in_memory_without_touching_storage(): void
    {
        $fake = Audit::fake();

        $this->postJson('/api/orders', ['sku' => 'ABC'])->assertStatus(201);

        $fake->assertRecordedCount(1);
        $fake->assertRecorded(fn (AuditEntry $entry) => $entry->statusCode === 201 && $entry->path === '/api/orders');
        $fake->assertNotRecorded(fn (AuditEntry $entry) => $entry->method === 'DELETE');

        $this->assertCount(0, $this->auditLogs(), 'The database driver should not have been used.');
    }

    public function test_it_can_assert_nothing_was_recorded(): void
    {
        $fake = Audit::fake();

        $fake->assertNothingRecorded();
    }
}
