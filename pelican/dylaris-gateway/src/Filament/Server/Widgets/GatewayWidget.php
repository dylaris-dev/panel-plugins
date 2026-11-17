<?php

namespace Dylaris\GatewayPelican\Filament\Server\Widgets;

use App\Enums\TablerIcon;
use App\Models\Server;
use Dylaris\GatewayPanel\Mode;
use Dylaris\GatewayPanel\ModeService;
use Dylaris\GatewayPanel\Provider\ProviderClient;
use Dylaris\GatewayPanel\ServerState;
use Dylaris\GatewayPanel\StateStore;
use Dylaris\GatewayPanel\StatusService;
use Dylaris\GatewayPelican\EloquentStateStore;
use Dylaris\GatewayPelican\ErrorText;
use Dylaris\GatewayPelican\GatewayLock;
use Dylaris\GatewayPelican\Permissions;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;

/** The gateway panel on the server console: mode, address, Real IP, Bedrock, status. */
class GatewayWidget extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public const MODE_LABELS = ['direct' => 'Direct', 'both' => 'Both', 'gateway_only' => 'Gateway only'];

    protected string $view = 'dylaris-gateway::gateway-widget';

    protected int|string|array $columnSpan = 'full';

    public ?Server $server = null;

    public ?array $data = [];

    public ?string $statusLine = null;

    public static function canView(): bool
    {
        $server = Filament::getTenant();

        // Owner and panel admins pass this check implicitly (User::hasPermission), subusers need the permission.
        return $server instanceof Server && (user()?->can(Permissions::MANAGE, $server) ?? false);
    }

    public function mount(): void
    {
        $this->authorizeManage();
        $this->refreshState();
    }

    public function hydrate(): void
    {
        $this->authorizeManage();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('mode')
                ->label('Mode')
                ->options(fn () => $this->modeOptions())
                ->selectablePlaceholder(false)
                ->required()
                ->helperText('Direct: players use the server\'s own address. Both: the own address and the gateway address. Gateway only: just the gateway address, the server\'s port is closed.'),
            TextInput::make('label')
                ->label('Address name')
                ->visible(fn () => $this->firstEnable())
                ->required()
                ->maxLength(63)
                ->regex('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/')
                ->validationMessages(['regex' => 'Use lowercase letters, digits and dashes, not at the start or end.'])
                ->helperText('The first part of the gateway address. It is chosen once, before the gateway is first enabled.'),
            TextEntry::make('address')
                ->label('Gateway address')
                ->state(fn () => $this->address())
                ->copyable()
                ->visible(fn () => $this->address() !== null),
            Toggle::make('real_ip')
                ->label('Real player IP')
                ->visible(fn () => $this->state()?->mode === Mode::GatewayOnly)
                ->live()
                ->afterStateUpdated(fn (bool $state) => $this->switchRealIp($state))
                ->helperText('Passes each player\'s real IP with the PROXY protocol. Turn it on in the server as well, or nobody can join. Paper: proxies.proxy-protocol: true in config/paper-global.yml. Velocity: haproxy-protocol = true in velocity.toml.'),
            Toggle::make('bedrock')
                ->label('Bedrock players')
                ->visible(fn () => in_array($this->state()?->mode, [Mode::Both, Mode::GatewayOnly], true))
                ->live()
                ->afterStateUpdated(fn (bool $state) => $this->switchBedrock($state))
                ->helperText('Lets Bedrock players join through the gateway. The server needs Floodgate with the key below.'),
        ])->statePath('data');
    }

    public function applyAction(): Action
    {
        return Action::make('apply')
            ->label('Apply mode')
            ->icon(TablerIcon::Check)
            // Only when the switch changes more than the mode: a moved direct port, Real player IP going off.
            ->requiresConfirmation(fn () => $this->previewWarnings() !== [])
            // A heading alone makes Filament open a modal; without warnings the switch runs at once.
            ->modalHidden(fn () => $this->previewWarnings() === [])
            ->modalHeading('Switch the mode?')
            ->modalDescription(fn () => implode(' ', $this->previewWarnings()))
            ->action(function () {
                $data = $this->form->getState();
                $label = $this->firstEnable() ? ($data['label'] ?? null) : null;
                try {
                    $result = GatewayLock::change($this->server->id, fn () => app(ModeService::class)
                        ->apply($this->server->id, Mode::from($data['mode']), $label, $this->actorIsAdmin()));
                } catch (\Throwable $e) {
                    $this->fail($e);
                    $this->refreshState();

                    return;
                }
                if ($result->restartNeeded) {
                    $this->store()->markRestartNeeded($this->server->id);
                    Notification::make()->title($result->notice)->success()->send();
                } else {
                    Notification::make()->title('The server is already in this mode.')->info()->send();
                }
                $this->refreshState();
            });
    }

    public function floodgateKeyAction(): Action
    {
        return Action::make('floodgateKey')
            ->label('Download Floodgate key')
            ->icon(TablerIcon::Download)
            ->color('gray')
            ->visible(fn () => $this->state()?->bedrock === true)
            ->action(function () {
                try {
                    $key = base64_decode(app(ProviderClient::class)->floodgateKey($this->state()->domain()), true);
                } catch (\Throwable $e) {
                    $this->fail($e);

                    return null;
                }
                if ($key === false || $key === '') {
                    Notification::make()->title('The gateway has no Floodgate key for this address yet. Try again in a minute.')->danger()->send();

                    return null;
                }

                // A file response only: the key never becomes part of the page.
                return response()->streamDownload(function () use ($key) {
                    echo $key;
                }, 'key.pem', ['Content-Type' => 'application/octet-stream']);
            });
    }

    /** @return list<string> what the chosen switch changes beyond the mode, for the confirm step */
    public function previewWarnings(): array
    {
        $to = Mode::tryFrom((string) ($this->data['mode'] ?? ''));
        if ($to === null) {
            return [];
        }
        try {
            return app(ModeService::class)->preview($this->server->id, $to);
        } catch (\Throwable) {
            return []; // apply() says what is wrong
        }
    }

    /** @return array<string, string> the node's allowed modes plus the current one; admins get every mode */
    public function modeOptions(): array
    {
        $allowed = $this->actorIsAdmin() ? Mode::cases() : ($this->store()->node($this->server->node_id)?->allowedModes ?? Mode::cases());
        $current = $this->state()?->mode ?? Mode::Direct;

        $out = [];
        foreach (Mode::cases() as $m) {
            if ($m === $current || in_array($m, $allowed, true)) {
                $out[$m->value] = self::MODE_LABELS[$m->value];
            }
        }

        return $out;
    }

    protected function getViewData(): array
    {
        return ['state' => $this->state(), 'notOffered' => $this->notOffered()];
    }

    /** Narrowing a node's modes never moves a server; the page says its mode is no longer offered. */
    private function notOffered(): ?string
    {
        $mode = $this->state()?->mode ?? Mode::Direct;
        $allowed = $this->store()->node($this->server->node_id)?->allowedModes ?? Mode::cases();

        return $this->actorIsAdmin() || in_array($mode, $allowed, true) ? null : ModeService::notOffered(self::MODE_LABELS[$mode->value]);
    }

    private function switchRealIp(bool $on): void
    {
        try {
            GatewayLock::change($this->server->id, fn () => app(ModeService::class)->setRealIp($this->server->id, $on));
            Notification::make()->title($on ? 'Real player IP is on.' : 'Real player IP is off.')->success()->send();
        } catch (\Throwable $e) {
            $this->fail($e);
            $this->data['real_ip'] = !$on;
        }
    }

    private function switchBedrock(bool $on): void
    {
        try {
            GatewayLock::change($this->server->id, fn () => app(ModeService::class)->setBedrock($this->server->id, $on));
            Notification::make()->title($on ? 'Bedrock players are on. Install the Floodgate key next.' : 'Bedrock players are off.')->success()->send();
        } catch (\Throwable $e) {
            $this->fail($e);
            $this->data['bedrock'] = !$on;
        }
    }

    private function refreshState(): void
    {
        $s = $this->state();
        $this->clearRestartIfRestarted($s);
        $this->form->fill([
            'mode' => ($s?->mode ?? Mode::Direct)->value,
            'label' => $this->data['label'] ?? $this->suggestedLabel($s), // keep what the user typed after a refusal
            'real_ip' => $s?->proxyProtocol ?? false,
            'bedrock' => $s?->bedrock ?? false,
        ]);
        $this->statusLine = $this->loadStatus($s);
    }

    /**
     * Wings reports uptime, not restarts: a server whose current run began after the flag was
     * set has picked up the allocation change. Wings unreachable or the server stopped: keep it.
     */
    private function clearRestartIfRestarted(?ServerState $s): void
    {
        if ($s === null || !$s->restartNeeded) {
            return;
        }
        $since = $this->store()->restartNeededSince($s->serverId);
        try {
            $uptimeMs = (int) ($this->server->retrieveResources()['uptime'] ?? 0);
        } catch (\Throwable) {
            return;
        }
        if ($since !== null && $uptimeMs > 0 && time() - intdiv($uptimeMs, 1000) > $since) {
            $this->store()->clearRestartNeeded($s->serverId);
        }
    }

    /**
     * Only before the first switch, when the field is shown; a provider that does not answer leaves it empty. Kept for
     * 10 minutes, so console views do not spend the provider's read budget: apply() checks the name again anyway.
     */
    private function suggestedLabel(?ServerState $s): ?string
    {
        if (($s?->label ?? '') !== '') {
            return null;
        }
        try {
            return Cache::remember(GatewayLock::labelKey($this->server->id), 600, fn () => app(ModeService::class)->suggestLabel($this->server->id));
        } catch (\Throwable) {
            return null;
        }
    }

    private function loadStatus(?ServerState $s): ?string
    {
        if ($s === null || $s->mode === Mode::Direct) {
            return null;
        }
        try {
            // 30 s per server: every console view would otherwise make three provider reads. A change clears it.
            return Cache::remember(GatewayLock::statusKey($s->serverId), 30, fn () => StatusService::line(app(StatusService::class)->forServer($s->serverId)));
        } catch (\Throwable $e) {
            return ErrorText::for($e, $this->actorIsAdmin());
        }
    }

    private function fail(\Throwable $e): void
    {
        Notification::make()->title(ErrorText::for($e, $this->actorIsAdmin()))->danger()->send();
    }

    private function address(): ?string
    {
        $s = $this->state();

        return $s !== null && $s->mode !== Mode::Direct ? $s->domain() : null;
    }

    private function firstEnable(): bool
    {
        return ($this->state()?->label ?? '') === '';
    }

    private function state(): ?ServerState
    {
        return $this->store()->get($this->server->id);
    }

    private function store(): EloquentStateStore
    {
        return app(StateStore::class);
    }

    /** Panel admins may pick any mode; the node's allowed modes bind everyone else. */
    private function actorIsAdmin(): bool
    {
        return user()?->can('update', $this->server) ?? false;
    }

    private function authorizeManage(): void
    {
        abort_unless($this->server !== null && (user()?->can(Permissions::MANAGE, $this->server) ?? false), 403);
    }
}
