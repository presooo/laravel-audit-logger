<?php

namespace AuditTrail\Laravel\Facades;

use AuditTrail\Laravel\AuditManager;
use AuditTrail\Laravel\Context\CorrelationContext;
use AuditTrail\Laravel\Contracts\AuditDriver;
use AuditTrail\Laravel\Data\AuditEntry;
use AuditTrail\Laravel\Drivers\FakeDriver;
use Closure;
use Illuminate\Support\Facades\Facade;

/**
 * @method static AuditDriver driver(string|null $name = null)
 * @method static string defaultDriver()
 * @method static AuditManager extend(string $name, Closure $callback)
 * @method static void record(AuditEntry $entry)
 * @method static void write(AuditEntry $entry, string|null $driver = null)
 * @method static bool enabled()
 * @method static AuditManager disable()
 * @method static AuditManager enable()
 * @method static FakeDriver fake()
 * @method static bool isFaked()
 * @method static AuditManager tag(string $key, mixed $value)
 * @method static string correlationId()
 * @method static string requestId()
 * @method static CorrelationContext context()
 * @method static mixed config(string $key, mixed $default = null)
 *
 * @see AuditManager
 */
class Audit extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'audit-logger';
    }
}
