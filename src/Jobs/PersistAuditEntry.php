<?php

namespace AuditTrail\Laravel\Jobs;

use AuditTrail\Laravel\AuditManager;
use AuditTrail\Laravel\Data\AuditEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Persists an audit entry off the request path.
 *
 * The entry travels as a plain array rather than an object, so a deploy that
 * changes the DTO cannot break jobs already sitting on the queue.
 */
class PersistAuditEntry implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public array $backoff = [5, 15];


    public function __construct(public array $payload) {}

    public function handle(AuditManager $manager): void
    {
        // write() rather than record(): the sampling decision was already made
        // when the entry was created, so re-running it here could drop it.
        $manager->write(AuditEntry::fromArray($this->payload));
    }

    public function tags(): array
    {
        return [
            'audit',
            'correlation:'.($this->payload['correlation_id'] ?? 'unknown'),
        ];
    }
}
