<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel;

use Dylaris\GatewayPanel\Provider\ProviderClient;
use Dylaris\GatewayPanel\Provider\ProviderException;

/**
 * Switches one server between Direct, Both and Gateway only. Every step that changes something
 * registers its undo; a failure runs the undos in reverse and re-throws, so a failed switch leaves
 * allocations, route and state as they were.
 */
final class ModeService
{
    private const LOOPBACK = '127.0.0.1';

    /** Pterodactyl refuses an allocation on port 1024 or below, Pelican one below 1024; the stricter rule serves both. */
    public const PORT_FLOOR = 1024;

    /** A mode change while the reconcile, or another change, holds the gateway lock. */
    public const BUSY = 'The gateway is being updated, try again in a moment.';

    /** A page call while the provider's Retry-After from the last 429 still runs. */
    public const PROVIDER_BUSY = 'The gateway provider asked to wait a moment. Try again shortly.';

    /** A server whose egg is not on the admin's list: the edge routes the Minecraft Java handshake only. */
    public const MINECRAFT_ONLY = 'The gateway supports Minecraft servers only.';

    /** A server that is already behind the gateway when its egg leaves the list keeps its route. */
    public const NO_LONGER_ALLOWED = 'The gateway supports Minecraft servers only. This server keeps its gateway address until an admin switches it to Direct.';

    /** The address permission, but the node offers this user no domain (none of its region, or all admins only). */
    public const NO_DOMAINS = 'No domain is offered to you on this node, so the address cannot be chosen here. A panel admin switches domains on in the gateway domain list.';

    public const IN_USE = 'That address is already in use on the gateway.';

    public static function notOfferedAnyMore(string $address): string
    {
        return "The address {$address} is no longer offered on this node. Someone with the address permission has to choose a new one first.";
    }

    public const NOT_CONNECTED = "This node is not connected to the gateway yet. An admin connects it on the node's Gateway tab.";

    /** Shown before and after leaving Gateway only while Real player IP is on: the plugin writes no server files. */
    public const PROXY_OFF = "Real player IP is switched off with this change. Turn off proxy-protocol in the server's config before restarting (Paper: proxies.proxy-protocol in config/paper-global.yml, Velocity: haproxy-protocol in velocity.toml), or nobody can connect.";

    public static function portTooLow(int $port): string
    {
        return "Port {$port} cannot use the gateway: the panel allows no 127.0.0.1 allocation on port 1024 or below. Give the server a primary port above 1024 first.";
    }

    /** Narrowing a node's modes never moves a server: it keeps its mode, and the page says it is no longer offered. */
    public static function notOffered(string $modeLabel): string
    {
        return "{$modeLabel} is no longer offered on this node. The server keeps it until you switch to another mode.";
    }

    public function __construct(
        private readonly ProviderClient $provider,
        private readonly PanelAdapter $panel,
        private readonly StateStore $store,
        /** The panel's list, with its lock; null builds an unlocked one (tests). */
        private readonly ?DomainList $domains = null,
    ) {
    }

