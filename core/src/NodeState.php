<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel;

final readonly class NodeState
{
    /**
     * @param Mode[] $allowedModes
     * @param string $targetHost the node's Wings Docker interface IP, the private address a route targets
     *                           (Wings binds a 127.0.0.1 panel allocation to this interface)
     */
    public function __construct(
        public int $nodeId,
        public ?string $linkId,
        public ?string $region,
        public array $allowedModes,
        public string $targetHost = '172.18.0.1',
        /** @var AllowedDomain[]|null the domains this node offers; null = never saved, all of the provider's hoster domains */
        public ?array $domains = null,
    ) {
    }
}
