<?php

namespace Dylaris\GatewayPelican;

use App\Models\Allocation as PelicanAllocation;
use App\Models\Node;
use App\Models\Server;
use App\Services\Allocations\AssignmentService;
use App\Services\Servers\BuildModificationService;
use Dylaris\GatewayPanel\Allocation;
use Dylaris\GatewayPanel\AllocationTaken;
use Dylaris\GatewayPanel\PanelAdapter;
use Dylaris\GatewayPanel\PanelServer;
use Filament\Facades\Filament;

/**
 * Every allocation change goes through Pelican's services, which sync Wings; raw model writes would not.
 * Every method runs outside the Filament tenant: in the server panel (the gateway widget) Filament scopes
 * all Allocation queries to the current server, Pelican's own services included, which hides the node's
 * free allocations and makes a new allocation belong to that server on creation.
 */
final class PelicanPanelAdapter implements PanelAdapter
{
    private const LOOPBACK = '127.0.0.1';

    public function __construct(
        private readonly AssignmentService $assignments,
        private readonly BuildModificationService $builds,
    ) {}

    public function server(int $id): ?PanelServer
    {
        return self::untenanted(function () use ($id) {
            $server = Server::query()->with('allocations')->find($id);
            if ($server === null) {
                return null;
            }
            $allocations = $server->allocations->map(self::map(...))->all();
            $primary = array_values(array_filter($allocations, fn (Allocation $a) => $a->id === $server->allocation_id))[0]
                // Never null for a server that exists: the reconcile would read that as deleted and drop its route.
                ?? throw new \RuntimeException('This server has no primary allocation.');

            return new PanelServer($server->id, $server->uuid_short, $server->node_id, $primary, $allocations, (string) $server->name);
        });
    }

    public function allServerIds(): array
    {
        return self::untenanted(fn () => Server::query()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    public function findLoopback(int $nodeId, int $port): ?Allocation
    {
        return self::untenanted(function () use ($nodeId, $port) {
            $a = $this->loopback($nodeId, $port);

            return $a ? self::map($a) : null;
        });
    }

    public function findOrCreateLoopback(int $nodeId, int $port): Allocation
    {
        return self::untenanted(function () use ($nodeId, $port) {
            $a = $this->loopback($nodeId, $port);
            if ($a !== null) {
                if ($a->server_id !== null) {
                    throw new AllocationTaken($port);
                }

                return self::map($a);
            }
            $ids = $this->assignments->handle(Node::query()->findOrFail($nodeId), [
                'allocation_ip' => self::LOOPBACK,
                'allocation_ports' => [(string) $port],
            ]);

            return self::map(PelicanAllocation::query()->findOrFail($ids[0]));
        });
    }

    public function assign(int $serverId, int $allocationId): void
    {
        self::untenanted(function () use ($serverId, $allocationId) {
            $server = Server::query()->findOrFail($serverId);
            $a = PelicanAllocation::query()->findOrFail($allocationId);
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
            // BuildModificationService skips, without an error, an allocation another server took in the meantime.
            if ($a->refresh()->server_id !== $serverId) {
                throw new AllocationTaken($a->port);
            }
        });
    }

    public function unassign(int $serverId, int $allocationId): void
    {
        self::untenanted(function () use ($serverId, $allocationId) {
            $a = PelicanAllocation::query()->find($allocationId);
            if ($a === null || $a->server_id !== $serverId) {
                return;
            }
            $this->build(Server::query()->findOrFail($serverId), ['remove_allocations' => [$a->id]]);
        });
    }

    public function setPrimary(int $serverId, int $allocationId): void
    {
        self::untenanted(function () use ($serverId, $allocationId) {
            $a = PelicanAllocation::query()->findOrFail($allocationId);
            // Only an allocation the server already holds may become its primary, never a free or foreign one.
            if ($a->server_id !== $serverId) {
                throw $a->server_id === null
                    ? new \RuntimeException("Allocation {$a->id} is not assigned to server {$serverId}.")
                    : new AllocationTaken($a->port);
            }
            $this->build(Server::query()->findOrFail($serverId), ['allocation_id' => $allocationId]);
        });
    }

    public function freePublic(int $nodeId, ?int $preferredPort, ?string $preferredIp = null): ?Allocation
    {
        return self::untenanted(function () use ($nodeId, $preferredPort, $preferredIp) {
            $free = PelicanAllocation::query()
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
        });
    }

    public function deleteIfUnassigned(int $allocationId): void
    {
        self::untenanted(fn () => PelicanAllocation::query()->whereKey($allocationId)->whereNull('server_id')->first()?->delete());
    }

    /** @param array<string, mixed> $data */
    private function build(Server $server, array $data): void
    {
        // BuildModificationService writes these three on every call and defaults a missing one to 0.
        $this->builds->handle($server, $data + [
            'allocation_limit' => $server->allocation_limit,
            'database_limit' => $server->database_limit,
            'backup_limit' => $server->backup_limit,
        ]);
    }

    private function loopback(int $nodeId, int $port): ?PelicanAllocation
    {
        return PelicanAllocation::query()->where('node_id', $nodeId)->where('ip', self::LOOPBACK)->where('port', $port)->first();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private static function untenanted(callable $fn): mixed
    {
        $tenant = Filament::getTenant();
        if ($tenant === null) {
            return $fn();
        }
        Filament::setTenant(null, isQuiet: true);
        try {
            return $fn();
        } finally {
            Filament::setTenant($tenant, isQuiet: true);
        }
    }

    private static function map(PelicanAllocation $a): Allocation
    {
        return new Allocation($a->id, $a->node_id, $a->ip, $a->port, $a->server_id);
    }
}