    /**
     * @param string|null $label  the name for a first address under a hoster domain; null or '' picks one
     * @param string|null $domain the domain for a first address, one of domainChoices(); null takes the first
     */
    public function apply(int $serverId, Mode $to, ?string $label = null, bool $actorIsAdmin = false, ?string $domain = null): ApplyResult
    {
        $server = $this->panel->server($serverId) ?? throw new ModeRefused('This server no longer exists.');
        $state = $this->store->get($serverId);
        $from = $state?->mode ?? Mode::Direct;
        // Checked before the node rules: a server keeps a mode the node no longer offers.
        if ($from === $to) {
            return new ApplyResult(false, '');
        }
        // An admin may still take a server whose egg left the list back to Direct; nobody may give it a route.
        if (!$server->gatewayAllowed && ($to !== Mode::Direct || !$actorIsAdmin)) {
            throw new ModeRefused($from === Mode::Direct ? self::MINECRAFT_ONLY : self::NO_LONGER_ALLOWED);
        }

        $node = $this->store->node($server->nodeId);
        if (!$actorIsAdmin && $node !== null && !in_array($to, $node->allowedModes, true)) {
            throw new ModeRefused('This mode is not allowed on this node.');
        }
        if ($to !== Mode::Direct && ($node?->linkId ?? '') === '') {
            throw new ModeRefused(self::NOT_CONNECTED);
        }

        // Work on a copy so a failure leaves the stored state untouched.
        $next = $state !== null ? clone $state : new ServerState($serverId, Mode::Direct, '', '', $server->nodeId, $server->primary->port);
        $next->nodeId = $server->nodeId;
        $next->lastError = null;
        if ($from === Mode::Direct) {
            $this->ensureAddress($next, $label, $domain, $server, $actorIsAdmin);
            $next->port = $server->primary->port;
            $next->publicPort = null;
            $next->proxyProtocol = false;
            $next->bedrock = false;
        }

        $undo = [];
        $newPublic = null;
        $proxyWasOn = $from === Mode::GatewayOnly && $next->proxyProtocol;
        try {
            if ($from === Mode::Direct) {
                $loopback = $this->loopback($server, $next->port, $undo);
                $this->createRoute($node, $next, $next->port, $undo, fn () => $this->deleteRoute($next->domain()));
                if ($to === Mode::GatewayOnly) {
                    $this->dropPublic($server, $loopback, $next, $undo);
                }
            } elseif ($from === Mode::Both && $to === Mode::GatewayOnly) {
                $this->dropPublic($server, $this->loopback($server, $next->port, $undo), $next, $undo);
            } elseif ($from === Mode::GatewayOnly) {
                [$rememberedIp, $rememberedPort] = [$next->publicIp, $next->publicPort];
                $public = $this->restorePublic($server, $next, $undo);
                if ($rememberedIp !== null && $public->ip !== $rememberedIp) {
                    $newPublic = " The direct address is now {$public->ip}:{$public->port}.";
                } elseif ($public->port !== $rememberedPort) {
                    $newPublic = " The direct port is now {$public->port}.";
                }
                if ($to === Mode::Both && $public->port !== $next->port) {
                    $this->followPrimaryPort($server, $next, $node, $public->port, $undo);
                }
            }
            if ($to === Mode::Direct) {
                $this->removeGateway($server, $next);
            }
        } catch (\Throwable $e) {
            $this->rollback($undo, $state ?? $next);
            throw $e instanceof AllocationTaken ? new ModeRefused($e->userMessage, $e) : $e;
        }

        $next->mode = $to;
        $next->restartNeeded = true;
        $this->store->put($next);

        $notice = 'Restart the server to apply the change.';
        $notice .= $newPublic ?? '';
        if ($proxyWasOn) {
            $notice .= ' ' . self::PROXY_OFF;
        }
        return new ApplyResult(true, $notice);
    }

    /**
     * What a switch to $to will change beyond the mode, as sentences for the confirm step: a direct port that
     * has to move because the old one is taken, and Real player IP going off. Reads only; apply() decides again.
     *
     * @return list<string>
     */
    public function preview(int $serverId, Mode $to): array
    {
        $state = $this->store->get($serverId);
        if ($state === null || $state->mode !== Mode::GatewayOnly || $to === Mode::GatewayOnly) {
            return [];
        }
        $server = $this->panel->server($serverId) ?? throw new ModeRefused('This server no longer exists.');
        $remembered = $state->publicPort ?? $state->port;
        $public = $this->panel->freePublic($server->nodeId, $remembered, $state->publicIp);
        $out = [];
        if ($public === null) {
            $out[] = 'No free public port on this node: the switch will be refused.';
        } elseif ($state->publicIp !== null && $public->ip !== $state->publicIp) {
            $out[] = "{$state->publicIp}:{$remembered} is taken, so the direct address will be {$public->ip}:{$public->port}.";
        } elseif ($public->port !== $remembered) {
            $out[] = "Port {$remembered} is taken, so the direct port will be {$public->port}.";
        }
        if ($state->proxyProtocol) {
            $out[] = self::PROXY_OFF;
        }
        return $out;
    }

