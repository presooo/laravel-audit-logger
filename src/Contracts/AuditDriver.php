<?php

namespace AuditTrail\Laravel\Contracts;

use AuditTrail\Laravel\Data\AuditEntry;

interface AuditDriver
{
    public function store(AuditEntry $entry): void;

    public function storeMany(array $entries): void;
}
