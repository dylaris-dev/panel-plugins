<?php

namespace Dylaris\GatewayPtero;

use Dylaris\GatewayPanel\Allocation;
use Dylaris\GatewayPanel\AllocationTaken;
use Dylaris\GatewayPanel\PanelAdapter;
use Dylaris\GatewayPanel\PanelServer;
use Pterodactyl\Contracts\Allocations\CreatesAllocations;
use Pterodactyl\Contracts\Allocations\DeletesAllocations;
use Pterodactyl\Contracts\Servers\UpdatesServerBuild;
use Pterodactyl\Models\Allocation as PteroAllocation;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;

/**
 * Every assignment change goes through UpdatesServerBuild, the one allocation contract that syncs Wings. The client
 * contracts (AssignsAllocations, SetsPrimaryAllocations, UnassignsAllocations) only write the database, pick a random
 * allocation and refuse a server without an allocation limit, so they are not used.
 */
final class PteroPanelAdapter implements PanelAdapter
{
    private const LOOPBACK = '127.0.0.1';

    public function __construct(
        private readonly CreatesAllocations $creates,
        private readonly UpdatesServerBuild $builds,
        private readonly DeletesAllocations $deletes,
    ) {}

    public function server(int $id): ?PanelServer
    {
        $server = Server::query()->with(['allocations', 'egg.tags', 'egg.configFrom'])->find($id);
        if ($server === null) {
            return null;
        }
        $allocations = $server->allocations->map(self::map(...))->all();
        $primary = array_values(array_filter($allocations, fn (Allocation $a) => $a->id === $server->allocation_id))[0]
            // Never null for a server that exists: the reconcile would read that as deleted and drop its route.
            ?? throw new \RuntimeException('This server has no primary allocation.');

        return new PanelServer($server->id, $server->uuidShort, $server->node_id, $primary, $allocations, (string) $server->name, GatewaySettings::eggAllowed($server->egg));
    }

    public function allServerIds(): array
    {
        return Server::query()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function findLoopback(int $nodeId, int $port): ?Allocation
    {
        $a = $this->loopback($nodeId, $port);

        return $a ? self::map($a) : null;
    }

    public function findOrCreateLoopback(int $nodeId, int $port): Allocation
    {
        $a = $this->loopback($nodeId, $port);
        if ($a === null) {
            // Returns nothing; it inserts or ignores, so a concurrent create of the same allocation is fine.
            $this->creates->create(Node::query()->findOrFail($nodeId), [
                'allocation_ip' => self::LOOPBACK,
                'allocation_ports' => [(string) $port],
            ]);
            $a = $this->loopback($nodeId, $port) ?? throw new \RuntimeException("Allocation 127.0.0.1:{$port} was not created.");
        }
        if ($a->server_id !== null) {
            throw new AllocationTaken($port);
        }

        return self::map($a);
    }

    public function assign(int $serverId, int $allocationId): void
    {
        $server = Server::query()->findOrFail($serverId);
        $a = PteroAllocation::query()->findOrFail($allocationId);
        if ($a->server_id === $serverId) {
            return;
        }
        if ($a->server_id !== null) {
            throw new AllocationTaken($a->port);
        }
        if ($a->node_id !== $server->node_id) {
            throw new \RuntimeException("Allocation {$a->id} is on another node than server {$serverId}.");
        }
        $this->build($server, ['add_allocations' => [$a->id]]);
        // UpdatesServerBuild skips, without an error, an allocation another server took in the meantime.
        if ($a->refresh()->server_id !== $serverId) {
            throw new AllocationTaken($a->port);
        }
    }

    public function unassign(int $serverId, int $allocationId): void
    {
        $a = PteroAllocation::query()->find($allocationId);
        if ($a === null || $a->server_id !== $serverId) {
            return;
        }
        $this->build(Server::query()->findOrFail($serverId), ['remove_allocations' => [$a->id]]);
    }

    public function setPrimary(int $serverId, int $allocationId): void
    {
        $a = PteroAllocation::query()->findOrFail($allocationId);
        // Only an allocation the server already holds may become its primary, never a free or foreign one.
        if ($a->server_id !== $serverId) {
            throw $a->server_id === null
                ? new \RuntimeException("Allocation {$a->id} is not assigned to server {$serverId}.")
                : new AllocationTaken($a->port);
        }
        $this->build(Server::query()->findOrFail($serverId), ['allocation_id' => $allocationId]);
    }

    public function freePublic(int $nodeId, ?int $preferredPort, ?string $preferredIp = null): ?Allocation
    {
        $free = PteroAllocation::query()
            ->where('node_id', $nodeId)
            ->whereNull('server_id')
            // Every loopback, not only the plugin's 127.0.0.1: no player reaches 127.0.0.0/8 or ::1.
            ->where('ip', 'not like', '127.%')
            ->whereNotIn('ip', ['::1', '[::1]'])
            ->orderBy('port')
            ->orderBy('id');
        $q = fn () => clone $free;
        // The customer's own address first: on a multi-IP node the same port can be free on another, even private, IP.
        $a = ($preferredIp !== null && $preferredPort !== null ? $q()->where('ip', $preferredIp)->where('port', $preferredPort)->first() : null)
            ?? ($preferredIp !== null ? $q()->where('ip', $preferredIp)->first() : null)
            ?? ($preferredPort !== null ? $q()->where('port', $preferredPort)->first() : null)
            ?? $free->first();

        return $a ? self::map($a) : null;
    }

    public function deleteIfUnassigned(int $allocationId): void
    {
        $a = PteroAllocation::query()->whereKey($allocationId)->whereNull('server_id')->first();
        if ($a !== null) {
            $this->deletes->delete($a);
        }
    }

    /** @param array<string, mixed> $data */
    private function build(Server $server, array $data): void
    {
        // UpdatesServerBuild clears a database or allocation limit left out and zeroes a backup limit left out.
        $this->builds->update($server, $data + [
            'allocation_limit' => $server->allocation_limit,
            'database_limit' => $server->database_limit,
            'backup_limit' => $server->backup_limit,
        ]);
    }

    private function loopback(int $nodeId, int $port): ?PteroAllocation
    {
        return PteroAllocation::query()->where('node_id', $nodeId)->where('ip', self::LOOPBACK)->where('port', $port)->first();
    }

    private static function map(PteroAllocation $a): Allocation
    {
        return new Allocation($a->id, $a->node_id, $a->ip, $a->port, $a->server_id);
    }
}
