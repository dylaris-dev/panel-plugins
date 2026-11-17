<?php

namespace Dylaris\GatewayPtero\Http;

use Dylaris\GatewayPanel\AllowedDomain;
use Dylaris\GatewayPanel\Mode;
use Dylaris\GatewayPanel\ModeService;
use Dylaris\GatewayPanel\Provider\ProviderClient;
use Dylaris\GatewayPanel\Reconciler;
use Dylaris\GatewayPanel\ServerState;
use Dylaris\GatewayPanel\StateStore;
use Dylaris\GatewayPanel\StatusService;
use Dylaris\GatewayPtero\ErrorText;
use Dylaris\GatewayPtero\GatewaySettings;
use Dylaris\GatewayPtero\Permissions;
use Dylaris\GatewayPtero\PteroStateStore;
use Dylaris\GatewayPtero\ReconcileRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpFoundation\Response;

/**
 * The server's Gateway page (its own screen, see extension.json): mode, address, Real IP, Bedrock, status. Mounted under
 * /api/client/servers/{server}/extensions/dylaris-gateway, whose middleware lets every user of the server in; each
 * action here checks the gateway permission itself. The server always comes from the route.
 */
final class ServerGatewayController
{
    public const MODE_LABELS = ['direct' => 'Direct', 'both' => 'Both', 'gateway_only' => 'Gateway only'];

    private const LABEL_MESSAGES = ['label.regex' => 'Use lowercase letters, digits and dashes, not at the start or end.'];

    public function show(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, Permissions::VIEW);
        $this->clearRestartIfRestarted($server);

