<?php

namespace AuditTrail\Laravel\Tests\Feature;

use AuditTrail\Laravel\AuditManager;
use AuditTrail\Laravel\Contracts\AuditDriver;
use AuditTrail\Laravel\Data\AuditEntry;
use AuditTrail\Laravel\Data\Direction;
use AuditTrail\Laravel\Drivers\DatabaseDriver;
use AuditTrail\Laravel\Drivers\FileDriver;
use AuditTrail\Laravel\Drivers\NullDriver;
use AuditTrail\Laravel\Drivers\S3Driver;
use AuditTrail\Laravel\Facades\Audit;
use AuditTrail\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class DriversTest extends TestCase
{
    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempPath());

        parent::tearDown();
    }

    protected function entry(array $overrides = []): AuditEntry
    {
        return AuditEntry::fromArray(array_merge([
            'request_id' => '01HQZX4P8YQK2R7V3N6M9TBWCD',
            'correlation_id' => '01HQZX4P8YQK2R7V3N6M9TTRAC',
            'parent_request_id' => null,
            'service' => 'orders-service',
            'environment' => 'testing',
            'direction' => Direction::INBOUND,
            'method' => 'POST',
            'url' => 'https://orders.test/api/orders?page=1',
            'path' => '/api/orders',
            'status_code' => 201,
            'duration_ms' => 12.34,
            'request_body' => ['sku' => 'ABC'],
            'response_body' => ['id' => 1],
            'started_at' => '2026-08-21T10:00:00+00:00',
            'finished_at' => '2026-08-21T10:00:01+00:00',
        ], $overrides));
    }

    // --- Driver resolution ----------------------------------------------

    public function test_the_driver_is_configurable_per_service(): void
    {
        $manager = $this->app->make(AuditManager::class);

        config()->set('audit-logger.default', 'file');
        $this->assertInstanceOf(FileDriver::class, $manager->driver());

        $this->assertInstanceOf(DatabaseDriver::class, $manager->driver('database'));
        $this->assertInstanceOf(S3Driver::class, $manager->driver('s3'));
        $this->assertInstanceOf(NullDriver::class, $manager->driver('null'));
    }

    public function test_it_supports_custom_drivers(): void
    {
        $driver = new class implements AuditDriver
        {
            /** @var array<int, string> */
            public array $captured = [];

            public function store(AuditEntry $entry): void
            {
                $this->captured[] = $entry->requestId;
            }

            public function storeMany(array $entries): void
            {
                foreach ($entries as $entry) {
                    $this->store($entry);
                }
            }
        };

        config()->set('audit-logger.drivers.custom', ['driver' => 'custom']);
        config()->set('audit-logger.default', 'custom');

        Audit::extend('custom', fn () => $driver);
        Audit::record($this->entry());

        $this->assertSame(['01HQZX4P8YQK2R7V3N6M9TBWCD'], $driver->captured);
    }

    // --- Database --------------------------------------------------------

    public function test_the_database_driver_stores_and_reads_back_an_entry(): void
    {
        config()->set('audit-logger.default', 'database');

        Audit::record($this->entry());

        $log = $this->firstAuditLog();

        $this->assertSame('orders-service', $log->service);
        $this->assertSame(201, $log->status_code);
        $this->assertSame(12, $log->duration_ms, 'Sub-millisecond precision is rounded for storage.');
        $this->assertSame(['sku' => 'ABC'], $log->request_body);

        $found = $this->app->make(AuditManager::class)
            ->driver('database')
            ->findByCorrelationId('01HQZX4P8YQK2R7V3N6M9TTRAC');

        $this->assertCount(1, $found);
        $this->assertSame(['sku' => 'ABC'], $found[0]['request_body']);
    }

    // --- File ------------------------------------------------------------

    public function test_the_file_driver_appends_newline_delimited_json(): void
    {
        config()->set('audit-logger.default', 'file');
        config()->set('audit-logger.drivers.file.path', $this->tempPath('logs'));

        Audit::record($this->entry());
        Audit::record($this->entry(['request_id' => '01HQZX4P8YQK2R7V3N6M9TSECO']));

        $file = $this->tempPath('logs').'/orders-service-2026-08-21.log';

        $this->assertFileExists($file);

        $lines = array_values(array_filter(explode(PHP_EOL, (string) file_get_contents($file))));

        $this->assertCount(2, $lines);
        $this->assertSame('01HQZX4P8YQK2R7V3N6M9TBWCD', json_decode($lines[0], true)['request_id']);
        $this->assertSame('01HQZX4P8YQK2R7V3N6M9TSECO', json_decode($lines[1], true)['request_id']);
    }

    public function test_the_file_driver_can_find_a_trace(): void
    {
        config()->set('audit-logger.default', 'file');
        config()->set('audit-logger.drivers.file.path', $this->tempPath('logs'));

        Audit::record($this->entry());
        Audit::record($this->entry([
            'request_id' => '01HQZX4P8YQK2R7V3N6M9TOTHE',
            'correlation_id' => 'another-trace-id',
        ]));

        $found = $this->app->make(AuditManager::class)
            ->driver('file')
            ->findByCorrelationId('01HQZX4P8YQK2R7V3N6M9TTRAC');

        $this->assertCount(1, $found);
        $this->assertSame('01HQZX4P8YQK2R7V3N6M9TBWCD', $found[0]['request_id']);
    }

    // --- S3 --------------------------------------------------------------

    public function test_the_s3_driver_writes_hive_partitioned_objects(): void
    {
        Storage::fake('s3');

        config()->set('audit-logger.default', 's3');
        config()->set('audit-logger.drivers.s3.disk', 's3');

        Audit::record($this->entry());

        $expected = 'audit-logs/service=orders-service/date=2026-08-21/hour=10/01HQZX4P8YQK2R7V3N6M9TBWCD.json';

        Storage::disk('s3')->assertExists($expected);

        $stored = json_decode((string) Storage::disk('s3')->get($expected), true);

        $this->assertSame('orders-service', $stored['service']);
        $this->assertSame(['sku' => 'ABC'], $stored['request_body']);
    }

    public function test_the_s3_key_pattern_is_configurable(): void
    {
        Storage::fake('s3');

        config()->set('audit-logger.default', 's3');
        config()->set('audit-logger.drivers.s3.prefix', 'traces');
        config()->set('audit-logger.drivers.s3.partition', '{prefix}/{environment}/{Y}/{m}/{d}/{correlation_id}-{request_id}.json');

        Audit::record($this->entry());

        Storage::disk('s3')->assertExists(
            'traces/testing/2026/08/21/01HQZX4P8YQK2R7V3N6M9TTRAC-01HQZX4P8YQK2R7V3N6M9TBWCD.json'
        );
    }

    // --- Stack -----------------------------------------------------------

    public function test_the_stack_driver_writes_to_every_channel(): void
    {
        Storage::fake('s3');

        config()->set('audit-logger.default', 'stack');
        config()->set('audit-logger.drivers.stack.channels', ['database', 'file', 's3']);
        config()->set('audit-logger.drivers.file.path', $this->tempPath('logs'));

        Audit::record($this->entry());

        $this->assertCount(1, $this->auditLogs());
        $this->assertFileExists($this->tempPath('logs').'/orders-service-2026-08-21.log');
        $this->assertCount(1, Storage::disk('s3')->allFiles());
    }

    public function test_the_stack_driver_keeps_writing_when_one_channel_fails(): void
    {
        config()->set('audit-logger.default', 'stack');
        config()->set('audit-logger.drivers.stack.channels', ['database', 'file']);
        config()->set('audit-logger.drivers.stack.continue_on_failure', true);
        config()->set('audit-logger.drivers.file.path', $this->tempPath('logs'));
        config()->set('audit-logger.drivers.database.table', 'missing_table');

        Audit::record($this->entry());

        // The database write blew up, but the file write still happened.
        $this->assertFileExists($this->tempPath('logs').'/orders-service-2026-08-21.log');
    }

    // --- Sampling --------------------------------------------------------

    public function test_sampling_drops_successful_entries(): void
    {
        config()->set('audit-logger.sampling.rate', 0.0);

        Audit::record($this->entry());

        $this->assertCount(0, $this->auditLogs());
    }

    public function test_sampling_keeps_slow_entries(): void
    {
        config()->set('audit-logger.sampling.rate', 0.0);
        config()->set('audit-logger.sampling.always_log_slower_than_ms', 1000);

        Audit::record($this->entry(['duration_ms' => 2500]));

        $this->assertCount(1, $this->auditLogs());
    }
}