    /**
     * The address name to offer before the first switch, under the first hoster domain the actor may pick: from the
     * server's name, else a free random one. '' when only own domains are offered (they take no name).
     */
    public function suggestLabel(int $serverId, bool $actorIsAdmin = false): string
    {
        // A page view, not an action: it waits out the provider's Retry-After instead of spending the read budget.
        if (Reconciler::busyUntil($this->store->all()) > time()) {
            throw new ModeRefused(self::PROVIDER_BUSY);
        }
        $server = $this->panel->server($serverId) ?? throw new ModeRefused('This server no longer exists.');
        foreach ($this->domainChoices($server->nodeId, $actorIsAdmin) as $d) {
            if (!$d->custom) {
                return $this->freeLabel($server, $d) ?? AddressLabel::random();
            }
        }
        return '';
    }

    /**
     * The domains the actor may pick for an address on this node: the panel-wide list, filtered to the node's region
     * (DomainList::offered()). Synced with the provider first when the list is empty or stale.
     *
     * @return AllowedDomain[]
     */
    public function domainChoices(int $nodeId, bool $actorIsAdmin): array
    {
        return ($this->domains ?? new DomainList($this->provider, $this->store))->choices($this->store->node($nodeId)?->region, $actorIsAdmin);
    }

    /**
     * Gives the server a new address. In Direct only the remembered address changes. Behind the gateway the new route is
     * made first, Real player IP and Bedrock are carried over, and the old route is deleted last: a failure before that
     * deletes the new route again, so the server never ends up without an address. The old address stops working.
     */
    public function changeAddress(int $serverId, ?string $label, string $domain, bool $actorIsAdmin = false): ApplyResult
    {
        $server = $this->panel->server($serverId) ?? throw new ModeRefused('This server no longer exists.');
        if (!$server->gatewayAllowed) {
            throw new ModeRefused(self::MINECRAFT_ONLY);
        }
        $state = $this->store->get($serverId);
        $next = $state !== null ? clone $state : new ServerState($serverId, Mode::Direct, '', '', $server->nodeId, $server->primary->port);
        $next->lastError = null;
        $entry = $this->choice($server->nodeId, $domain, $actorIsAdmin);
        $label = strtolower(trim((string) $label));
        if (!$entry->custom && $label === '') {
            throw new ModeRefused('Enter an address name.');
        }
        [$next->label, $next->hosterDomain] = [$entry->custom ? '' : $label, $entry->domain];
        $old = $state?->hasAddress() ? $state->domain() : null;
        if ($old === $next->domain()) {
            return new ApplyResult(false, 'The server already has this address.');
        }
        $this->checkNewAddress($next, $entry);

        if ($next->mode === Mode::Direct) {
            $this->store->put($next);
            return new ApplyResult(false, "The address {$next->domain()} is saved. It is used when the gateway is switched on.");
        }

        $node = $this->store->node($server->nodeId);
        if (($node?->linkId ?? '') === '') {
            throw new ModeRefused(self::NOT_CONNECTED);
        }
        $undo = [];
        try {
            $this->createRoute($node, $next, $next->port, $undo, fn () => $this->deleteRoute($next->domain()));
            if ($next->proxyProtocol) {
                $this->provider->setProxyProtocol($next->domain(), true);
            }
            if ($next->bedrock) {
                $this->provider->setEditions($next->domain(), true, true);
            }
        } catch (\Throwable $e) {
            // Until here the old address works untouched; the new one is removed again.
            $this->rollback($undo, $state);
            throw $e;
        }
        // The new address works from here on. A failed delete of the old one is reported, never undone: undoing after
        // a delete that got no answer could leave the server with no address at all.
        try {
            $this->deleteRoute((string) $old);
            $notice = "The gateway address is now {$next->domain()}. The old address {$old} no longer works: tell your players.";
        } catch (\Throwable $e) {
            $next->lastError = "The address changed to {$next->domain()}, but the old address {$old} could not be removed: "
                . ($e instanceof ProviderException ? $e->userMessage : $e->getMessage()) . ' It may still work; remove it in the DYLARIS dashboard.';
            $notice = "The gateway address is now {$next->domain()}.";
        }
        $this->store->put($next);

        if ($next->bedrock) {
            $notice .= ' Download the Floodgate key again and replace plugins/floodgate/key.pem: the new address has its own key.';
        }
        return new ApplyResult(false, $notice);
    }

