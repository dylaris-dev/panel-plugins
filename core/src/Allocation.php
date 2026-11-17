<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel;

final readonly class Allocation
{
    public function __construct(
        public int $id,
        public int $nodeId,
        public string $ip,
        public int $port,
        public ?int $serverId,
    ) {
    }

    /** 127.0.0.0/8 and ::1: no player reaches these, so they are never a public address. */
    public static function isLoopbackIp(string $ip): bool
    {
        return str_starts_with($ip, '127.') || in_array(strtolower(trim($ip, '[]')), ['::1', '0:0:0:0:0:0:0:1'], true);
    }
}
