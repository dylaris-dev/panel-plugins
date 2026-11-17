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

    public function mergeDomains(array $domains): void
    {
        $byName = [];
        foreach ($this->domains as $i => $e) {
            $byName[$e->domain] = $i;
        }
        foreach ($domains as $e) {
            if (isset($byName[$e->domain])) {
                $this->domains[$byName[$e->domain]] = $e->with(enabled: $this->domains[$byName[$e->domain]]->enabled);
            } else {
                $this->domains[] = $e;
                $byName[$e->domain] = count($this->domains) - 1;
            }
        }
        usort($this->domains, fn ($a, $b) => [$a->own(), $a->domain] <=> [$b->own(), $b->domain]);
    }

    public function setDomainEnabled(string $domain, bool $enabled): void
    {
        foreach ($this->domains as $i => $e) {
            if ($e->domain === $domain) {
                $this->domains[$i] = $e->with(enabled: $enabled);
            }
        }
    }

    public function deleteDomain(string $domain): void
    {
        $this->domains = array_values(array_filter($this->domains, fn ($e) => $e->domain !== $domain));
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
