<?php

namespace AuditTrail\Laravel\Contracts;

use DateTimeInterface;

interface PrunableDriver
{
    /**
     * Delete entries recorded before the given moment. Returns the number removed.
     */
    public function prune(DateTimeInterface $before): int;
}
