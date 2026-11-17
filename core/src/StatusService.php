<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel;

use Dylaris\GatewayPanel\Provider\ProviderClient;
use Dylaris\GatewayPanel\Provider\ProviderException;

/** What the server page shows about the gateway. A direct server has nothing to ask the provider. */
final class StatusService
{
    /** $panel gives the server's current node; without it the stored node is used (it is stale after a transfer). */
    public function __construct(
        private readonly ProviderClient $provider,
        private readonly StateStore $store,
        private readonly ?PanelAdapter $panel = null,
    ) {
    }

    /** The status line both panels show; a node without a link says so instead of route and link state. */
    public static function line(array $st): string
    {
        if (!($st['nodeConnected'] ?? true)) {
            return 'This node is not connected to the gateway.';
        }
        return sprintf(
            '%s, %s, %d Java and %d Bedrock players.',
            $st['route'] ? 'Route active' : 'Route missing at the provider',
            $st['linkOnline'] ? 'link online' : 'link offline',
            $st['java'],
            $st['bedrock'],
        );
    }

    /** @return array{route:bool, linkOnline:bool, nodeConnected:bool, java:int, bedrock:int, restartNeeded:bool, lastError:?string} */
    public function forServer(int $serverId): array
    {
        $s = $this->store->get($serverId);
        $status = [
            'route' => false,
            'linkOnline' => false,
            'nodeConnected' => false,
            'java' => 0,
            'bedrock' => 0,
            'restartNeeded' => $s?->restartNeeded ?? false,
            'lastError' => $s?->lastError,
        ];
        if ($s === null || $s->mode === Mode::Direct) {
            return $status;
        }
        // A page view waits out the provider's Retry-After instead of spending the read budget.
        $busyUntil = Reconciler::busyUntil($this->store->all());
        if ($busyUntil > time()) {
            throw new ProviderException(429, 'rate_limited', ModeService::PROVIDER_BUSY, $busyUntil - time());
        }

        $nodeId = $this->panel?->server($serverId)?->nodeId ?? $s->nodeId;
        $linkId = $this->store->node($nodeId)?->linkId;
        if (($linkId ?? '') === '') {
            return $status;
        }
        $status['nodeConnected'] = true;
        foreach ($this->provider->listLinkKits() as $kit) {
            if ($kit->linkId === $linkId) {
                $status['linkOnline'] = $kit->online;
            }
        }
        foreach ($this->provider->listRoutes() as $route) {
            // A route still pointing at another node's link is not this server's route any more.
            if ($route->domain === $s->domain() && $route->linkId === $linkId) {
                $status['route'] = true;
            }
        }
        $players = $this->provider->players()[$s->domain()] ?? null;
        if ($players !== null) {
            $status['java'] = $players['java'];
            $status['bedrock'] = $players['bedrock'];
        }
        return $status;
    }
}