        return self::ok($this->state($request, $server));
    }

    public function mode(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, Permissions::MODE);
        $data = $request->validate([
            'mode' => ['required', 'string', Rule::in(array_keys(self::MODE_LABELS))],
            'label' => self::labelRule(),
            'domain' => ['nullable', 'string', 'max:253'],
        ], self::LABEL_MESSAGES);
        // A first address comes from the request for whoever may choose it, else the extension picks one.
        $choose = $this->firstEnable($server) && Permissions::can($request->user(), Permissions::ADDRESS, $server);
        try {
            $result = ReconcileRunner::change($server->id, fn () => app(ModeService::class)->apply(
                $server->id,
                Mode::from($data['mode']),
                $choose ? ($data['label'] ?? null) : null,
                $this->isAdmin($request),
                $choose ? ($data['domain'] ?? null) : null,
            ));
        } catch (\Throwable $e) {
            return $this->fail($request, $e);
        }
        if (!$result->restartNeeded) {
            return self::ok($this->state($request, $server), 'The server is already in this mode.');
        }
        $this->store()->markRestartNeeded($server->id);

        return self::ok($this->state($request, $server), $result->notice);
    }

    /** A new address: in Direct only remembered, behind the gateway the new route first and the old one deleted last. */
    public function address(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, Permissions::ADDRESS);
        $data = $request->validate([
            'label' => self::labelRule(),
            'domain' => ['required', 'string', 'max:253'],
        ], self::LABEL_MESSAGES);
        try {
            $result = ReconcileRunner::change($server->id, fn () => app(ModeService::class)
                ->changeAddress($server->id, $data['label'] ?? null, $data['domain'], $this->isAdmin($request)));
        } catch (\Throwable $e) {
            return $this->fail($request, $e);
        }

        return self::ok($this->state($request, $server), $result->notice);
    }

    /** What the chosen switch changes beyond the mode, for the confirm step. Changes nothing. */
    public function preview(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, Permissions::MODE);
        $data = $request->validate(['mode' => ['required', 'string', Rule::in(array_keys(self::MODE_LABELS))]]);
        try {
            $warnings = app(ModeService::class)->preview($server->id, Mode::from($data['mode']));
        } catch (\Throwable) {
            $warnings = []; // the switch itself says what is wrong
        }

        return self::ok(['warnings' => $warnings]);
    }

    public function realIp(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, Permissions::MODE);
        $on = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        try {
            ReconcileRunner::change($server->id, fn () => app(ModeService::class)->setRealIp($server->id, $on));
        } catch (\Throwable $e) {
            return $this->fail($request, $e);
        }

        return self::ok($this->state($request, $server), $on ? 'Real player IP is on.' : 'Real player IP is off.');
    }

    public function bedrock(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, Permissions::MODE);
        $on = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        try {
            ReconcileRunner::change($server->id, fn () => app(ModeService::class)->setBedrock($server->id, $on));
        } catch (\Throwable $e) {
            return $this->fail($request, $e);
        }

        return self::ok($this->state($request, $server), $on ? 'Bedrock players are on. Install the Floodgate key next.' : 'Bedrock players are off.');
    }

    public function floodgateKey(Request $request, Server $server): Response
    {
        $this->authorize($request, $server, Permissions::MODE);
        $s = $this->store()->get($server->id);
        if ($s?->bedrock !== true) {
            return new JsonResponse(['error' => 'Turn on Bedrock players first.'], 422);
        }
        try {
            $key = base64_decode(app(ProviderClient::class)->floodgateKey($s->domain()), true);
        } catch (\Throwable $e) {
            return $this->fail($request, $e);
        }
        if ($key === false || $key === '') {
            return new JsonResponse(['error' => 'The gateway has no Floodgate key for this address yet. Try again in a minute.'], 422);
        }

        // A file response only: the key never becomes part of the page.
        return response()->streamDownload(function () use ($key) {
            echo $key;
        }, 'key.pem', ['Content-Type' => 'application/octet-stream']);
    }

    /** @return array<string, mixed> */
    private function state(Request $request, Server $server): array
    {
        $s = $this->store()->get($server->id);
        $mode = $s?->mode ?? Mode::Direct;
        $user = $request->user();
        $can = [
            'mode' => Permissions::can($user, Permissions::MODE, $server),
            'address' => Permissions::can($user, Permissions::ADDRESS, $server),
        ];
        $eggAllowed = GatewaySettings::eggAllowed($server->egg);
        if (!$eggAllowed) {
            // Already behind the gateway: it keeps working, and only an admin can take it back to Direct.
            $offer = $this->isAdmin($request) && $mode !== Mode::Direct ? [Mode::Direct] : [];
        } else {
            $offer = $this->isAdmin($request) ? Mode::cases() : ($this->store()->node($server->node_id)?->allowedModes ?? Mode::cases());
        }

        $modes = [];
        foreach (Mode::cases() as $m) {
            if ($m === $mode || in_array($m, $offer, true)) {
                $modes[] = ['value' => $m->value, 'label' => self::MODE_LABELS[$m->value]];
            }
        }
        $notOffered = $eggAllowed && !in_array($mode, $offer, true) ? ModeService::notOffered(self::MODE_LABELS[$mode->value]) : null;
        $domains = $can['address'] && $eggAllowed ? $this->domainChoices($request, $server) : [];
        $hasAddress = $s?->hasAddress() ?? false;
        $current = $hasAddress && in_array($s->hosterDomain, array_column($domains, 'value'), true) ? $s->hosterDomain : ($domains[0]['value'] ?? '');

        return [
            'mode' => $mode->value,
            'modes' => $modes,
            'not_offered' => $notOffered,
            'not_allowed' => $eggAllowed ? null : ($mode === Mode::Direct ? ModeService::MINECRAFT_ONLY : ModeService::NO_LONGER_ALLOWED),
            'no_domains' => $can['address'] && $eggAllowed && $domains === [] ? ModeService::NO_DOMAINS : null,
            'can_mode' => $can['mode'],
            'can_address' => $can['address'] && $eggAllowed,
            'first_enable' => !$hasAddress,
            'domains' => $domains,
            'domain' => $current,
            'label' => $hasAddress ? $s->label : ($can['address'] ? $this->suggestedLabel($request, $server) : ''),
            'address' => $s !== null && $s->mode !== Mode::Direct ? $s->domain() : null,
            'real_ip' => $s?->proxyProtocol ?? false,
            'bedrock' => $s?->bedrock ?? false,
            'restart_needed' => $s?->restartNeeded ?? false,
            'last_error' => $s?->lastError,
            'status' => $this->status($request, $s),
        ];
    }

    /**
     * Before the first address only; a provider that does not answer leaves it empty. Kept for 10 minutes, so page
     * views do not spend the provider's read budget: apply() checks the name again anyway.
     */
    private function suggestedLabel(Request $request, Server $server): string
    {
        try {
            return Cache::remember(ReconcileRunner::labelKey($server->id, $this->isAdmin($request)), 600, fn () => app(ModeService::class)->suggestLabel($server->id, $this->isAdmin($request)));
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * The domains the user may pick, from the node's list (no provider call) or, never saved, every hoster domain of
     * the provider (kept 10 minutes). While the provider asked to wait, or when it fails, nothing is offered.
     *
     * @return list<array{value: string, label: string, custom: bool}>
     */
    private function domainChoices(Request $request, Server $server): array
    {
        if (Reconciler::busyUntil($this->store()->all()) > time()) {
            return [];
        }
        $admin = $this->isAdmin($request);
        try {
            $rows = Cache::remember(ReconcileRunner::domainsKey($server->node_id, $admin), 600, fn () => array_map(
                fn (AllowedDomain $d) => $d->toArray(),
                app(ModeService::class)->domainChoices($server->node_id, $admin),
            ));
        } catch (\Throwable) {
            return [];
        }

        return array_map(fn (array $d) => [
            'value' => $d['domain'],
            'label' => $d['custom'] ? "{$d['domain']} (own domain, the whole address)" : $d['domain'],
            'custom' => $d['custom'],
        ], $rows);
    }

    /**
     * Wings reports uptime, not restarts: a server whose current run began after the flag was set has picked up the
     * allocation change. Wings unreachable or the server stopped: keep it. Same rule as the Pelican plugin.
     */
    private function clearRestartIfRestarted(Server $server): void
    {
        $s = $this->store()->get($server->id);
        if ($s === null || !$s->restartNeeded) {
            return;
        }
        $since = $this->store()->restartNeededSince($s->serverId);
        try {
            $uptimeMs = app(ReadsServerState::class)->read($server)->uptime;
        } catch (\Throwable) {
            return;
        }
        if ($since !== null && $uptimeMs > 0 && time() - intdiv($uptimeMs, 1000) > $since) {
            $this->store()->clearRestartNeeded($s->serverId);
        }
    }

    private function status(Request $request, ?ServerState $s): ?string
    {
        if ($s === null || $s->mode === Mode::Direct) {
            return null;
        }
        try {
            // 30 s per server: every page view would otherwise make three provider reads. A change clears it.
            return Cache::remember(ReconcileRunner::statusKey($s->serverId), 30, fn () => StatusService::line(app(StatusService::class)->forServer($s->serverId)));
        } catch (\Throwable $e) {
            return ErrorText::for($e, $this->isAdmin($request));
        }
    }

    private function fail(Request $request, \Throwable $e): JsonResponse
    {
        return new JsonResponse(['error' => ErrorText::for($e, $this->isAdmin($request))], 422);
    }

    private function firstEnable(Server $server): bool
    {
        return !($this->store()->get($server->id)?->hasAddress() ?? false);
    }

    private function store(): PteroStateStore
    {
        return app(StateStore::class);
    }

    /** Root admins may pick any mode; the node's allowed modes bind the owner and subusers. */
    private function isAdmin(Request $request): bool
    {
        return (bool) $request->user()?->root_admin;
    }

    private function authorize(Request $request, Server $server, string $permission): void
    {
        abort_unless(Permissions::can($request->user(), $permission, $server), 403, 'You need the gateway permission for this server.');
    }

    /** @return list<mixed> */
    private static function labelRule(): array
    {
        return ['nullable', 'string', 'max:63', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/'];
    }

    private static function ok(array $data, ?string $message = null): JsonResponse
    {
        return new JsonResponse(['data' => $data, 'message' => $message]);
    }
}
