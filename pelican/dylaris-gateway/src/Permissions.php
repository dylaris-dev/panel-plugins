<?php

namespace Dylaris\GatewayPelican;

use App\Enums\TablerIcon;
use App\Models\Subuser;

final class Permissions
{
    /** Owners and panel admins hold it implicitly; subusers get it in the Subusers screen. */
    public const MANAGE = 'gateway.manage';

    public static function register(): void
    {
        // Pelican reads the icon of a new group unconditionally, so it must be given.
        Subuser::registerCustomPermissions('gateway', ['manage'], 'dylaris-gateway::permissions', TablerIcon::Route);
    }
}
