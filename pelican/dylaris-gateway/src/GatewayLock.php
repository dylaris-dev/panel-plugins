<?php

namespace Dylaris\GatewayPelican;

use Dylaris\GatewayPanel\ModeRefused;
use Dylaris\GatewayPanel\ModeService;
use Illuminate\Support\Facades\Cache;

/**
 * One cache lock for the reconcile and every gateway change, the same name as on Pterodactyl: a change made while a
 * run works would otherwise be undone by the run's older copy of the server row.
 */
final class GatewayLock
{
    public const NAME = 'dylaris-gateway-reconcile';

    /** Expires after 10 minutes, so a crashed run cannot stop reconciling for long. */
    public const RECONCILE_SECONDS = 600;

    /** A change makes a few provider calls of at most 30 s each, plus retries and Wings syncs. */
    public const CHANGE_SECONDS = 300;

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
        $lock = Cache::lock(self::NAME, self::CHANGE_SECONDS);
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

    /** Per admin flag: the first domain an admin may pick can be an admins-only one. */
    public static function labelKey(int $serverId, bool $admin): string
    {
        return "dylaris-gateway-label-{$serverId}-" . ($admin ? 'admin' : 'user');
    }
}
