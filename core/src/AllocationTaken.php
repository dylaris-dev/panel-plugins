<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel;

final class AllocationTaken extends \RuntimeException
{
    public readonly string $userMessage;

    public function __construct(public readonly int $port)
    {
        $this->userMessage = "Port {$port} on this node is already used by another server for the gateway.";
        parent::__construct($this->userMessage);
    }
}
