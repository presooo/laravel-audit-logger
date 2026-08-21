<?php

namespace AuditTrail\Laravel\Tests\Feature;

use AuditTrail\Laravel\Models\AuditLog;
use AuditTrail\Laravel\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class PruningTest extends TestCase
{
    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempPath());

        parent::tearDown();
    }

    protected function insert(string $requestId, Carbon $createdAt): void
    {
        DB::table('audit_logs')->insert([
            'request_id' => $requestId,
            'correlation_id' => 'trace',
            'service' => 'orders-service',
            'direction' => 'inbound',
            'method' => 'GET',
            'path' => '/api/orders',
            'url' => 'https://orders.test/api/orders',
            'status_code' => 200,
            'created_at' => $createdAt->toDateTimeString(),
            'started_at' => $createdAt->toDateTimeString(),
        ]);
    }

    public function test_it_prunes_entries_older_than_the_retention_period(): void
    {
        $this->insert('old-1', Carbon::now()->subDays(45));
        $this->insert('old-2', Carbon::now()->subDays(31));
        $this->insert('recent', Carbon::now()->subDays(2));

        $this->artisan('audit:prune', ['--days' => 30])->assertSuccessful();

        $remaining = AuditLog::query()->pluck('request_id')->all();

        $this->assertSame(['recent'], $remaining);
    }

    public function test_it_uses_the_configured_retention_period_by_default(): void
    {
        config()->set('audit-logger.retention.days', 7);

        $this->insert('old', Carbon::now()->subDays(10));
        $this->insert('recent', Carbon::now()->subDays(1));

        $this->artisan('audit:prune')->assertSuccessful();

        $this->assertSame(['recent'], AuditLog::query()->pluck('request_id')->all());
    }

    public function test_it_prunes_old_log_files(): void
    {
        config()->set('audit-logger.default', 'file');
        config()->set('audit-logger.drivers.file.path', $this->tempPath('logs'));

        File::ensureDirectoryExists($this->tempPath('logs'));

        $old = $this->tempPath('logs').'/orders-service-2020-01-01.log';
        $new = $this->tempPath('logs').'/orders-service-'.date('Y-m-d').'.log';

        file_put_contents($old, '{}'.PHP_EOL);
        file_put_contents($new, '{}'.PHP_EOL);
        touch($old, Carbon::now()->subDays(60)->getTimestamp());

        $this->artisan('audit:prune', ['--days' => 30])->assertSuccessful();

        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($new);
    }

    public function test_it_warns_when_the_driver_cannot_prune(): void
    {
        config()->set('audit-logger.default', 's3');

        $this->artisan('audit:prune')->assertSuccessful();
    }

    public function test_it_rejects_a_retention_period_below_one_day(): void
    {
        $this->artisan('audit:prune', ['--days' => 0])->assertFailed();
    }
}
