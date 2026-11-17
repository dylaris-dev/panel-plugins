<?php

namespace Dylaris\GatewayPtero\Http;

use Dylaris\GatewayPanel\Mode;
use Dylaris\GatewayPanel\ModeService;
use Dylaris\GatewayPanel\Provider\ProviderClient;
use Dylaris\GatewayPanel\ServerState;
use Dylaris\GatewayPanel\StateStore;
use Dylaris\GatewayPanel\StatusService;
use Dylaris\GatewayPtero\ErrorText;
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

    public function show(Request $request, Server $server): JsonResponse
    {
        $this->authorizeManage($request, $server);
        $this->clearRestartIfRestarted($server);

        return self::ok($this->state($request, $server));
    }

    public function mode(Request $request, Server $server): JsonResponse
    {
        $this->authorizeManage($request, $server);
        $data = $request->validate([
            'mode' => ['required', 'string', Rule::in(array_keys(self::MODE_LABELS))],
            'label' => ['nullable', 'string', 'max:63', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/'],
        ], ['label.regex' => 'Use lowercase letters, digits and dashes, not at the start or end.']);
        $label = $this->firstEnable($server) ? ($data['label'] ?? null) : null;
        try {
            $result = ReconcileRunner::change($server->id, fn () => app(ModeService::class)
                ->apply($server->id, Mode::from($data['mode']), $label, $this->isAdmin($request)));
        } catch (\Throwable $e) {
            return $this->fail($request, $e);
        }
        if (!$result->restartNeeded) {
            return self::ok($this->state($request, $server), 'The server is already in this mode.');
        }
        $this->store()->markRestartNeeded($server->id);

        return self::ok($this->state($request, $server), $result->notice);
    }

    /** What the chosen switch changes beyond the mode, for the confirm step. Changes nothing. */
    public function preview(Request $request, Server $server): JsonResponse
    {
        $this->authorizeManage($request, $server);
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
        $this->authorizeManage($request, $server);
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
        $this->authorizeManage($request, $server);
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
        $this->authorizeManage($request, $server);
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
        $allowed = $this->store()->node($server->node_id)?->allowedModes ?? Mode::cases();
        $offer = $this->isAdmin($request) ? Mode::cases() : $allowed;

        $modes = [];
        foreach (Mode::cases() as $m) {
            if ($m === $mode || in_array($m, $offer, true)) {
                $modes[] = ['value' => $m->value, 'label' => self::MODE_LABELS[$m->value]];
            }
        }
        $notOffered = !in_array($mode, $offer, true) ? ModeService::notOffered(self::MODE_LABELS[$mode->value]) : null;

        return [
            'mode' => $mode->value,
            'modes' => $modes,
            'not_offered' => $notOffered,
            'first_enable' => ($s?->label ?? '') === '',
            'label' => $this->suggestedLabel($server, $s),
            'address' => $s !== null && $s->mode !== Mode::Direct ? $s->domain() : null,
            'real_ip' => $s?->proxyProtocol ?? false,
            'bedrock' => $s?->bedrock ?? false,
            'restart_needed' => $s?->restartNeeded ?? false,
            'last_error' => $s?->lastError,
            'status' => $this->status($request, $s),
        ];
    }

    /**
     * Only before the first switch, when the field is shown; a provider that does not answer leaves it empty. Kept for
     * 10 minutes, so page views do not spend the provider's read budget: apply() checks the name again anyway.
     */
    private function suggestedLabel(Server $server, ?ServerState $s): string
    {
        if (($s?->label ?? '') !== '') {
            return '';
        }
        try {
            return Cache::remember(ReconcileRunner::labelKey($server->id), 600, fn () => app(ModeService::class)->suggestLabel($server->id));
        } catch (\Throwable) {
            return '';
        }
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
        return ($this->store()->get($server->id)?->label ?? '') === '';
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

    private function authorizeManage(Request $request, Server $server): void
    {
        abort_unless($request->user()?->can(Permissions::MANAGE, $server) ?? false, 403, 'You need the gateway permission for this server.');
    }

    private static function ok(array $data, ?string $message = null): JsonResponse
    {
        return new JsonResponse(['data' => $data, 'message' => $message]);
    }
}
