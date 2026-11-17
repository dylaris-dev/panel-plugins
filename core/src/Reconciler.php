<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel;

use Dylaris\GatewayPanel\Provider\ProviderClient;
use Dylaris\GatewayPanel\Provider\ProviderException;
use Dylaris\GatewayPanel\Provider\Route;

/**
 * Brings every server the plugin knows back in line with the panel and the provider. Same invariants
 * as ModeService: the route targets the node's targetHost on the server's primary port, and the
 * server holds 127.0.0.1:<primary port>. Provider routes without a ServerState are never touched.
 */
final class Reconciler
{
    private const LOOPBACK = '127.0.0.1';
    public const NOT_CONNECTED = "This node is not connected to the gateway. Connect it on the node's Gateway tab, or move the server to a connected node.";
    public const NOT_RECREATED = 'The gateway supports Minecraft servers only, so the missing route of this server is not created again. An admin can switch it to Direct.';
    private const DEFAULT_RETRY_AFTER = 60;

    private readonly \Closure $clock;

    /** @var array<int, ServerState> each state as this run read it, to tell a change made meanwhile from its own */
    private array $seen = [];

    /** @param callable(): int|null $clock unix time, injectable so tests do not sleep */
    public function __construct(
        private readonly ProviderClient $provider,
        private readonly PanelAdapter $panel,
        private readonly StateStore $store,
        ?callable $clock = null,
    ) {
        $this->clock = \Closure::fromCallable($clock ?? 'time');
    }

    public static function exposed(string $ip, int $port): string
    {
        return "Gateway only, but the server holds the public allocation {$ip}:{$port}, so players can still join it directly. Remove it in the panel (make the 127.0.0.1 allocation the primary first if it is the primary), or switch the mode to Both.";
    }

    /**
     * Unix time until which the provider asked to wait, or 0. Every state carries the same busy-until stamp from the
     * last 429, so the max is the window.
     *
     * @param ServerState[] $states
     */
    public static function busyUntil(array $states): int
    {
        return max([0, ...array_map(fn (ServerState $s) => $s->retryAfterUntil ?? 0, $states)]);
    }

    public function run(): ReconcileReport
    {
        $report = new ReconcileReport();
        $now = ($this->clock)();
        $states = $this->store->all();
        $busyUntil = self::busyUntil($states);
        if ($busyUntil > $now) {
            $report->stoppedForRetryAfter = $busyUntil - $now;
            return $report;
        }
        if ($states === []) {
            return $report;
        }

        try {
            $routes = [];
            foreach ($this->provider->listRoutes() as $route) {
                $routes[$route->domain] = $route;
            }
            $kits = [];
            foreach ($this->provider->listLinkKits() as $kit) {
                $kits[$kit->linkId] = true;
            }
            // A direct state keeps its address, and another server may have been given it since.
            $holders = [];
            // The links of the plugin's nodes: a route on any other link was made elsewhere (the dashboard).
            $ours = [];
            $this->seen = [];
            foreach ($states as $s) {
                if ($s->mode !== Mode::Direct && $s->hasAddress()) {
                    $holders[$s->domain()] = $s->serverId;
                }
                $ours[(string) $this->store->node($s->nodeId)?->linkId] = true;
                $this->seen[$s->serverId] = clone $s;
            }
            unset($ours['']);
            foreach ($states as $s) {
                try {
                    $this->reconcile($s, $routes, $holders, $ours, $kits, $report);
                } catch (\Throwable $e) {
                    if (self::rateLimited($e)) {
                        // Steps done before the 429 (a new loopback sets restartNeeded) must not be lost.
                        $this->persist($s);
                        throw $e;
                    }
                    $s->lastError = $e instanceof ProviderException || $e instanceof AllocationTaken || $e instanceof ModeRefused
                        ? $e->userMessage
                        : $e->getMessage();
                    $this->persist($s);
                    $report->errors[$s->serverId] = $s->lastError;
                }
            }
        } catch (ProviderException $e) {
            if (!self::rateLimited($e)) {
                throw $e;
            }
            $wait = $e->retryAfter ?? self::DEFAULT_RETRY_AFTER;
            foreach ($this->store->all() as $s) {
                $s->retryAfterUntil = $now + $wait;
                $this->store->put($s);
            }
            $report->stoppedForRetryAfter = $wait;
        }
        return $report;
    }

