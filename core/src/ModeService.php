<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel;

use Dylaris\GatewayPanel\Provider\HosterDomain;
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
    ) {
    }

    public function apply(int $serverId, Mode $to, ?string $label = null, bool $actorIsAdmin = false): ApplyResult
    {
        $server = $this->panel->server($serverId) ?? throw new ModeRefused('This server no longer exists.');
        $state = $this->store->get($serverId);
        $from = $state?->mode ?? Mode::Direct;
        // Checked before the node rules: a server keeps a mode the node no longer offers.
        if ($from === $to) {
            return new ApplyResult(false, '');
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
            $this->ensureAddress($next, $label, $server);
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

    /** The address name to offer before the first switch: from the server's name, else a free random one. */
    public function suggestLabel(int $serverId): string
    {
        // A page view, not an action: it waits out the provider's Retry-After instead of spending the read budget.
        if (Reconciler::busyUntil($this->store->all()) > time()) {
            throw new ModeRefused(self::PROVIDER_BUSY);
        }
        $server = $this->panel->server($serverId) ?? throw new ModeRefused('This server no longer exists.');
        return $this->freeLabel($server, $this->hosterDomain()) ?? AddressLabel::random();
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

    /** The first address is chosen and checked once; later switches reuse it unchanged. */
    private function ensureAddress(ServerState $s, ?string $label, PanelServer $server): void
    {
        if ($s->label !== '' && $s->hosterDomain !== '') {
            // While the server was direct, someone else may have taken the remembered address;
            // re-posting it would retarget their route to this server.
            foreach ($this->provider->listRoutes() as $route) {
                if ($route->domain === $s->domain()) {
                    throw new ModeRefused('That address is already in use on the gateway.');
                }
            }
            return;
        }
        $hoster = $this->hosterDomain();
        $s->hosterDomain = $hoster->domain;
        if ($label === null) {
            $s->label = $this->freeLabel($server, $hoster)
                ?? throw new ModeRefused('No free address name was found. Enter one yourself.');
            return;
        }
        $s->label = $label;
        $check = $this->provider->checkDomain($s->domain());
        if (!$check->available) {
            throw new ModeRefused(AddressLabel::refusal($check->reason, $hoster->validation));
        }
    }

    private function hosterDomain(): HosterDomain
    {
        return $this->provider->regions()->hosterDomains[0]
            ?? throw new ModeRefused('The gateway has no address domain to offer yet.');
    }

    /** The first candidate label the provider accepts, or null. */
    private function freeLabel(PanelServer $server, HosterDomain $hoster): ?string
    {
        foreach (AddressLabel::candidates($server->name, $hoster->validation) as $label) {
            if ($this->provider->checkDomain("{$label}.{$hoster->domain}")->available) {
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
        $this->panel->assign($server->id, $l->id);
        $undo[] = fn () => $this->panel->unassign($server->id, $l->id);
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
        $this->panel->setPrimary($server->id, $loopback->id);
        $undo[] = fn () => $this->panel->setPrimary($server->id, $public->id);
        $this->panel->unassign($server->id, $public->id);
        $undo[] = fn () => $this->panel->assign($server->id, $public->id);
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
        $this->panel->assign($server->id, $public->id);
        $undo[] = fn () => $this->panel->unassign($server->id, $public->id);
        $this->panel->setPrimary($server->id, $public->id);
        $undo[] = fn () => $this->panel->setPrimary($server->id, $loopback->id);

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
            $this->panel->unassign($server->id, $old->id);
            $undo[] = fn () => $this->panel->assign($server->id, $old->id);
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
