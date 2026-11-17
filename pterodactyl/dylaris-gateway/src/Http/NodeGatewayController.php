<?php

namespace Dylaris\GatewayPtero\Http;

use Dylaris\GatewayPanel\Mode;
use Dylaris\GatewayPanel\NodeState;
use Dylaris\GatewayPanel\Provider\ProviderClient;
use Dylaris\GatewayPanel\Provider\ProviderException;
use Dylaris\GatewayPanel\StateStore;
use Dylaris\GatewayPtero\ErrorText;
use Dylaris\GatewayPtero\GatewaySettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;

/**
 * The Gateway tab on the admin node page: one link kit per node. Mounted under /api/admin/extensions/dylaris-gateway
 * (root admins only, admin throttle); the node always comes from the route.
 */
final class NodeGatewayController
{
    public const DEFAULT_TARGET = '172.18.0.1';

    /** Covers the mint call's 10 s connect and 20 s request timeouts with room to spare. */
    public const CONNECT_LOCK_SECONDS = 60;

    public const NO_REGION = 'No gateway region is online right now, so this node cannot be connected yet. Try again later.';

    public function show(Node $node): JsonResponse
    {
        return self::ok($this->state($node));
    }

    public function connect(Request $request, Node $node): JsonResponse
    {
        $data = $request->validate([
            'region' => ['required', 'string', 'max:64'],
            'target_host' => ['required', 'ip'],
            'allowed_modes' => self::modesRule(),
        ]);
        // A double-click or a second tab must not mint a second kit: one connect per node at a time, re-checked inside.
        $lock = Cache::lock('dylaris-gateway-connect-' . $node->id, self::CONNECT_LOCK_SECONDS);
        if (!$lock->get()) {
            return self::refuse('This node is already being connected.', 409);
        }
        try {
            if ($this->connected($node)) {
                return self::refuse('This node is already connected.', 409);
            }
            try {
                $kit = app(ProviderClient::class)->mintLinkKit(Str::limit('Pterodactyl: ' . $node->name, 64, ''), $data['region']);
            } catch (\Throwable $e) {
                return self::refuse(ErrorText::for($e, true));
            }
            $this->store()->putNode(new NodeState($node->id, $kit->linkId, $data['region'], self::modes($data['allowed_modes']), $data['target_host']));
        } finally {
            $lock->release();
        }

        // The key goes out in this one response only; it is not stored anywhere. The provider may not list the new kit
        // yet, which would read as "no longer exists" and send the admin to reconnect: it is offline until the link starts.
        return self::ok(
            ['snippet' => self::snippet($kit->linkKey, GatewaySettings::providerUrl(), $data['target_host']), 'kit_status' => 'Link offline'] + $this->state($node),
            'Node connected. Start the link with the snippet below; its key is not shown again.',
        );
    }

    public function saveModes(Request $request, Node $node): JsonResponse
    {
        $data = $request->validate(['allowed_modes' => self::modesRule()]);
        $n = $this->store()->node($node->id);
        if (($n?->linkId ?? '') === '') {
            return self::refuse('Connect the node first.');
        }
        $this->store()->putNode(new NodeState($n->nodeId, $n->linkId, $n->region, self::modes($data['allowed_modes']), $n->targetHost));

        return self::ok($this->state($node), 'Allowed modes saved.');
    }

    public function disconnect(Node $node): JsonResponse
    {
        $n = $this->store()->node($node->id);
        if (($n?->linkId ?? '') === '') {
            return self::refuse('This node is not connected.');
        }
        $servers = $this->gatewayServers($node);
        if ($servers !== []) {
            return self::refuse('Switch these servers to Direct first: ' . implode(', ', array_column($servers, 'name')) . '.');
        }
        try {
            app(ProviderClient::class)->revokeLinkKit((string) $n->linkId);
        } catch (ProviderException $e) {
            // Already gone at the provider: nothing left to revoke, the node is disconnected either way.
            if ($e->status !== 404) {
                return self::refuse($e->userMessage);
            }
        } catch (\Throwable $e) {
            return self::refuse(ErrorText::for($e, true));
        }
        $this->store()->putNode(new NodeState($n->nodeId, null, null, $n->allowedModes, $n->targetHost));

        return self::ok($this->state($node), 'Node disconnected and its link kit revoked.');
    }

