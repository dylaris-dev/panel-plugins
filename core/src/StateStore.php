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

    /** @return DomainEntry[] the panel-wide domain list, official domains first */
    public function domains(): array;

    /**
     * Replaces the whole list: domains not in $domains are removed. For seeding and tests; the list's own changes go
     * through the column-scoped methods below, so a sync never overwrites an admin's switch.
     *
     * @param DomainEntry[] $domains
     */
    public function putDomains(array $domains): void;

    /**
     * Inserts the domains not stored yet (with their enabled flag) and updates every other column of the stored
     * ones. Never writes enabled of a stored row: that belongs to setDomainEnabled() alone.
     *
     * @param DomainEntry[] $domains
     */
    public function mergeDomains(array $domains): void;

    /** Writes only the enabled column of one domain. */
    public function setDomainEnabled(string $domain, bool $enabled): void;

    public function deleteDomain(string $domain): void;

    public function domainSync(): DomainSync;

    public function putDomainSync(DomainSync $s): void;
}
