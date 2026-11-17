<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel\Testing;

use Dylaris\GatewayPanel\NodeState;
use Dylaris\GatewayPanel\ServerState;
use Dylaris\GatewayPanel\StateStore;

final class InMemoryStateStore implements StateStore
{
    /** @var array<int, ServerState> */
    private array $servers = [];
    /** @var array<int, NodeState> */
    private array $nodes = [];

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
}
