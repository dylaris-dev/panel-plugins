<?php

namespace Dylaris\GatewayPtero\Http;

use Dylaris\GatewayPanel\AllowedDomain;
use Dylaris\GatewayPanel\Mode;
use Dylaris\GatewayPanel\NodeState;
use Dylaris\GatewayPanel\Provider\ProviderClient;
use Dylaris\GatewayPanel\Provider\ProviderException;
use Dylaris\GatewayPanel\StateStore;
use Dylaris\GatewayPtero\ErrorText;
use Dylaris\GatewayPtero\GatewaySettings;
use Dylaris\GatewayPtero\ReconcileRunner;
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

    public const DOMAINS_HELP = "The domains users pick from for a server's address on this node. A hoster domain takes a name in front of it (survival.eu.example.com). An own domain is the whole address: the provider verifies each exact name by CNAME and TXT, so a name in front of it would be a different, unverified one. Only verified own domains are listed.";

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
            $this->store()->putNode(new NodeState($node->id, $kit->linkId, $data['region'], self::modes($data['allowed_modes']), $data['target_host'], $this->store()->node($node->id)?->domains));
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
        $this->store()->putNode(new NodeState($n->nodeId, $n->linkId, $n->region, self::modes($data['allowed_modes']), $n->targetHost, $n->domains));

        return self::ok($this->state($node), 'Allowed modes saved.');
    }

    /** The domains users may pick on this node, each optionally for admins only. Only domains the provider offers are kept. */
    public function saveDomains(Request $request, Node $node): JsonResponse
    {
        $data = $request->validate([
            'domains' => ['required', 'array', 'min:1', 'max:100'],
            'domains.*' => ['string', 'max:253'],
            'admin_only' => ['nullable', 'array', 'max:100'],
            'admin_only.*' => ['string', 'max:253'],
        ], ['domains.required' => 'Offer at least one domain.', 'domains.min' => 'Offer at least one domain.']);
        try {
            $candidates = $this->candidates($this->store()->node($node->id));
        } catch (\Throwable $e) {
            return self::refuse(ErrorText::for($e, true));
        }
        $domains = [];
        foreach ($candidates as $c) {
            if (in_array($c->domain, $data['domains'], true)) {
                $domains[] = new AllowedDomain($c->domain, $c->custom, in_array($c->domain, $data['admin_only'] ?? [], true), $c->validation);
            }
        }
        if ($domains === []) {
            return self::refuse('Offer at least one domain.');
        }
        $n = $this->store()->node($node->id) ?? new NodeState($node->id, null, null, Mode::cases());
        $this->store()->putNode(new NodeState($n->nodeId, $n->linkId, $n->region, $n->allowedModes, $n->targetHost, $domains));
        ReconcileRunner::forgetDomains($node->id);

        return self::ok($this->state($node), 'Domains saved. Servers that already have an address keep it.');
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
        $this->store()->putNode(new NodeState($n->nodeId, null, null, $n->allowedModes, $n->targetHost, $n->domains));

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
            'domain_options' => [],
            'domains' => [],
            'admin_only' => [],
            'domains_help' => self::DOMAINS_HELP,
            'own_domains_url' => self::ownDomainsUrl(),
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
            $candidates = $this->candidates($n);
            $out['domain_options'] = array_map(fn (AllowedDomain $d) => ['value' => $d->domain, 'label' => $d->domain . ($d->custom ? ' (own domain)' : '')], $candidates);
            $saved = $n?->domains;
            $out['domains'] = array_values(array_map(fn (AllowedDomain $d) => $d->domain, $saved ?? array_filter($candidates, fn (AllowedDomain $d) => !$d->custom)));
            $out['admin_only'] = array_values(array_map(fn (AllowedDomain $d) => $d->domain, array_filter($saved ?? [], fn (AllowedDomain $d) => $d->adminOnly)));
        } catch (\Throwable $e) {
            $out['problem'] = ErrorText::for($e, true);
        }

        return $out;
    }

    /** Where own domains are added and verified: the provider's own panel. */
    public static function ownDomainsUrl(): string
    {
        return GatewaySettings::providerUrl() . '/nodes?tab=routes';
    }

    /**
     * What the admin can offer: the provider's hoster domains, the account's VERIFIED own domains, and whatever is saved
     * on the node but no longer offered by the provider, so it can be unticked. Same rule as the Pelican plugin.
     *
     * @return AllowedDomain[]
     */
    private function candidates(?NodeState $n): array
    {
        $client = app(ProviderClient::class);
        $out = [];
        foreach ($client->regions()->hosterDomains as $h) {
            $out[$h->domain] = new AllowedDomain($h->domain, false, false, $h->validation);
        }
        foreach ($client->customDomains() as $c) {
            if ($c->verified() && !isset($out[$c->domain])) {
                $out[$c->domain] = new AllowedDomain($c->domain, true);
            }
        }
        foreach ($n?->domains ?? [] as $d) {
            $out[$d->domain] ??= $d;
        }

        return array_values($out);
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
