<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel;

enum Mode: string
{
    case Direct = 'direct';
    case Both = 'both';
    case GatewayOnly = 'gateway_only';
}
