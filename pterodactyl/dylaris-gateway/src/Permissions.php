<?php

namespace Dylaris\GatewayPtero;

use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;

/**
 * Pterodactyl names an extension permission ext.<id>.<key>, so Pelican's gateway.view is ext.dylaris-gateway.view here.
 * view: address and status; mode: Direct, Both, Gateway only, plus Real player IP, Bedrock and the Floodgate key;
 * address: choose or change the address. Mode and address include view. Owners and root admins hold all of them
 * implicitly (ServerPolicy::before); subusers get them in the server's user editor. Pterodactyl has no admin roles
 * beyond root admin, so there is nothing to register for roles.
 */
final class Permissions
{
    public const VIEW = 'ext.dylaris-gateway.view';

    public const MODE = 'ext.dylaris-gateway.mode';

    public const ADDRESS = 'ext.dylaris-gateway.address';

    /** The one permission of 0.1.0; the 2026_11_17_000001 migration turns it into the three above. */
    public const OLD_MANAGE = 'ext.dylaris-gateway.manage';

    /** @return array<string, string> key => description, for registerPermissions() */
    public static function keys(): array
    {
        return [
            'view' => 'See the gateway address and status on the Gateway page.',
            'mode' => 'Switch between Direct, Both and Gateway only (creates or removes the route), and Real player IP, Bedrock and the Floodgate key.',
            'address' => 'Choose the gateway address and change it later. The old address stops working.',
        ];
    }

    public static function can(?User $user, string $permission, Server $server): bool
    {
        if ($user === null) {
            return false;
        }
        $implied = $permission === self::VIEW ? [self::VIEW, self::MODE, self::ADDRESS] : [$permission];
        foreach ($implied as $p) {
            if ($user->can($p, $server)) {
                return true;
            }
        }

        return false;
    }
}