    public function setRealIp(int $serverId, bool $on): void
    {
        $state = $this->store->get($serverId);
        // In Both it would break every direct connection: direct players send no PROXY header.
        if ($state?->mode !== Mode::GatewayOnly) {
            throw new ModeRefused('Real player IP is only available in Gateway only mode.');
        }
        $this->provider->setProxyProtocol($state->domain(), $on);
        $state->proxyProtocol = $on;
        $this->store->put($state);
    }

    public function setBedrock(int $serverId, bool $on): void
    {
        $state = $this->store->get($serverId);
        if ($state === null || $state->mode === Mode::Direct) {
            throw new ModeRefused('Bedrock needs a gateway address. Switch to Both or Gateway only first.');
        }
        $this->provider->setEditions($state->domain(), true, $on);
        $state->bedrock = $on;
        $this->store->put($state);
    }

    /**
     * The first address is chosen and checked once; later switches from Direct reuse it, but only while the node still
     * offers its domain to this actor (the admin may have removed it or made it admins only, or the server moved to a
     * node with other domains). A server that is behind the gateway keeps its address whatever the list says.
     */
    private function ensureAddress(ServerState $s, ?string $label, ?string $domain, PanelServer $server, bool $actorIsAdmin): void
    {
        if ($s->hasAddress()) {
            $offered = false;
            foreach ($this->domainChoices($server->nodeId, $actorIsAdmin) as $d) {
                $offered = $offered || ($d->domain === $s->hosterDomain && $d->custom === ($s->label === ''));
            }
            if (!$offered) {
                throw new ModeRefused(self::notOfferedAnyMore($s->domain()));
            }
            // While the server was direct, someone else may have taken the remembered address;
            // re-posting it would retarget their route to this server.
            if (isset($this->routedDomains()[$s->domain()])) {
                throw new ModeRefused(self::IN_USE);
            }
            return;
        }
        $entry = $domain === null || $domain === ''
            ? ($this->domainChoices($server->nodeId, $actorIsAdmin)[0] ?? throw new ModeRefused('The gateway has no address domain to offer yet.'))
            : $this->choice($server->nodeId, $domain, $actorIsAdmin);
        $s->hosterDomain = $entry->domain;
        $s->label = '';
        if (!$entry->custom && ($label ?? '') === '') {
            $s->label = $this->freeLabel($server, $entry)
                ?? throw new ModeRefused('No free address name was found. Enter one yourself.');
            return;
        }
        if (!$entry->custom) {
            $s->label = (string) $label;
        }
        $this->checkNewAddress($s, $entry);
    }

    /** One of the domains the actor may pick, by name. */
    private function choice(int $nodeId, string $domain, bool $actorIsAdmin): AllowedDomain
    {
        foreach ($this->domainChoices($nodeId, $actorIsAdmin) as $d) {
            if ($d->domain === strtolower(trim($domain))) {
                return $d;
            }
        }
        throw new ModeRefused('That domain is not offered for this server.');
    }

