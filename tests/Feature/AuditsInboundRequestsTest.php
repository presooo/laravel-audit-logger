<?php

namespace AuditTrail\Laravel\Tests\Feature;

use AuditTrail\Laravel\Facades\Audit;
use AuditTrail\Laravel\Tests\TestCase;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use RuntimeException;

class AuditsInboundRequestsTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->post('/api/orders', fn () => response()->json(['id' => 7, 'status' => 'created'], 201));
        $router->get('/api/orders', fn () => response()->json(['data' => []]));
        $router->get('/api/boom', fn () => throw new RuntimeException('Something went wrong'));
        $router->get('/health', fn () => response()->json(['ok' => true]));
        $router->get('/api/me', function (Request $request) {
            $request->setUserResolver(fn () => new GenericUser(['id' => 99]));

            return response()->json(['id' => 99]);
        });
        $router->get('/api/download', fn () => response()->streamDownload(fn () => print('binary'), 'report.csv'));
    }

    public function test_it_records_an_inbound_request_and_response(): void
    {
        $this->postJson('/api/orders', ['sku' => 'ABC-123', 'quantity' => 2])
            ->assertStatus(201);

        $log = $this->firstAuditLog();

        $this->assertNotNull($log);
        $this->assertSame('test-service', $log->service);
        $this->assertSame('inbound', $log->direction);
        $this->assertSame('POST', $log->method);
        $this->assertSame('/api/orders', $log->path);
        $this->assertSame(201, $log->status_code);
        $this->assertSame('ABC-123', $log->request_body['sku']);
        $this->assertSame(2, $log->request_body['quantity']);
        $this->assertSame(7, $log->response_body['id']);
        $this->assertNotNull($log->duration_ms);
        $this->assertNotNull($log->started_at);
        $this->assertNotNull($log->finished_at);
        $this->assertSame('testing', $log->environment);
    }

    public function test_it_redacts_sensitive_body_fields_and_headers(): void
    {
        $this->postJson('/api/orders', [
            'sku' => 'ABC-123',
            'password' => 'hunter2',
            'customer' => ['card' => ['number' => '4111111111111111']],
        ], [
            'Authorization' => 'Bearer a-very-secret-token',
            'X-Api-Key' => 'secret-key',
        ]);

        $log = $this->firstAuditLog();

        $this->assertSame('[REDACTED]', $log->request_body['password']);
        $this->assertSame('[REDACTED]', $log->request_body['customer']['card']['number']);
        $this->assertSame('ABC-123', $log->request_body['sku']);
        $this->assertSame('[REDACTED]', $log->request_headers['authorization']);
        $this->assertSame('[REDACTED]', $log->request_headers['x-api-key']);

        // Nothing sensitive should survive anywhere in the stored row.
        $raw = json_encode($log->toArray());
        $this->assertStringNotContainsString('hunter2', $raw);
        $this->assertStringNotContainsString('4111111111111111', $raw);
        $this->assertStringNotContainsString('a-very-secret-token', $raw);
    }

    public function test_it_redacts_sensitive_query_parameters(): void
    {
        $this->getJson('/api/orders?page=2&token=super-secret');

        $log = $this->firstAuditLog();

        $this->assertSame('2', $log->query['page']);
        $this->assertSame('[REDACTED]', $log->query['token']);
        $this->assertStringNotContainsString('super-secret', json_encode($log->query));
    }

    public function test_it_adopts_the_upstream_correlation_id_and_records_the_parent(): void
    {
        $this->getJson('/api/orders', [
            'X-Correlation-ID' => '01HQZX4P8YQK2R7V3N6M9TBWCD',
            'X-Parent-Request-ID' => '01HQZX4P8YQK2R7V3N6M9TPARE',
        ]);

        $log = $this->firstAuditLog();

        $this->assertSame('01HQZX4P8YQK2R7V3N6M9TBWCD', $log->correlation_id);
        $this->assertSame('01HQZX4P8YQK2R7V3N6M9TPARE', $log->parent_request_id);
        $this->assertNotSame($log->parent_request_id, $log->request_id);
    }

    public function test_it_echoes_correlation_headers_on_the_response(): void
    {
        $response = $this->getJson('/api/orders', ['X-Correlation-ID' => '01HQZX4P8YQK2R7V3N6M9TBWCD']);

        $response->assertHeader('X-Correlation-ID', '01HQZX4P8YQK2R7V3N6M9TBWCD');
        $this->assertNotEmpty($response->headers->get('X-Request-ID'));
    }

    public function test_it_records_exceptions_with_the_failed_response(): void
    {
        $this->getJson('/api/boom')->assertStatus(500);

        $log = $this->firstAuditLog();

        $this->assertSame(500, $log->status_code);
        $this->assertSame(RuntimeException::class, $log->exception_class);
        $this->assertSame('Something went wrong', $log->exception_message);
    }

    public function test_it_skips_excluded_paths(): void
    {
        $this->getJson('/health')->assertOk();

        $this->assertCount(0, $this->auditLogs());
    }

    public function test_it_respects_include_only_whitelisting(): void
    {
        config()->set('audit-logger.include_only.paths', ['api/orders*']);

        $this->getJson('/api/me')->assertOk();
        $this->getJson('/api/orders')->assertOk();

        $logs = $this->auditLogs();

        $this->assertCount(1, $logs);
        $this->assertSame('/api/orders', $logs->first()->path);
    }

    public function test_it_captures_the_authenticated_user(): void
    {
        $this->getJson('/api/me')->assertOk();

        $log = $this->firstAuditLog();

        $this->assertSame('99', $log->user_id);
        $this->assertSame(GenericUser::class, $log->user_type);
    }

    public function test_it_does_not_store_streamed_response_bodies(): void
    {
        $this->get('/api/download')->assertOk();

        $log = $this->firstAuditLog();

        $this->assertSame('streamed or file response', $log->response_body['_omitted']);
    }

    public function test_it_stores_tags_added_during_the_request(): void
    {
        $this->app['router']->get('/api/tagged', function () {
            Audit::tag('tenant_id', 42);

            return response()->json(['ok' => true]);
        });

        $this->getJson('/api/tagged')->assertOk();

        $this->assertSame(42, $this->firstAuditLog()->tags['tenant_id']);
    }

    public function test_it_can_be_disabled_globally(): void
    {
        config()->set('audit-logger.enabled', false);

        $this->getJson('/api/orders')->assertOk();

        $this->assertCount(0, $this->auditLogs());
    }

    public function test_it_can_be_disabled_for_a_single_request(): void
    {
        $this->app['router']->get('/api/private', function () {
            Audit::disable();

            return response()->json(['ok' => true]);
        });

        $this->getJson('/api/private')->assertOk();

        $this->assertCount(0, $this->auditLogs());
    }

    public function test_it_never_breaks_the_request_when_the_driver_fails(): void
    {
        config()->set('audit-logger.drivers.database.table', 'a_table_that_does_not_exist');

        $this->getJson('/api/orders')->assertOk();

        $this->assertTrue(true, 'The request completed despite the audit write failing.');
    }

    public function test_it_always_records_errors_even_when_sampling_is_off(): void
    {
        config()->set('audit-logger.sampling.rate', 0.0);
        config()->set('audit-logger.sampling.always_log_errors', true);

        $this->getJson('/api/orders')->assertOk();
        $this->assertCount(0, $this->auditLogs());

        $this->getJson('/api/boom');
        $this->assertCount(1, $this->auditLogs());
    }
}
