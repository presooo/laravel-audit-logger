<?php

namespace AuditTrail\Laravel\Drivers;

use AuditTrail\Laravel\Contracts\AuditDriver;
use AuditTrail\Laravel\Data\AuditEntry;

/**
 * Discards everything. Useful in tests and local development.
 */
class NullDriver implements AuditDriver
{
    public function store(AuditEntry $entry): void
    {
        //
    }

    public function storeMany(array $entries): void
    {
        //
    }
}
