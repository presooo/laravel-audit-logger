<?php

namespace AuditTrail\Laravel\Drivers;

use AuditTrail\Laravel\AuditManager;
use AuditTrail\Laravel\Contracts\AuditDriver;
use AuditTrail\Laravel\Data\AuditEntry;
use Throwable;

/**
 * Fans an entry out to several drivers at once, e.g. database for fast local
 * lookups plus S3 for cheap long term retention.
 */
class StackDriver implements AuditDriver
{

    public function __construct(
        protected AuditManager $manager,
        protected array $config = [],
    ) {}

    public function store(AuditEntry $entry): void
    {
        $this->each(fn (AuditDriver $driver) => $driver->store($entry));
    }

    public function storeMany(array $entries): void
    {
        $this->each(fn (AuditDriver $driver) => $driver->storeMany($entries));
    }

    protected function each(callable $callback): void
    {
        $continueOnFailure = (bool) ($this->config['continue_on_failure'] ?? true);
        $failure = null;

        foreach ((array) ($this->config['channels'] ?? []) as $channel) {
            try {
                $callback($this->manager->driver($channel));
            } catch (Throwable $e) {
                if (! $continueOnFailure) {
                    throw $e;
                }

                $failure ??= $e;
            }
        }

        if ($failure !== null) {
            // One channel failed but the others were written. Surface it so the
            // manager can report it without losing the successful writes.
            throw $failure;
        }
    }
}
