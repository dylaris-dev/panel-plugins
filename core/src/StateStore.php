<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel;

interface StateStore
{
    public function get(int $serverId): ?ServerState;

    public function put(ServerState $s): void;

    public function delete(int $serverId): void;

    /** @return ServerState[] */
    public function all(): array;

    public function node(int $nodeId): ?NodeState;

    public function putNode(NodeState $n): void;
}