    /**
     * A new address must be free: check-domain answers for the provider, and the servers of this panel behind the gateway are asked here,
     * because check-domain calls a name this account already holds available.
     */
    private function checkNewAddress(ServerState $s, AllowedDomain $entry): void
    {
        if ($this->heldByOtherServer($s->domain(), $s->serverId)) {
            throw new ModeRefused('That address is already used by another server.');
        }
        // check-domain calls a name this account already holds available, and posting it would MOVE that route here:
        // another server's players would land on this one, and an undo would delete the route it took over.
        if (isset($this->routedDomains()[$s->domain()])) {
            throw new ModeRefused(self::IN_USE);
        }
        $check = $this->provider->checkDomain($s->domain());
        if (!$check->available) {
            throw new ModeRefused(AddressLabel::refusal($check->reason, $entry->validation));
        }
    }

    /** @return array<string, true> every address the account has a route under, from one list read */
    private function routedDomains(): array
    {
        $out = [];
        foreach ($this->provider->listRoutes() as $route) {
            $out[$route->domain] = true;
        }
        return $out;
    }

    private function heldByOtherServer(string $domain, int $serverId): bool
    {
        foreach ($this->store->all() as $other) {
            if ($other->serverId !== $serverId && $other->mode !== Mode::Direct && $other->domain() === $domain) {
                return true;
            }
        }
        return false;
    }

    /** The first candidate label the provider accepts and no other server of this panel holds, or null. */
    private function freeLabel(PanelServer $server, AllowedDomain $hoster): ?string
    {
        $routed = $this->routedDomains();
        foreach (AddressLabel::candidates($server->name, $hoster->validation) as $label) {
            $domain = "{$label}.{$hoster->domain}";
            if (!isset($routed[$domain]) && !$this->heldByOtherServer($domain, $server->id) && $this->provider->checkDomain($domain)->available) {
                return $label;
            }
        }
        return null;
    }

    /** The server's own 127.0.0.1:<port>, else one found or created on the node and assigned. */
    private function loopback(PanelServer $server, int $port, array &$undo): Allocation
    {
        $own = $this->ownLoopback($server, $port);
        if ($own !== null) {
            return $own;
        }
        if ($port <= self::PORT_FLOOR) {
            throw new ModeRefused(self::portTooLow($port));
        }
        $l = $this->panel->findOrCreateLoopback($server->nodeId, $port);
        $undo[] = fn () => $this->panel->deleteIfUnassigned($l->id);
        // Each undo is registered BEFORE its call and is a no-op when the call changed nothing: a panel service can
        // write the database and then fail on the Wings sync, and that write must be undone as well.
        $undo[] = fn () => $this->panel->unassign($server->id, $l->id);
        $this->panel->assign($server->id, $l->id);
        return $l;
    }

    private function ownLoopback(PanelServer $server, int $port): ?Allocation
    {
        foreach ($server->allocations as $a) {
            if ($a->ip === self::LOOPBACK && $a->port === $port) {
                return $a;
            }
        }
        return null;
    }

    /** The loopback becomes primary and the public allocation is released (its port remembered). */
    private function dropPublic(PanelServer $server, Allocation $loopback, ServerState $s, array &$undo): void
    {
        $public = $server->primary;
        $undo[] = fn () => $this->panel->setPrimary($server->id, $public->id);
        $this->panel->setPrimary($server->id, $loopback->id);
        $undo[] = fn () => $this->panel->assign($server->id, $public->id);
        $this->panel->unassign($server->id, $public->id);
        $s->publicPort = $public->port;
        $s->publicIp = $public->ip;
    }

