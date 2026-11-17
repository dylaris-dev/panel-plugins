<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel\Tests;

use Dylaris\GatewayPanel\NodeState;
use Dylaris\GatewayPanel\ServerState;
use Dylaris\GatewayPanel\StateStore;
use Dylaris\GatewayPanel\Testing\InMemoryStateStore;

/** Hands out copies, like a database-backed store: a change counts only once it is put(). */
final class CopyingStateStore implements StateStore
{
    private InMemoryStateStore $inner;

    public function __construct()
    {
        $this->inner = new InMemoryStateStore();
    }

    public function get(int $serverId): ?ServerState
    {
        $s = $this->inner->get($serverId);
        return $s !== null ? clone $s : null;
    }

    public function put(ServerState $s): void
    {
        $this->inner->put(clone $s);
    }

    public function delete(int $serverId): void
    {
        $this->inner->delete($serverId);
    }

    public function all(): array
    {
        return array_map(fn (ServerState $s) => clone $s, $this->inner->all());
    }

    public function node(int $nodeId): ?NodeState
    {
        return $this->inner->node($nodeId);
    }

    public function putNode(NodeState $n): void
    {
        $this->inner->putNode($n);
    }
}
