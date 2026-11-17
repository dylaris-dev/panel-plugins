<?php

namespace Dylaris\GatewayPelican\Console;

use Dylaris\GatewayPanel\ReconcileReport;
use Dylaris\GatewayPanel\Reconciler;
use Dylaris\GatewayPelican\GatewayLock;
use Dylaris\GatewayPelican\GatewaySettings;
use Dylaris\GatewayPelican\GatewayUnavailable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled every five minutes. Never throws: a failed run is logged and the next one tries again. Takes the lock every
 * gateway change takes, so a run never works on a server row a change is rewriting.
 */
class ReconcileCommand extends Command
{
    protected $signature = 'dylaris-gateway:reconcile';

    protected $description = 'Bring gateway routes and allocations back in line with the panel.';

    public function handle(): int
    {
        $lock = Cache::lock(GatewayLock::NAME, GatewayLock::RECONCILE_SECONDS);
        if (!$lock->get()) {
            Log::info('DYLARIS gateway reconcile skipped: another run is in progress.');
            $this->line(ReconcileReport::SKIPPED_LOCK);

            return self::SUCCESS;
        }
        try {
            return $this->reconcile();
        } finally {
            $lock->release();
        }
    }

    private function reconcile(): int
    {
        try {
            $report = app(Reconciler::class)->run();
        } catch (GatewayUnavailable $e) {
            // Not set up yet is a normal state and stays quiet; a stored key that fails (APP_KEY changed) must leave a trace.
            if (GatewaySettings::current()->hasKey()) {
                Log::warning('DYLARIS gateway reconcile skipped: ' . $e->getMessage());
            }
            $this->info('DYLARIS gateway reconcile skipped: ' . $e->getMessage());

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('DYLARIS gateway reconcile failed: ' . $e->getMessage(), ['exception' => $e]);
            $this->error('Reconcile failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        foreach ($report->errors as $serverId => $error) {
            Log::warning("DYLARIS gateway reconcile: server $serverId: $error");
        }
        if ($report->stoppedForRetryAfter !== null) {
            Log::info("DYLARIS gateway reconcile: the provider asked to wait {$report->stoppedForRetryAfter} s; the run stopped.");
        }
        // The same summary as the Pterodactyl command prints.
        foreach ($report->summary() as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
