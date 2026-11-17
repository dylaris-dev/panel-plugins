<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel;

final readonly class PanelServer
{
    /** @param Allocation[] $allocations */
    public function __construct(
        public int $id,
        public string $shortId,
        public int $nodeId,
        public Allocation $primary,
        public array $allocations,
        /** The server's display name; the default address label is derived from it. */
        public string $name = '',
    ) {
    }
}
