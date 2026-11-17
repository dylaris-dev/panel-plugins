<?php

namespace Dylaris\GatewayPelican\Filament\Server\Widgets;

use App\Enums\SubuserPermission;
use App\Enums\TablerIcon;
use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use Dylaris\GatewayPanel\AllowedDomain;
use Dylaris\GatewayPanel\Mode;
use Dylaris\GatewayPanel\ModeService;
use Dylaris\GatewayPanel\Provider\ProviderClient;
use Dylaris\GatewayPanel\Reconciler;
use Dylaris\GatewayPanel\ServerState;
use Dylaris\GatewayPanel\StateStore;
use Dylaris\GatewayPanel\StatusService;
use Dylaris\GatewayPelican\EloquentStateStore;
use Dylaris\GatewayPelican\ErrorText;
use Dylaris\GatewayPelican\GatewayLock;
use Dylaris\GatewayPelican\GatewaySettings;
use Dylaris\GatewayPelican\Permissions;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
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

/**
 * The gateway box on the server's Gateway page: status and address (view), mode, Real IP and Bedrock (mode), the
 * address (address). Every action checks its own permission; mount and hydrate check view.
 */
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

    public function mount(): void
    {
        $this->authorizeView();
        $this->refreshState();
    }

    public function hydrate(): void
    {
        $this->authorizeView();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('current_mode')
                ->label('Mode')
                ->state(fn () => self::MODE_LABELS[($this->state()?->mode ?? Mode::Direct)->value])
                ->visible(fn () => !$this->modeSelectable()),
            Select::make('mode')
                ->label('Mode')
                ->options(fn () => $this->modeOptions())
                ->selectablePlaceholder(false)
                ->required()
                ->visible(fn () => $this->modeSelectable())
                ->helperText('Direct: players use the server\'s own address. Both: the own address and the gateway address. Gateway only: just the gateway address, the server\'s port is closed.'),
            TextEntry::make('address')
                ->label('Gateway address')
                ->state(fn () => $this->address())
                ->copyable()
                ->visible(fn () => $this->address() !== null),
            Select::make('domain')
                ->label('Domain')
                ->options(fn () => $this->domainOptions())
                ->selectablePlaceholder(false)
                ->live()
                ->visible(fn () => $this->addressEditable())
                ->helperText('The domains this node offers, set by the panel admin on the node\'s Gateway tab.'),
            TextInput::make('label')
                ->label('Address name')
                ->maxLength(63)
                ->regex('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/')
                ->validationMessages(['regex' => 'Use lowercase letters, digits and dashes, not at the start or end.'])
                ->visible(fn () => $this->addressEditable() && !($this->chosenDomain()?->custom ?? false))
                ->suffix(fn () => $this->chosenDomain() ? '.' . $this->chosenDomain()->domain : null)
                ->helperText(fn () => $this->state()?->hasAddress()
                    ? 'The first part of the address. Changing it later moves the server to the new address; the old one stops working.'
                    : 'The first part of the gateway address.'),
            Toggle::make('real_ip')
                ->label('Real player IP')
                ->visible(fn () => $this->canMode() && $this->state()?->mode === Mode::GatewayOnly)
                ->live()
                ->afterStateUpdated(fn (bool $state) => $this->switchRealIp($state))
                ->helperText('Passes each player\'s real IP with the PROXY protocol. Turn it on in the server as well, or nobody can join. Paper: proxies.proxy-protocol: true in config/paper-global.yml. Velocity: haproxy-protocol = true in velocity.toml.'),
            Toggle::make('bedrock')
                ->label('Bedrock players')
                ->visible(fn () => $this->canMode() && in_array($this->state()?->mode, [Mode::Both, Mode::GatewayOnly], true))
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
            ->visible(fn () => $this->modeSelectable())
            ->authorize(fn () => $this->canMode())
            // Only when the switch changes more than the mode: a moved direct port, Real player IP going off.
            ->requiresConfirmation(fn () => $this->previewWarnings() !== [])
            // A heading alone makes Filament open a modal; without warnings the switch runs at once.
            ->modalHidden(fn () => $this->previewWarnings() === [])
            ->modalHeading('Switch the mode?')
            ->modalDescription(fn () => implode(' ', $this->previewWarnings()))
            ->action(function () {
                $data = $this->form->getState();
                // A first address comes from the fields for whoever may choose it, else the plugin picks one.
                $choose = !($this->state()?->hasAddress() ?? false) && $this->canAddress();
                try {
                    $result = GatewayLock::change($this->server->id, fn () => app(ModeService::class)->apply(
                        $this->server->id,
                        Mode::from($data['mode']),
                        $choose ? ($data['label'] ?? null) : null,
                        $this->actorIsAdmin(),
                        $choose ? ($data['domain'] ?? null) : null,
                    ));
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

    public function changeAddressAction(): Action
    {
        return Action::make('changeAddress')
            ->label(fn () => $this->gatewayActive() ? 'Change address' : 'Save address')
            ->icon(TablerIcon::Pencil)
            ->color('gray')
            ->visible(fn () => $this->addressEditable())
            ->authorize(fn () => $this->canAddress())
            ->requiresConfirmation(fn () => $this->gatewayActive())
            ->modalHidden(fn () => !$this->gatewayActive())
            ->modalHeading('Change the gateway address?')
            ->modalDescription(fn () => "The new address works right away and the old address {$this->address()} stops working: players have to use the new one. No restart is needed.")
            ->action(function () {
                $data = $this->form->getState();
                try {
                    $result = GatewayLock::change($this->server->id, fn () => app(ModeService::class)
                        ->changeAddress($this->server->id, $data['label'] ?? null, (string) ($data['domain'] ?? ''), $this->actorIsAdmin()));
                } catch (\Throwable $e) {
                    $this->fail($e);
                    $this->refreshState();

                    return;
                }
                Notification::make()->title($result->notice)->success()->send();
                $this->refreshState();
            });
    }

    public function restartAction(): Action
    {
        return Action::make('restart')
            ->label('Restart now')
            ->icon(TablerIcon::Reload)
            ->color('warning')
            ->visible(fn () => ($this->state()?->restartNeeded ?? false) && $this->canRestart())
            ->authorize(fn () => $this->canRestart())
            ->requiresConfirmation()
            ->modalHeading('Restart the server now?')
            ->modalDescription('Players on the server are disconnected while it restarts.')
            ->action(function () {
                try {
                    app(DaemonServerRepository::class)->setServer($this->server)->power('restart');
                } catch (\Throwable $e) {
                    $this->fail($e);

                    return;
                }
                Notification::make()->title('The server is restarting.')->success()->send();
            });
    }

    public function floodgateKeyAction(): Action
    {
        return Action::make('floodgateKey')
            ->label('Download Floodgate key')
            ->icon(TablerIcon::Download)
            ->color('gray')
            ->visible(fn () => $this->canMode() && $this->state()?->bedrock === true)
            ->authorize(fn () => $this->canMode())
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

    /**
     * The node's allowed modes plus the current one; admins get every mode. A server whose egg may not use the
     * gateway gets nothing, or, already behind it, Direct for an admin.
     *
     * @return array<string, string>
     */
    public function modeOptions(): array
    {
        $current = $this->state()?->mode ?? Mode::Direct;
        if (!$this->eggAllowed()) {
            $allowed = $this->actorIsAdmin() && $current !== Mode::Direct ? [Mode::Direct] : [];
        } else {
            $allowed = $this->actorIsAdmin() ? Mode::cases() : ($this->store()->node($this->server->node_id)?->allowedModes ?? Mode::cases());
        }

        $out = [];
        foreach (Mode::cases() as $m) {
            if ($m === $current || in_array($m, $allowed, true)) {
                $out[$m->value] = self::MODE_LABELS[$m->value];
            }
        }

        return $out;
    }

    /** @return array<string, string> domain => what the select shows */
    public function domainOptions(): array
    {
        $out = [];
        foreach ($this->domainChoices() as $d) {
            $out[$d->domain] = $d->custom ? "{$d->domain} (own domain, the whole address)" : $d->domain;
        }

        return $out;
    }

    protected function getViewData(): array
    {
        return ['state' => $this->state(), 'notOffered' => $this->notOffered(), 'notAllowed' => $this->notAllowed()];
    }

    /** Narrowing a node's modes never moves a server; the page says its mode is no longer offered. */
    private function notOffered(): ?string
    {
        $mode = $this->state()?->mode ?? Mode::Direct;
        $allowed = $this->store()->node($this->server->node_id)?->allowedModes ?? Mode::cases();

        return !$this->eggAllowed() || $this->actorIsAdmin() || in_array($mode, $allowed, true) ? null : ModeService::notOffered(self::MODE_LABELS[$mode->value]);
    }

    private function notAllowed(): ?string
    {
        if ($this->eggAllowed()) {
            return null;
        }

        return $this->gatewayActive() ? ModeService::NO_LONGER_ALLOWED : ModeService::MINECRAFT_ONLY;
    }

    private function switchRealIp(bool $on): void
    {
        abort_unless($this->canMode(), 403);
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
        abort_unless($this->canMode(), 403);
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
        $options = $this->addressEditable() ? $this->domainOptions() : [];
        $domain = $s?->hasAddress() && isset($options[$s->hosterDomain]) ? $s->hosterDomain : array_key_first($options);
        $this->form->fill([
            'mode' => ($s?->mode ?? Mode::Direct)->value,
            'domain' => $this->data['domain'] ?? $domain, // keep what the user picked and typed after a refusal
            'label' => $this->data['label'] ?? ($s?->hasAddress() ? $s->label : $this->suggestedLabel()),
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
     * Before the first address only; a provider that does not answer leaves it empty. Kept for 10 minutes, so page
     * views do not spend the provider's read budget: apply() checks the name again anyway.
     */
    private function suggestedLabel(): ?string
    {
        if (!$this->canAddress()) {
            return null;
        }
        try {
            return Cache::remember(GatewayLock::labelKey($this->server->id), 600, fn () => app(ModeService::class)->suggestLabel($this->server->id, $this->actorIsAdmin()));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The node's saved list needs no provider call; the default (every hoster domain) does, so it is kept for 10
     * minutes. A failed read offers nothing and apply() says why.
     *
     * @return AllowedDomain[]
     */
    private function domainChoices(): array
    {
        // A page view waits out the provider's Retry-After instead of spending the read budget.
        if (Reconciler::busyUntil($this->store()->all()) > time()) {
            return [];
        }
        try {
            $rows = Cache::remember(GatewayLock::domainsKey($this->server->node_id, $this->actorIsAdmin()), 600, fn () => array_map(
                fn (AllowedDomain $d) => $d->toArray(),
                app(ModeService::class)->domainChoices($this->server->node_id, $this->actorIsAdmin()),
            ));
        } catch (\Throwable) {
            return [];
        }

        return array_map(AllowedDomain::fromArray(...), $rows);
    }

    private function chosenDomain(): ?AllowedDomain
    {
        foreach ($this->domainChoices() as $d) {
            if ($d->domain === ($this->data['domain'] ?? null)) {
                return $d;
            }
        }

        return null;
    }

    private function loadStatus(?ServerState $s): ?string
    {
        if ($s === null || $s->mode === Mode::Direct) {
            return null;
        }
        try {
            // 30 s per server: every page view would otherwise make three provider reads. A change clears it.
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
        return $this->gatewayActive() ? $this->state()->domain() : null;
    }

    private function gatewayActive(): bool
    {
        $s = $this->state();

        return $s !== null && $s->mode !== Mode::Direct;
    }

    private function modeSelectable(): bool
    {
        return $this->canMode() && count($this->modeOptions()) > 1;
    }

    private function addressEditable(): bool
    {
        return $this->canAddress() && $this->eggAllowed();
    }

    private function eggAllowed(): bool
    {
        return GatewaySettings::eggAllowed($this->server->egg);
    }

    private function state(): ?ServerState
    {
        return $this->store()->get($this->server->id);
    }

    private function store(): EloquentStateStore
    {
        return app(StateStore::class);
    }

    /** Panel admins may pick any mode and the admins-only domains; the node's rules bind everyone else. */
    private function actorIsAdmin(): bool
    {
        return user()?->can('update', $this->server) ?? false;
    }

    private function canMode(): bool
    {
        return Permissions::can(Permissions::MODE, $this->server);
    }

    private function canAddress(): bool
    {
        return Permissions::can(Permissions::ADDRESS, $this->server);
    }

    private function canRestart(): bool
    {
        return user()?->can(SubuserPermission::ControlRestart, $this->server) ?? false;
    }

    private function authorizeView(): void
    {
        abort_unless($this->server !== null && Permissions::can(Permissions::VIEW, $this->server), 403);
    }
}
