<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel;

interface PanelAdapter
{
    public function server(int $id): ?PanelServer;

    /** @return int[] */
    public function allServerIds(): array;

    /** The 127.0.0.1:<port> allocation on the node, assigned or not, or null. Never creates one. */
    public function findLoopback(int $nodeId, int $port): ?Allocation;

    /** Existing or new unassigned 127.0.0.1:<port> allocation on the node; throws AllocationTaken if another server holds it. */
    public function findOrCreateLoopback(int $nodeId, int $port): Allocation;

    public function assign(int $serverId, int $allocationId): void;

    public function unassign(int $serverId, int $allocationId): void;

    public function setPrimary(int $serverId, int $allocationId): void;

    /**
     * Unassigned allocation outside 127.0.0.0/8 and ::1, best first: the preferred IP and port, the preferred IP on its
     * lowest free port, the preferred port on any IP, the lowest free port; else null.
     */
    public function freePublic(int $nodeId, ?int $preferredPort, ?string $preferredIp = null): ?Allocation;

    public function deleteIfUnassigned(int $allocationId): void;
}