    /**
     * @param array<string, Route> $routes
     * @param array<string, int> $holders domain => id of the server whose gateway mode holds it
     * @param array<string, true> $ours link ids of the plugin's nodes
     * @param array<string, true> $kits
     */
    private function reconcile(ServerState $s, array $routes, array $holders, array $ours, array $kits, ReconcileReport $report): void
    {
        $server = $this->panel->server($s->serverId);
        $route = $s->hasAddress() ? ($routes[$s->domain()] ?? null) : null;
        if ($route !== null && ($holders[$route->domain] ?? $s->serverId) !== $s->serverId) {
            $route = null; // another server's route: never deleted or retargeted from here
        }
        // A gateway mode's route is the plugin's own (the address was free when it was made). A direct state only kept
        // the name, so a route under it on a link of no plugin node was made elsewhere, the dashboard say.
        if ($route !== null && $s->mode === Mode::Direct && !isset($ours[$route->linkId])) {
            $route = null;
        }
        if ($server === null) {
            $this->forget($s, $route, $report);
            $report->fixed[] = "Server {$s->serverId} was deleted in the panel; its gateway address was removed.";
        } elseif ($s->mode === Mode::Direct) {
            if ($this->clearLeftovers($server, $route, $report)) {
                $report->fixed[] = "Server {$s->serverId}: leftover gateway allocation or route removed.";
            }
        } elseif ($this->follow($server, $s, $route, $kits, $report)) {
            $report->fixed[] = "Server {$s->serverId}: gateway route and loopback updated to match the panel.";
        }
    }

    private function forget(ServerState $s, ?Route $route, ReconcileReport $report): void
    {
        if ($route !== null) {
            $this->provider->deleteRoute($route->domain);
            $report->deleted++;
        }
        if ($s->mode !== Mode::Direct) {
            $this->releaseLoopback($s->nodeId, $s->port);
        }
        $this->store->delete($s->serverId);
    }

    /** A partial switch to Direct can leave the loopback, and the plugin's own route, behind. */
    private function clearLeftovers(PanelServer $server, ?Route $route, ReconcileReport $report): bool
    {
        $changed = false;
        foreach ($server->allocations as $a) {
            if ($a->ip === self::LOOPBACK && $a->id !== $server->primary->id) {
                $this->panel->unassign($server->id, $a->id);
                $this->panel->deleteIfUnassigned($a->id);
                $changed = true;
            }
        }
        if ($route !== null) {
            $this->provider->deleteRoute($route->domain);
            $report->deleted++;
            $changed = true;
        }
        return $changed;
    }

