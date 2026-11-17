<?php

namespace Dylaris\GatewayPtero;

use Dylaris\GatewayPanel\ModeRefused;
use Dylaris\GatewayPanel\ModeService;
use Dylaris\GatewayPanel\ReconcileReport;
use Dylaris\GatewayPanel\Reconciler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The one way to run a reconcile: every trigger (operation hook, schedule) takes the same lock. Never throws. Every
 * gateway change takes it too, so a run never works on a server row a change is rewriting.
 */
final class ReconcileRunner
{
    public const LOCK = 'dylaris-gateway-reconcile';

    /** Expires after 10 minutes, so a crashed run cannot stop reconciling for long. */
    private const LOCK_SECONDS = 600;

    /** A change makes a few provider calls of at most 30 s each, plus retries and Wings syncs. */
    public const CHANGE_SECONDS = 300;

    public const FAILED = 'Reconcile failed: ';

    /**
     * Runs one gateway change under the lock, or refuses with ModeService::BUSY.
     *
     * @template T
     *
     * @param  callable(): T  $change
     * @return T
     */
    public static function change(int $serverId, callable $change): mixed
    {
        $lock = Cache::lock(self::LOCK, self::CHANGE_SECONDS);
        if (!$lock->get()) {
            throw new ModeRefused(ModeService::BUSY);
        }
        try {
            return $change();
        } finally {
            $lock->release();
            Cache::forget(self::statusKey($serverId));
        }
    }

    public static function statusKey(int $serverId): string
    {
        return "dylaris-gateway-status-{$serverId}";
    }

    public static function labelKey(int $serverId): string
    {
        return "dylaris-gateway-label-{$serverId}";
    }

    public static function domainsKey(int $nodeId, bool $admin): string
    {
        return "dylaris-gateway-domains-{$nodeId}-" . ($admin ? 'admin' : 'user');
    }

    /** After the node's domain list changed. */
    public static function forgetDomains(int $nodeId): void
    {
        Cache::forget(self::domainsKey($nodeId, true));
        Cache::forget(self::domainsKey($nodeId, false));
    }

    /**
     * @return ReconcileReport|string|false the run's report; a sentence when it could not run (not set up, failed);
     *                                       false when another run held the lock and this one was skipped
     */
    public static function run(): ReconcileReport|string|false
    {
        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);
        if (!$lock->get()) {
            Log::info('DYLARIS gateway reconcile skipped: another run is in progress.');

            return false;
        }
        try {
            return self::reconcile();
        } finally {
            $lock->release();
        }
    }

    private static function reconcile(): ReconcileReport|string
    {
        try {
            $report = app(Reconciler::class)->run();
        } catch (GatewayUnavailable $e) {
            // Not set up yet is a normal state and stays quiet; a stored key that fails (APP_KEY changed) must leave a trace.
            if ($e->getMessage() === GatewaySettings::KEY_UNREADABLE) {
                Log::warning('DYLARIS gateway reconcile skipped: ' . $e->getMessage());
            }

            return 'DYLARIS gateway reconcile skipped: ' . $e->getMessage();
        } catch (\Throwable $e) {
            Log::error('DYLARIS gateway reconcile failed: ' . $e->getMessage(), ['exception' => $e]);

            return self::FAILED . $e->getMessage();
        }
        foreach ($report->errors as $serverId => $error) {
            Log::warning("DYLARIS gateway reconcile: server $serverId: $error");
        }
        if ($report->stoppedForRetryAfter !== null) {
            Log::info("DYLARIS gateway reconcile: the provider asked to wait {$report->stoppedForRetryAfter} s; the run stopped.");
        }

        return $report;
    }
}
