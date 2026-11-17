<?php

namespace Dylaris\GatewayPelican;

use App\Enums\TablerIcon;
use App\Models\Role;
use App\Models\Server;
use App\Models\Subuser;

/**
 * Three subuser permissions per server: view (address and status), mode (Direct, Both, Gateway only, plus Real player
 * IP, Bedrock and the Floodgate key) and address (choose or change the address). Mode and address include view.
 * Owners and admins with "update server" hold all of them (User::hasPermission). Admin roles get the same three as
 * "view gateway", "mode gateway" and "address gateway", for every server the role can view.
 */
final class Permissions
{
    public const VIEW = 'gateway.view';

    public const MODE = 'gateway.mode';

    public const ADDRESS = 'gateway.address';

    /** The one permission of 0.1.0; the 2026_11_17_000001 migration turns it into the three above. */
    public const OLD_MANAGE = 'gateway.manage';

    public static function register(): void
    {
        // Pelican reads the icon of a new group unconditionally, so it must be given.
        Subuser::registerCustomPermissions('gateway', ['view', 'mode', 'address'], 'dylaris-gateway::permissions', TablerIcon::Route);
        Role::registerCustomPermissions(['gateway' => ['view', 'mode', 'address']]);
        Role::registerCustomModelIcon('gateway', TablerIcon::Route);
    }

    public static function can(string $permission, ?Server $server): bool
    {
        $user = user();
        if ($user === null || $server === null) {
            return false;
        }
        $implied = $permission === self::VIEW ? [self::VIEW, self::MODE, self::ADDRESS] : [$permission];
        foreach ($implied as $p) {
            if ($user->can($p, $server)) {
                return true;
            }
            // The admin role permission ("mode gateway"), for a server the role may see at all.
            if ($user->can(substr($p, strlen('gateway.')) . ' gateway') && $user->can('view', $server)) {
                return true;
            }
        }

        return false;
    }
}
