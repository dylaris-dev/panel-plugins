<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel\Testing;

use Dylaris\GatewayPanel\DomainSync;
use Dylaris\GatewayPanel\NodeState;
use Dylaris\GatewayPanel\ServerState;
use Dylaris\GatewayPanel\StateStore;

final class InMemoryStateStore implements StateStore
{
    /** @var array<int, ServerState> */
    private array $servers = [];
    /** @var array<int, NodeState> */
    private array $nodes = [];
    /** @var \Dylaris\GatewayPanel\DomainEntry[] */
    private array $domains = [];
    private ?DomainSync $sync = null;

    public function get(int $serverId): ?ServerState
    {
        return $this->servers[$serverId] ?? null;
    }

    public function put(ServerState $s): void
    {
        $this->servers[$s->serverId] = $s;
    }

    public function delete(int $serverId): void
    {
        unset($this->servers[$serverId]);
    }

    public function all(): array
    {
        return array_values($this->servers);
    }

    public function node(int $nodeId): ?NodeState
    {
        return $this->nodes[$nodeId] ?? null;
    }

    public function putNode(NodeState $n): void
    {
        $this->nodes[$n->nodeId] = $n;
    }

    public function domains(): array
    {
        return $this->domains;
    }

    public function putDomains(array $domains): void
    {
        $this->domains = array_values($domains);
    }

    public function domainSync(): DomainSync
    {
        return $this->sync ?? new DomainSync();
    }

    public function putDomainSync(DomainSync $s): void
    {
        $this->sync = $s;
    }
}