    /** Takes a public allocation back as primary, preferring the remembered port. */
    private function restorePublic(PanelServer $server, ServerState $s, array &$undo): Allocation
    {
        $public = $this->panel->freePublic($server->nodeId, $s->publicPort ?? $s->port, $s->publicIp)
            ?? throw new ModeRefused('No free public port on this node.');
        if ($s->proxyProtocol) {
            // Direct players send no PROXY header, so Real-IP must be off once the public port is back.
            $this->provider->setProxyProtocol($s->domain(), false);
            $undo[] = fn () => $this->provider->setProxyProtocol($s->domain(), true);
            $s->proxyProtocol = false;
        }
        $loopback = $server->primary;
        $undo[] = fn () => $this->panel->unassign($server->id, $public->id);
        $this->panel->assign($server->id, $public->id);
        $undo[] = fn () => $this->panel->setPrimary($server->id, $loopback->id);
        $this->panel->setPrimary($server->id, $public->id);

        $s->publicPort = null;
        $s->publicIp = null;
        return $public;
    }

    /**
     * Wings runs the game on the primary allocation's port, and every other allocation maps its own
     * port to that same container port. A loopback on any other port reaches nothing, so the loopback
     * and the route move to the new primary port.
     */
    private function followPrimaryPort(PanelServer $server, ServerState $s, NodeState $node, int $port, array &$undo): void
    {
        $oldPort = $s->port;
        $old = $this->ownLoopback($server, $oldPort);
        $this->loopback($server, $port, $undo);
        $this->createRoute($node, $s, $port, $undo, fn () => $this->provider->createRoute((string) $node->linkId, $s->label, $s->hosterDomain, $node->targetHost, $oldPort));
        if ($old !== null) {
            $undo[] = fn () => $this->panel->assign($server->id, $old->id);
            $this->panel->unassign($server->id, $old->id);
            $this->panel->deleteIfUnassigned($old->id);
        }
        $s->port = $port;
    }

    /**
     * Route delete is the last provider call and the point of no return. A failed panel cleanup after
     * it is recorded, not rolled back; the switch to Direct stands and reconcile retries the cleanup.
     */
    private function removeGateway(PanelServer $server, ServerState $s): void
    {
        $this->deleteRoute($s->domain());
        $s->proxyProtocol = false;
        $s->bedrock = false;
        $loopback = $this->ownLoopback($server, $s->port);
        if ($loopback === null) {
            return;
        }
        try {
            $this->panel->unassign($server->id, $loopback->id);
            $this->panel->deleteIfUnassigned($loopback->id);
        } catch (\Throwable $e) {
            $s->lastError = 'The gateway address was removed, but the loopback allocation could not be released: ' . $e->getMessage();
        }
    }

    /**
     * A call that got no answer, or a 5xx, may have created the route all the same, so its undo is registered for those
     * too. A 4xx refusal created nothing, and the address may belong to someone else: nothing to undo.
     *
     * @param list<callable> $undo
     */
    private function createRoute(NodeState $node, ServerState $s, int $port, array &$undo, callable $undoStep): void
    {
        try {
            $this->provider->createRoute((string) $node->linkId, $s->label, $s->hosterDomain, $node->targetHost, $port);
        } catch (ProviderException $e) {
            if ($e->status === 0 || $e->status >= 500) {
                $undo[] = $undoStep;
            }
            throw $e;
        }
        $undo[] = $undoStep;
    }

    /** A route that is already gone is deleted. */
    private function deleteRoute(string $domain): void
    {
        try {
            $this->provider->deleteRoute($domain);
        } catch (ProviderException $e) {
            if ($e->status !== 404) {
                throw $e;
            }
        }
    }

    /** @param list<callable> $undo */
    private function rollback(array $undo, ServerState $keep): void
    {
        $failures = [];
        foreach (array_reverse($undo) as $step) {
            try {
                $step();
            } catch (\Throwable $e) {
                $failures[] = $e->getMessage();
            }
        }
        if ($failures !== []) {
            $keep->lastError = 'A failed change could not be fully undone: ' . implode('; ', $failures);
            $this->store->put($keep);
        }
    }
}
