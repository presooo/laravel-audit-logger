<?php

namespace AuditTrail\Laravel\Tests\Unit;

use AuditTrail\Laravel\Context\CorrelationContext;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class CorrelationContextTest extends TestCase
{
    protected function context(array $config = []): CorrelationContext
    {
        return new CorrelationContext(array_merge([
            'header' => 'X-Correlation-ID',
            'request_id_header' => 'X-Request-ID',
            'parent_header' => 'X-Parent-Request-ID',
            'span_header' => 'X-Audit-Span-ID',
            'trust_incoming' => true,
            'share_with_logger' => false,
        ], $config));
    }

    public function test_it_adopts_an_upstream_correlation_id(): void
    {
        $context = $this->context();

        $context->startFromRequest(Request::create('/orders', 'GET', [], [], [], [
            'HTTP_X_CORRELATION_ID' => '01HQZX4P8YQK2R7V3N6M9TBWCD',
            'HTTP_X_PARENT_REQUEST_ID' => '01HQZX4P8YQK2R7V3N6M9TPARE',
        ]));

        $this->assertSame('01HQZX4P8YQK2R7V3N6M9TBWCD', $context->correlationId());
        $this->assertSame('01HQZX4P8YQK2R7V3N6M9TPARE', $context->parentRequestId());
    }

    public function test_it_generates_ids_when_none_arrive(): void
    {
        $context = $this->context();
        $context->startFromRequest(Request::create('/orders'));

        $this->assertNotEmpty($context->correlationId());
        $this->assertNotEmpty($context->requestId());
        $this->assertNull($context->parentRequestId());
        $this->assertNotSame($context->correlationId(), $context->requestId());
    }

    public function test_it_rejects_malformed_incoming_ids(): void
    {
        $context = $this->context();

        $context->startFromRequest(Request::create('/orders', 'GET', [], [], [], [
            'HTTP_X_CORRELATION_ID' => "not a valid id <script>alert('xss')</script>",
        ]));

        $this->assertNotSame("not a valid id <script>alert('xss')</script>", $context->correlationId());
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{26}$/i', $context->correlationId());
    }

    public function test_it_rejects_overlong_incoming_ids(): void
    {
        $context = $this->context();

        $context->startFromRequest(Request::create('/orders', 'GET', [], [], [], [
            'HTTP_X_CORRELATION_ID' => str_repeat('a', 200),
        ]));

        $this->assertLessThanOrEqual(64, strlen($context->correlationId()));
        $this->assertNotSame(str_repeat('a', 200), $context->correlationId());
    }

    public function test_it_ignores_incoming_ids_when_untrusted(): void
    {
        $context = $this->context(['trust_incoming' => false]);

        $context->startFromRequest(Request::create('/orders', 'GET', [], [], [], [
            'HTTP_X_CORRELATION_ID' => '01HQZX4P8YQK2R7V3N6M9TBWCD',
        ]));

        $this->assertNotSame('01HQZX4P8YQK2R7V3N6M9TBWCD', $context->correlationId());
    }

    public function test_propagation_headers_share_the_trace_but_mint_a_new_span(): void
    {
        $context = $this->context();
        $context->startFromRequest(Request::create('/orders'));

        $first = $context->propagationHeaders();
        $second = $context->propagationHeaders();

        $this->assertSame($context->correlationId(), $first['X-Correlation-ID']);
        $this->assertSame($first['X-Parent-Request-ID'], $first['X-Audit-Span-ID']);
        $this->assertNotSame($first['X-Audit-Span-ID'], $second['X-Audit-Span-ID']);
    }

    public function test_it_continues_a_trace_from_a_queued_job(): void
    {
        $context = $this->context();
        $context->continueTrace('01HQZX4P8YQK2R7V3N6M9TBWCD', '01HQZX4P8YQK2R7V3N6M9TPARE');

        $this->assertSame('01HQZX4P8YQK2R7V3N6M9TBWCD', $context->correlationId());
        $this->assertSame('01HQZX4P8YQK2R7V3N6M9TPARE', $context->parentRequestId());
        $this->assertNotSame('01HQZX4P8YQK2R7V3N6M9TPARE', $context->requestId());
    }

    public function test_it_collects_tags(): void
    {
        $context = $this->context();
        $context->startFromRequest(Request::create('/orders'));
        $context->tag('tenant_id', 42)->tag('feature', 'checkout');

        $this->assertSame(['tenant_id' => 42, 'feature' => 'checkout'], $context->tags());
    }
}
