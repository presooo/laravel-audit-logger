<?php

namespace AuditTrail\Laravel\Tests\Feature;

use AuditTrail\Laravel\Data\AuditEntry;
use AuditTrail\Laravel\Data\Direction;
use AuditTrail\Laravel\Facades\Audit;
use AuditTrail\Laravel\Models\AuditLog;
use AuditTrail\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * The behaviour that makes this package worth installing across an estate:
 * one id follows a request from Nuxt, through Api Suite, into every backend
 * service, and the entries can be reassembled into a single tree.
 */
class CrossServiceTracingTest extends TestCase
{
    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempPath());

        parent::tearDown();
    }

    protected function defineRoutes($router): void
    {
        // Stands in for an Api Suite endpoint that proxies to a backend service.
        $router->get('/v1/orders', function () {
            $response = Http::withCorrelation()->get('https://orders.internal/api/orders');

            return response()->json($response->json());
        });
    }

    public function test_outbound_calls_carry_the_trace_downstream(): void
    {
        Http::fake(['orders.internal/*' => Http::response(['data' => []], 200)]);

        $this->getJson('/v1/orders', ['X-Correlation-ID' => '01HQZX4P8YQK2R7V3N6M9TBWCD'])->assertOk();

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-Correlation-ID', '01HQZX4P8YQK2R7V3N6M9TBWCD')
                && $request->hasHeader('X-Parent-Request-ID')
                && $request->hasHeader('X-Audit-Span-ID');
        });
    }

    public function test_it_records_inbound_and_outbound_hops_linked_as_parent_and_child(): void
    {
        Http::fake(['orders.internal/*' => Http::response(['data' => []], 200)]);

        $this->getJson('/v1/orders', ['X-Correlation-ID' => '01HQZX4P8YQK2R7V3N6M9TBWCD'])->assertOk();

        $logs = $this->auditLogs();

        $this->assertCount(2, $logs, 'Expected one inbound entry and one outbound entry.');

        $inbound = $logs->firstWhere('direction', Direction::INBOUND);
        $outbound = $logs->firstWhere('direction', Direction::OUTBOUND);

        $this->assertNotNull($inbound);
        $this->assertNotNull($outbound);

        // Same trace...
        $this->assertSame('01HQZX4P8YQK2R7V3N6M9TBWCD', $inbound->correlation_id);
        $this->assertSame('01HQZX4P8YQK2R7V3N6M9TBWCD', $outbound->correlation_id);

        // ...and the outbound call hangs off the inbound request.
        $this->assertSame($inbound->request_id, $outbound->parent_request_id);
        $this->assertSame('https://orders.internal/api/orders', $outbound->url);
        $this->assertSame(200, $outbound->status_code);
    }

    public function test_the_outbound_entry_id_matches_the_span_sent_downstream(): void
    {
        Http::fake(['orders.internal/*' => Http::response(['data' => []], 200)]);

        $sentSpanId = null;

        $this->getJson('/v1/orders')->assertOk();

        Http::assertSent(function ($request) use (&$sentSpanId) {
            $sentSpanId = $request->header('X-Audit-Span-ID')[0] ?? null;

            return true;
        });

        $outbound = AuditLog::query()->outbound()->first();

        $this->assertNotNull($sentSpanId);
        $this->assertSame(
            $sentSpanId,
            $outbound->request_id,
            'The span id sent downstream must be the outbound entry id, so the downstream '
            .'service records it as its parent_request_id.'
        );
    }

    public function test_it_rebuilds_a_trace_across_three_services(): void
    {
        $this->seedThreeServiceTrace();

        $this->artisan('audit:trace', ['correlation' => 'trace-across-services'])
            ->expectsOutputToContain('api-suite')
            ->expectsOutputToContain('orders-service')
            ->assertSuccessful();
    }

    public function test_it_merges_traces_from_other_services_ndjson_exports(): void
    {
        // This service's own entry lives in the database...
        Audit::record($this->makeEntry('api-suite', 'req-api', null, 200));

        // ...while another service shipped its logs as NDJSON.
        $export = $this->tempPath('exports');
        File::ensureDirectoryExists($export);
        $file = $export.'/billing.ndjson';

        file_put_contents($file, json_encode([
            'request_id' => 'req-billing',
            'parent_request_id' => 'req-api',
            'correlation_id' => 'trace-across-services',
            'service' => 'billing-service',
            'direction' => 'inbound',
            'method' => 'POST',
            'path' => '/charges',
            'status_code' => 502,
            'duration_ms' => 300,
            'started_at' => '2026-08-21T10:00:02+00:00',
        ]).PHP_EOL);

        $this->artisan('audit:trace', [
            'correlation' => 'trace-across-services',
            '--file' => [$file],
        ])
            ->expectsOutputToContain('billing-service')
            ->expectsOutputToContain('api-suite')
            ->assertSuccessful();
    }

    public function test_the_trace_command_reports_when_nothing_is_found(): void
    {
        $this->artisan('audit:trace', ['correlation' => 'does-not-exist'])
            ->assertFailed();
    }

    protected function seedThreeServiceTrace(): void
    {
        Audit::record($this->makeEntry('api-suite', 'req-api', null, 500));
        Audit::record($this->makeEntry('api-suite', 'span-out', 'req-api', 500, Direction::OUTBOUND));
        Audit::record($this->makeEntry('orders-service', 'req-orders', 'span-out', 500));
    }

    protected function makeEntry(
        string $service,
        string $requestId,
        ?string $parentId,
        int $status,
        string $direction = Direction::INBOUND
    ): AuditEntry {
        return AuditEntry::fromArray([
            'request_id' => $requestId,
            'correlation_id' => 'trace-across-services',
            'parent_request_id' => $parentId,
            'service' => $service,
            'environment' => 'testing',
            'direction' => $direction,
            'method' => 'GET',
            'url' => 'https://'.$service.'.test/api/orders',
            'path' => '/api/orders',
            'status_code' => $status,
            'duration_ms' => 25,
            'started_at' => '2026-08-21T10:00:00+00:00',
            'finished_at' => '2026-08-21T10:00:01+00:00',
        ]);
    }
}