    /**
     * Port or node changed, or the route is gone: take the loopback for the primary port, (re)target
     * the route, then release the old loopbacks. Each step is idempotent, so a failure halfway is
     * finished by the next run.
     *
     * @param array<string, true> $kits
     */
    private function follow(PanelServer $server, ServerState $s, ?Route $route, array $kits, ReconcileReport $report): bool
    {
        $port = $server->primary->port;
        $node = $this->store->node($server->nodeId);
        $moved = $s->nodeId !== $server->nodeId;
        $own = null;
        $stale = [];
        // Reported, never changed: the plugin makes no automatic mode change.
        $warning = null;
        foreach ($server->allocations as $a) {
            if ($s->mode === Mode::GatewayOnly && $warning === null && !Allocation::isLoopbackIp($a->ip)) {
                $warning = self::exposed($a->ip, $a->port);
            }
            if ($a->ip !== self::LOOPBACK) {
                continue;
            }
            if ($a->port === $port) {
                $own = $a;
            } elseif ($a->id !== $server->primary->id) {
                $stale[] = $a;
            }
        }
        if ($warning !== null) {
            $report->errors[$s->serverId] = $warning;
        }
        $routeOk = $route !== null && $node !== null && $route->linkId === $node->linkId
            && $route->targetIp === $node->targetHost && $route->targetPort === $port;
        // Real player IP is what the state says; an unanswered call can leave the provider on the other value.
        $proxyOk = $route === null || $route->proxyProtocol === $s->proxyProtocol;
        if ($own !== null && $routeOk && $proxyOk && $stale === [] && !$moved) {
            // An error from an earlier run (a node without a link, a failed call) no longer applies.
            if ($s->lastError !== $warning) {
                $s->lastError = $warning;
                $this->persist($s);
            }
            return false;
        }
        $linkId = $node?->linkId ?? '';
        if ($linkId === '' || !isset($kits[$linkId])) {
            $s->lastError = self::NOT_CONNECTED;
            $this->persist($s);
            $report->errors[$s->serverId] = self::NOT_CONNECTED;
            return false;
        }

        // A server whose egg left the list keeps the route it has, but never gets a new one.
        if ($route === null && !$server->gatewayAllowed) {
            $s->lastError = self::NOT_RECREATED;
            $this->persist($s);
            $report->errors[$s->serverId] = self::NOT_RECREATED;
            return false;
        }
        if ($own === null && $port <= ModeService::PORT_FLOOR) {
            throw new ModeRefused(ModeService::portTooLow($port));
        }
        if ($own === null) {
            $l = $this->panel->findOrCreateLoopback($server->nodeId, $port);
            $this->panel->assign($server->id, $l->id);
            $s->restartNeeded = true;
        }
        if (!$routeOk) {
            $this->provider->createRoute($linkId, $s->label, $s->hosterDomain, $node->targetHost, $port);
            $route === null ? $report->created++ : $report->retargeted++;
        }
        if (!$proxyOk) {
            $this->provider->setProxyProtocol($s->domain(), $s->proxyProtocol);
        }
        foreach ($stale as $a) {
            $this->panel->unassign($server->id, $a->id);
            $this->panel->deleteIfUnassigned($a->id);
        }
        if ($moved) {
            $this->releaseLoopback($s->nodeId, $s->port);
        }
        $s->nodeId = $server->nodeId;
        $s->port = $port;
        $s->lastError = $warning;
        $this->persist($s);
        return true;
    }

    /**
     * Writes what this run changed onto the row as it is now, never the copy read at the start: a mode change that
     * landed meanwhile wins, and the next run starts from it.
     */
    private function persist(ServerState $s): void
    {
        $seen = $this->seen[$s->serverId] ?? null;
        $fresh = $this->store->get($s->serverId);
        if ($seen === null || $fresh === null || $fresh->mode !== $seen->mode || $fresh->domain() !== $seen->domain()) {
            return;
        }
        $fresh->nodeId = $s->nodeId;
        $fresh->port = $s->port;
        $fresh->lastError = $s->lastError;
        $fresh->retryAfterUntil = $s->retryAfterUntil;
        if ($s->restartNeeded && !$seen->restartNeeded) {
            $fresh->restartNeeded = true;
        }
        $this->store->put($fresh);
    }

    /** Deletes a loopback the server no longer holds (deleted or transferred); one another server holds stays. */
    private function releaseLoopback(int $nodeId, int $port): void
    {
        $l = $this->panel->findLoopback($nodeId, $port);
        if ($l !== null && $l->serverId === null) {
            $this->panel->deleteIfUnassigned($l->id);
        }
    }

    private static function rateLimited(\Throwable $e): bool
    {
        return $e instanceof ProviderException && ($e->status === 429 || $e->errorCode === 'rate_limited');
    }
}
