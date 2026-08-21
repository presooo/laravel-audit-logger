<?php

namespace AuditTrail\Laravel\Tests\Feature;

use AuditTrail\Laravel\Data\AuditEntry;
use AuditTrail\Laravel\Facades\Audit;
use AuditTrail\Laravel\Jobs\PersistAuditEntry;
use AuditTrail\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Queue;

class QueueingTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->get('/api/orders', fn () => response()->json(['data' => []]));
    }

    protected function entry(): AuditEntry
    {
        return AuditEntry::fromArray([
            'request_id' => '01HQZX4P8YQK2R7V3N6M9TBWCD',
            'correlation_id' => '01HQZX4P8YQK2R7V3N6M9TTRAC',
            'service' => 'orders-service',
            'direction' => 'inbound',
            'method' => 'GET',
            'url' => 'https://orders.test/api/orders',
            'path' => '/api/orders',
            'status_code' => 200,
            'duration_ms' => 5,
            'started_at' => '2026-08-21T10:00:00+00:00',
        ]);
    }

    public function test_it_writes_inline_when_queueing_is_disabled(): void
    {
        Queue::fake();
        config()->set('audit-logger.queue.enabled', false);

        $this->getJson('/api/orders')->assertOk();

        Queue::assertNothingPushed();
        $this->assertCount(1, $this->auditLogs());
    }

    public function test_it_pushes_a_job_when_queueing_is_enabled(): void
    {
        Queue::fake();
        config()->set('audit-logger.queue.enabled', true);
        config()->set('audit-logger.queue.queue', 'audit');

        $this->getJson('/api/orders')->assertOk();

        Queue::assertPushed(PersistAuditEntry::class, function (PersistAuditEntry $job) {
            return $job->queue === 'audit'
                && $job->payload['path'] === '/api/orders'
                && $job->payload['status_code'] === 200;
        });

        $this->assertCount(0, $this->auditLogs(), 'Nothing is written until the job runs.');
    }

    public function test_the_job_persists_the_entry(): void
    {
        (new PersistAuditEntry($this->entry()->toArray()))->handle($this->app->make(\AuditTrail\Laravel\AuditManager::class));

        $log = $this->firstAuditLog();

        $this->assertNotNull($log);
        $this->assertSame('01HQZX4P8YQK2R7V3N6M9TBWCD', $log->request_id);
    }

    public function test_the_job_bypasses_sampling_so_a_kept_entry_is_never_dropped_twice(): void
    {
        config()->set('audit-logger.sampling.rate', 0.0);

        (new PersistAuditEntry($this->entry()->toArray()))->handle($this->app->make(\AuditTrail\Laravel\AuditManager::class));

        $this->assertCount(1, $this->auditLogs());
    }

    public function test_the_entry_survives_a_round_trip_through_the_queue_payload(): void
    {
        $original = $this->entry();
        $restored = AuditEntry::fromArray(json_decode(json_encode($original->toArray()), true));

        $this->assertEquals($original->toArray(), $restored->toArray());
    }

    public function test_the_trace_context_is_available_to_work_dispatched_during_the_request(): void
    {
        config()->set('audit-logger.queue.enabled', true);
        Queue::fake();

        $this->getJson('/api/orders', ['X-Correlation-ID' => '01HQZX4P8YQK2R7V3N6M9TBWCD'])->assertOk();

        $this->assertSame('01HQZX4P8YQK2R7V3N6M9TBWCD', Audit::correlationId());
    }
}
