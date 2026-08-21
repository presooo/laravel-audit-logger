<?php

namespace AuditTrail\Laravel\Console;

use AuditTrail\Laravel\AuditManager;
use AuditTrail\Laravel\Contracts\PrunableDriver;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Deletes audit entries older than the retention period.
 *
 * Schedule it in your app:  $schedule->command('audit:prune')->daily();
 * For S3, prefer a bucket lifecycle rule instead.
 */
class PruneAuditLogsCommand extends Command
{
    protected $signature = 'audit:prune
        {--days= : Override the configured retention period}
        {--driver= : Prune a specific driver instead of the configured one}';

    protected $description = 'Delete audit entries older than the retention period';

    public function handle(AuditManager $manager): int
    {
        $days = (int) ($this->option('days') ?? $manager->config('retention.days', 30));

        if ($days < 1) {
            $this->components->error('Retention must be at least 1 day.');

            return self::FAILURE;
        }

        $driver = $manager->driver($this->option('driver') ? (string) $this->option('driver') : null);

        if (! $driver instanceof PrunableDriver) {
            $this->components->warn(sprintf(
                'Driver [%s] does not support pruning. Use an S3 lifecycle rule or your log shipper instead.',
                get_class($driver)
            ));

            return self::SUCCESS;
        }

        $before = Carbon::now()->subDays($days);

        $this->components->task(
            "Pruning audit entries older than {$before->toDateTimeString()}",
            function () use ($driver, $before, &$deleted) {
                $deleted = $driver->prune($before);

                return true;
            }
        );

        $this->components->info(($deleted ?? 0).' audit entries pruned.');

        return self::SUCCESS;
    }
}