    /** Values from the route-only compose file; strings JSON-quoted, which YAML reads as double-quoted. Same as Pelican. */
    public static function snippet(string $linkKey, string $coreUrl, string $target): string
    {
        $q = fn (string $v) => json_encode($v, JSON_UNESCAPED_SLASHES);

        return implode("\n", [
            'services:',
            '  link:',
            '    image: ghcr.io/dylaris-dev/gateway-link:latest',
            '    network_mode: host',
            '    restart: unless-stopped',
            '    stop_grace_period: 6h10m',
            '    mem_limit: 4g',
            '    environment:',
            '      LINK_KEY: ' . $q($linkKey),
            '      CORE_URL: ' . $q($coreUrl),
            '      LINK_DRAIN_TIMEOUT: "6h"',
            '      LINK_ALLOWED_TARGETS: ' . $q($target),
            '      LOCAL_HOST: ' . $q($target),
            '      # Host networking: keep the link\'s health endpoint off the network.',
            '      LINK_PORT: "127.0.0.1:25540"',
            '      GOMEMLIMIT: "3400MiB"',
        ]);
    }

    /** @return array<string, mixed> */
    private function state(Node $node): array
    {
        $n = $this->store()->node($node->id);
        $out = [
            'connected' => $this->connected($node),
            'link_id' => $n?->linkId,
            'region' => $n?->region,
            'target_host' => $n?->targetHost ?? self::DEFAULT_TARGET,
            'allowed_modes' => array_map(fn (Mode $m) => $m->value, $n?->allowedModes ?? Mode::cases()),
            'kit_status' => null,
            'regions' => [],
            'notice' => null,
            'problem' => null,
            'servers' => $this->gatewayServers($node),
        ];
        // The provider data comes after the panel data, so a provider failure leaves the rest of the tab usable.
        try {
            $client = app(ProviderClient::class);
            if ($out['connected']) {
                $out['kit_status'] = 'The link kit no longer exists at the provider. Disconnect and connect the node again.';
                foreach ($client->listLinkKits() as $kit) {
                    if ($kit->linkId === $n->linkId) {
                        $out['kit_status'] = $kit->online ? 'Link online' : 'Link offline';
                    }
                }
            } else {
                foreach ($client->regions()->items as $r) {
                    if ($r->online) {
                        $out['regions'][] = $r->region;
                    }
                }
                $out['notice'] = $out['regions'] === [] ? self::NO_REGION : null;
            }
        } catch (\Throwable $e) {
            $out['problem'] = ErrorText::for($e, true);
        }

        return $out;
    }

    /** @return list<array{name: string, mode: string, address: string, error: ?string}> */
    private function gatewayServers(Node $node): array
    {
        $states = [];
        foreach ($this->store()->all() as $s) {
            if ($s->mode !== Mode::Direct) {
                $states[$s->serverId] = $s;
            }
        }
        if ($states === []) {
            return [];
        }

        // By the panel's node, not the stored one: a server transferred here is listed here.
        return Server::query()->where('node_id', $node->id)->whereIn('id', array_keys($states))->orderBy('name')->get()
            ->map(fn (Server $srv) => [
                'name' => $srv->name,
                'mode' => ServerGatewayController::MODE_LABELS[$states[$srv->id]->mode->value],
                'address' => $states[$srv->id]->domain(),
                'error' => $states[$srv->id]->lastError,
            ])->values()->all();
    }

    private function connected(Node $node): bool
    {
        return ($this->store()->node($node->id)?->linkId ?? '') !== '';
    }

    private function store(): StateStore
    {
        return app(StateStore::class);
    }

    /** @return list<mixed> */
    private static function modesRule(): array
    {
        return ['required', 'array', 'min:1', 'max:3', Rule::in(array_map(fn (Mode $m) => $m->value, Mode::cases()))];
    }

    /**
     * @param list<string> $values
     *
     * @return Mode[]
     */
    private static function modes(array $values): array
    {
        return array_values(array_filter(Mode::cases(), fn (Mode $m) => in_array($m->value, $values, true)));
    }

    private static function ok(array $data, ?string $message = null): JsonResponse
    {
        return new JsonResponse(['data' => $data, 'message' => $message]);
    }

    private static function refuse(string $sentence, int $status = 422): JsonResponse
    {
        return new JsonResponse(['error' => $sentence], $status);
    }
}
