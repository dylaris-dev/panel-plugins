<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel;

final readonly class ApplyResult
{
    public function __construct(public bool $restartNeeded, public string $notice)
    {
    }
}
