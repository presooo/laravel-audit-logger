<?php

namespace AuditTrail\Laravel\Tests;

use AuditTrail\Laravel\AuditLoggerServiceProvider;
use AuditTrail\Laravel\Models\AuditLog;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [AuditLoggerServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Audit' => \AuditTrail\Laravel\Facades\Audit::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('audit-logger.service_name', 'test-service');
        $app['config']->set('audit-logger.default', 'database');
        $app['config']->set('audit-logger.redaction.hash_salt', 'test-salt');
        $app['config']->set('app.env', 'testing');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * @return \Illuminate\Support\Collection<int, AuditLog>
     */
    protected function auditLogs()
    {
        return AuditLog::query()->orderBy('id')->get();
    }

    protected function firstAuditLog(): ?AuditLog
    {
        return AuditLog::query()->orderBy('id')->first();
    }

    protected function tempPath(string $suffix = ''): string
    {
        return sys_get_temp_dir().'/audit-logger-tests-'.getmypid().($suffix === '' ? '' : '/'.$suffix);
    }
}
