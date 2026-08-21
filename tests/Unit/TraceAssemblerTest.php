<?php

namespace AuditTrail\Laravel\Tests\Unit;

use AuditTrail\Laravel\Support\TraceAssembler;
use PHPUnit\Framework\TestCase;

class TraceAssemblerTest extends TestCase
{
    /**
     * A realistic three service trace:
     *   api-suite (inbound)
     *     `- api-suite (outbound to orders)
     *          `- orders-service (inbound)
     */
    protected function entries(): array
    {
        return [
            [
                'request_id' => 'req-orders',
                'parent_request_id' => 'span-outbound',
                'correlation_id' => 'trace-1',
                'service' => 'orders-service',
                'direction' => 'inbound',
                'method' => 'GET',
                'path' => '/orders',
                'status_code' => 500,
                'duration_ms' => 90,
                'started_at' => '2026-08-21T10:00:00+00:00',
            ],
            [
                'request_id' => 'req-api-suite',
                'parent_request_id' => null,
                'correlation_id' => 'trace-1',
                'service' => 'api-suite',
                'direction' => 'inbound',
                'method' => 'GET',
                'path' => '/v1/orders',
                'status_code' => 500,
                'duration_ms' => 140,
                'started_at' => '2026-08-21T09:59:59+00:00',
            ],
            [
                'request_id' => 'span-outbound',
                'parent_request_id' => 'req-api-suite',
                'correlation_id' => 'trace-1',
                'service' => 'api-suite',
                'direction' => 'outbound',
                'method' => 'GET',
                'path' => '/orders',
                'status_code' => 500,
                'duration_ms' => 100,
                'started_at' => '2026-08-21T09:59:59+00:00',
            ],
        ];
    }

    public function test_it_builds_a_tree_from_parent_ids(): void
    {
        $tree = (new TraceAssembler)->tree($this->entries());

        $this->assertCount(1, $tree);
        $this->assertSame('req-api-suite', $tree[0]['request_id']);
        $this->assertSame('span-outbound', $tree[0]['children'][0]['request_id']);
        $this->assertSame('req-orders', $tree[0]['children'][0]['children'][0]['request_id']);
    }

    public function test_it_flattens_with_depth(): void
    {
        $rows = (new TraceAssembler)->flatten($this->entries());

        $this->assertSame([0, 1, 2], array_column($rows, 'depth'));
        $this->assertSame(
            ['api-suite', 'api-suite', 'orders-service'],
            array_column($rows, 'service')
        );
    }

    public function test_it_treats_orphans_as_roots(): void
    {
        $entries = $this->entries();
        $entries[] = [
            'request_id' => 'req-orphan',
            // Parent lives in a service whose logs we did not merge in.
            'parent_request_id' => 'missing-parent',
            'correlation_id' => 'trace-1',
            'service' => 'billing-service',
            'started_at' => '2026-08-21T10:00:05+00:00',
        ];

        $tree = (new TraceAssembler)->tree($entries);

        $this->assertCount(2, $tree);
    }

    public function test_it_summarises_a_trace(): void
    {
        $summary = (new TraceAssembler)->summarise($this->entries());

        $this->assertSame(3, $summary['hops']);
        $this->assertSame(3, $summary['failures']);
        $this->assertSame(['orders-service' => 1, 'api-suite' => 2], $summary['services']);
        $this->assertSame('req-api-suite', $summary['slowest']['request_id']);
    }

    public function test_it_handles_an_empty_set(): void
    {
        $assembler = new TraceAssembler;

        $this->assertSame([], $assembler->tree([]));
        $this->assertSame([], $assembler->flatten([]));
    }
}
