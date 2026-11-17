<?php

namespace Dylaris\GatewayPtero;

final class Permissions
{
    /**
     * Pterodactyl names an extension permission ext.<id>.<key>, so Pelican's gateway.manage is this here. Owners and root
     * admins pass it implicitly (ServerPolicy::before); subusers get it in the server's user editor.
     */
    public const MANAGE = 'ext.dylaris-gateway.manage';
}
