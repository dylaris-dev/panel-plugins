<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel\Testing;

use Dylaris\GatewayPanel\Allocation;
use Dylaris\GatewayPanel\AllocationTaken;
use Dylaris\GatewayPanel\PanelAdapter;
use Dylaris\GatewayPanel\PanelServer;

final class FakePanel implements PanelAdapter
{
    /** @var list<array{0: string, 1: array}> every adapter call as [method, args] */
    public array $calls = [];

    /** Runs once per server() call before it answers: lets a test change the world in the middle of a run. */
    public ?\Closure $onServer = null;

    /** @var array<int, Allocation> */
    private array $allocations = [];
    /** @var array<int, array{shortId: string, nodeId: int, primary: int, name: string}> */
    private array $servers = [];
    /** @var array<string, true> */
    private array $failNext = [];
    private int $nextAllocationId = 1;

    public function failNext(string $method): void
    {
        $this->failNext[$method] = true;
    }

    /** Test seed: a new unassigned allocation. */
    public function addAllocation(int $nodeId, string $ip, int $port): Allocation
    {
        $a = new Allocation($this->nextAllocationId++, $nodeId, $ip, $port, null);
        return $this->allocations[$a->id] = $a;
    }

    /** Test seed: a server whose primary allocation is $primary (assigned to it here). */
    public function addServer(int $id, int $nodeId, Allocation $primary, string $name = ''): void
    {
        $shortId = substr(md5((string) $id), 0, 8);
        // Without a name the short id stands in, so the default label is the short id under alphanumeric.
        $this->servers[$id] = ['shortId' => $shortId, 'nodeId' => $nodeId, 'primary' => $primary->id, 'name' => $name ?: $shortId];
        $this->setOwner($primary->id, $id);
    }

    /** Test seed: whether the server's egg may use the gateway. */
    public function setGatewayAllowed(int $id, bool $allowed): void
    {
        $this->servers[$id]['allowed'] = $allowed;
    }

    /** Test seed: the panel deletes the server; like a real panel, its allocations stay, unassigned. */
    public function removeServer(int $id): void
    {
        unset($this->servers[$id]);
        foreach ($this->allocations as $a) {
            if ($a->serverId === $id) {
                $this->setOwner($a->id, null);
            }
        }
    }

    public function allocation(int $id): ?Allocation
    {
        return $this->allocations[$id] ?? null;
    }

    public function server(int $id): ?PanelServer
    {
        $this->enter(__FUNCTION__, func_get_args());
        if ($this->onServer !== null) {
            ($this->onServer)($id);
        }
        $s = $this->servers[$id] ?? null;
        if ($s === null) {
            return null;
        }
        $owned = array_values(array_filter($this->allocations, fn (Allocation $a) => $a->serverId === $id));
        return new PanelServer($id, $s['shortId'], $s['nodeId'], $this->allocations[$s['primary']], $owned, $s['name'], $s['allowed'] ?? true);
    }

    public function allServerIds(): array
    {
        $this->enter(__FUNCTION__, func_get_args());
        return array_keys($this->servers);
    }

    public function findLoopback(int $nodeId, int $port): ?Allocation
    {
        $this->enter(__FUNCTION__, func_get_args());
        foreach ($this->allocations as $a) {
            if ($a->nodeId === $nodeId && $a->ip === '127.0.0.1' && $a->port === $port) {
                return $a;
            }
        }
        return null;
    }

    public function findOrCreateLoopback(int $nodeId, int $port): Allocation
    {
        $this->enter(__FUNCTION__, func_get_args());
        foreach ($this->allocations as $a) {
            if ($a->nodeId === $nodeId && $a->ip === '127.0.0.1' && $a->port === $port) {
                // The call carries no calling server, so any owner counts as "another server".
                if ($a->serverId !== null) {
                    throw new AllocationTaken($port);
                }
                return $a;
            }
        }
        return $this->addAllocation($nodeId, '127.0.0.1', $port);
    }

    public function assign(int $serverId, int $allocationId): void
    {
        $this->enter(__FUNCTION__, func_get_args());
        $a = $this->allocations[$allocationId];
        // A real panel refuses an allocation that belongs to another server.
        if ($a->serverId !== null && $a->serverId !== $serverId) {
            throw new AllocationTaken($a->port);
        }
        $this->setOwner($allocationId, $serverId);
    }

    public function unassign(int $serverId, int $allocationId): void
    {
        $this->enter(__FUNCTION__, func_get_args());
        if (($this->allocations[$allocationId]->serverId ?? null) === $serverId) {
            $this->setOwner($allocationId, null);
        }
    }

    public function setPrimary(int $serverId, int $allocationId): void
    {
        $this->enter(__FUNCTION__, func_get_args());
        $this->servers[$serverId]['primary'] = $allocationId;
    }

    public function freePublic(int $nodeId, ?int $preferredPort, ?string $preferredIp = null): ?Allocation
    {
        $this->enter(__FUNCTION__, func_get_args());
        $free = array_filter(
            $this->allocations,
            fn (Allocation $a) => $a->nodeId === $nodeId && $a->serverId === null && !Allocation::isLoopbackIp($a->ip),
        );
        usort($free, fn (Allocation $x, Allocation $y) => [$x->port, $x->id] <=> [$y->port, $y->id]);
        $tests = [
            fn (Allocation $a) => $a->ip === $preferredIp && $a->port === $preferredPort,
            fn (Allocation $a) => $a->ip === $preferredIp,
            fn (Allocation $a) => $a->port === $preferredPort,
            fn (Allocation $a) => true,
        ];
        foreach ($tests as $test) {
            foreach ($free as $a) {
                if ($test($a)) {
                    return $a;
                }
            }
        }
        return null;
    }

    public function deleteIfUnassigned(int $allocationId): void
    {
        $this->enter(__FUNCTION__, func_get_args());
        $a = $this->allocations[$allocationId] ?? null;
        if ($a !== null && $a->serverId === null) {
            unset($this->allocations[$allocationId]);
        }
    }

    private function setOwner(int $allocationId, ?int $serverId): void
    {
        $a = $this->allocations[$allocationId];
        $this->allocations[$allocationId] = new Allocation($a->id, $a->nodeId, $a->ip, $a->port, $serverId);
    }

    private function enter(string $method, array $args): void
    {
        $this->calls[] = [$method, $args];
        if (isset($this->failNext[$method])) {
            unset($this->failNext[$method]);
            throw new \RuntimeException("injected failure in {$method}");
        }
    }
}
