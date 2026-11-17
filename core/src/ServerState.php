<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel;

/** Mutable on purpose: mode transitions update it in place and put() it back. */
final class ServerState
{
    public function __construct(
        public int $serverId,
        public Mode $mode,
        public string $label,
        public string $hosterDomain,
        public int $nodeId,
        public int $port,
        public ?int $publicPort = null,
        public bool $proxyProtocol = false,
        public bool $bedrock = false,
        public bool $restartNeeded = false,
        public ?string $lastError = null,
        public ?int $retryAfterUntil = null,
        /** The IP of the public allocation released in Gateway only, taken back first with publicPort. */
        public ?string $publicIp = null,
    ) {
    }

    /** An address on an own domain has no label: hosterDomain then holds the whole name. */
    public function domain(): string
    {
        return $this->label === '' ? $this->hosterDomain : $this->label . '.' . $this->hosterDomain;
    }

    public function hasAddress(): bool
    {
        return $this->hosterDomain !== '';
    }
}
