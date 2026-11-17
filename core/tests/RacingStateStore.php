<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel\Tests;

use Dylaris\GatewayPanel\DomainSync;
use Dylaris\GatewayPanel\NodeState;
use Dylaris\GatewayPanel\ServerState;
use Dylaris\GatewayPanel\StateStore;

/**
 * Runs $race once, right after the first domains() read has been taken: what an admin's click does while a sync is
 * between its read and its write.
 */
final class RacingStateStore implements StateStore
{
    public bool $fired = false;

    /** @var callable */
    private $race;

    public function __construct(private readonly StateStore $inner, callable $race)
    {
        $this->race = $race;
    }

    public function domains(): array
    {
        $snapshot = $this->inner->domains();
        if (!$this->fired) {
            $this->fired = true;
            ($this->race)();
        }
        return $snapshot;
    }

    public function get(int $serverId): ?ServerState
    {
        return $this->inner->get($serverId);
    }

    public function put(ServerState $s): void
    {
        $this->inner->put($s);
    }

    public function delete(int $serverId): void
    {
        $this->inner->delete($serverId);
    }

    public function all(): array
    {
        return $this->inner->all();
    }

    public function node(int $nodeId): ?NodeState
    {
        return $this->inner->node($nodeId);
    }

    public function putNode(NodeState $n): void
    {
        $this->inner->putNode($n);
    }

    public function putDomains(array $domains): void
    {
        $this->inner->putDomains($domains);
    }

    public function mergeDomains(array $domains): void
    {
        $this->inner->mergeDomains($domains);
    }

    public function setDomainEnabled(string $domain, bool $enabled): void
    {
        $this->inner->setDomainEnabled($domain, $enabled);
    }

    public function deleteDomain(string $domain): void
    {
        $this->inner->deleteDomain($domain);
    }

    public function domainSync(): DomainSync
    {
        return $this->inner->domainSync();
    }

    public function putDomainSync(DomainSync $s): void
    {
        $this->inner->putDomainSync($s);
    }
}
