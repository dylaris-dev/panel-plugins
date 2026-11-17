<?php

namespace Dylaris\GatewayPtero\Console;

use Dylaris\GatewayPanel\ReconcileReport;
use Dylaris\GatewayPtero\ReconcileRunner;
use Illuminate\Console\Command;

/** Scheduled every five minutes. Goes through ReconcileRunner, so it shares the operation hook's lock and never throws. */
class ReconcileCommand extends Command
{
    protected $signature = 'dylaris-gateway:reconcile';

    protected $description = 'Bring gateway routes and allocations back in line with the panel.';

    public function handle(): int
    {
        // The runner logs what happened; a run skipped for the lock is not a failure. The summary matches Pelican's.
        $result = ReconcileRunner::run();
        $lines = match (true) {
            $result === false => [ReconcileReport::SKIPPED_LOCK],
            is_string($result) => [$result],
            default => $result->summary(),
        };
        foreach ($lines as $line) {
            $this->line($line);
        }

        // Like Pelican's: a failed run fails the command; not set up yet, or a skipped run, does not.
        return is_string($result) && str_starts_with($result, ReconcileRunner::FAILED) ? self::FAILURE : self::SUCCESS;
    }
}
