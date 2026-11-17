<?php

namespace Dylaris\GatewayPelican\Livewire;

use App\Enums\TablerIcon;
use App\Models\Node;
use App\Models\Server;
use Dylaris\GatewayPanel\AllowedDomain;
use Dylaris\GatewayPanel\Mode;
use Dylaris\GatewayPanel\NodeState;
use Dylaris\GatewayPanel\Provider\ProviderClient;
use Dylaris\GatewayPanel\Provider\ProviderException;
use Dylaris\GatewayPanel\StateStore;
use Dylaris\GatewayPelican\ErrorText;
use Dylaris\GatewayPelican\Filament\Server\Widgets\GatewayWidget;
use Dylaris\GatewayPelican\GatewayLock;
use Dylaris\GatewayPelican\GatewaySettings;
use Dylaris\GatewayPelican\GatewayUnavailable;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Component;

/** The Gateway tab on the admin node page: one link kit per node. */
final class NodeGateway extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public const DEFAULT_TARGET = '172.18.0.1';

    public const DOMAINS_HELP = "The domains users pick from for a server's address on this node. A hoster domain takes a name in front of it (survival.eu.example.com). An own domain is the whole address: the provider verifies each exact name by CNAME and TXT, so a name in front of it would be a different, unverified one. Only verified own domains are listed.";

    public const NO_REGION = 'No gateway region is online right now, so this node cannot be connected yet. Try again later.';

    public Node $node;

    public ?array $data = [];

    /** @var array<string, string> online regions, for the select before connecting */
    public array $regions = [];

    public ?string $kitStatus = null;

    /** @var array<string, string> domain => label, the domains the admin can offer on this node */
    public array $domainOptions = [];

    /** Why the provider data could not be loaded, shown instead of failing the page. */
    public ?string $problem = null;

    /** Why the node cannot be connected yet, when nothing failed. */
    public ?string $notice = null;

    /**
     * The compose snippet with the link key, in the Connect response only. Not public: Livewire would keep a public
     * property in the snapshot, and the key would travel back with every later request.
     */
    protected ?string $snippet = null;

    public function mount(): void
    {
        $this->authorizeAccess();
        $n = $this->nodeState();
        $this->form->fill([
            'region' => $n?->region,
            'target_host' => $n?->targetHost ?? self::DEFAULT_TARGET,
            'allowed_modes' => array_map(fn (Mode $m) => $m->value, $n?->allowedModes ?? Mode::cases()),
        ]);
        $this->loadProvider();
        $this->fillDomains();
    }

    public function hydrate(): void
    {
        $this->authorizeAccess();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('region')
                ->label('Region')
                ->options(fn () => $this->connected() ? [$this->nodeState()->region => $this->nodeState()->region] : $this->regions)
                ->required()
                ->disabled(fn () => $this->connected())
                ->helperText('Where players connect to this node\'s servers. Changing the region later means retiring this node\'s link kit, and link kits are limited.'),
            TextInput::make('target_host')
                ->label('Wings interface IP')
                ->required()
                ->ip()
                ->disabled(fn () => $this->connected())
                ->helperText('The docker.network.interface value from this node\'s Wings config.'),
            CheckboxList::make('allowed_modes')
                ->label('Allowed modes')
                ->options(GatewayWidget::MODE_LABELS)
                ->required()
                ->helperText('The modes server owners may choose on this node. Admins can always choose any mode.'),
            CheckboxList::make('domains')
                ->label('Address domains')
                ->options(fn () => $this->domainOptions)
                ->visible(fn () => $this->domainOptions !== [])
                ->helperText(fn () => new HtmlString(e(self::DOMAINS_HELP) . ' <a class="underline" target="_blank" rel="noopener" href="'
                    . e(self::ownDomainsUrl()) . '">Add and verify own domains in the DYLARIS panel</a> (Nodes, Protected addresses, Your own domains).')),
            CheckboxList::make('admin_only')
                ->label('Admins only')
                ->options(fn () => $this->domainOptions)
                ->visible(fn () => $this->domainOptions !== [])
                ->helperText('Ticked domains are offered to panel admins only.'),
        ])->statePath('data');
    }

    public function connectAction(): Action
    {
        return Action::make('connect')
            ->button()
            ->label('Connect node')
            ->icon(TablerIcon::PlugConnected)
            ->visible(fn () => !$this->connected())
            // Before the modal opens, so field errors land on the form rather than on the modal.
            ->mountUsing(fn () => $this->form->validate())
            ->requiresConfirmation()
            ->modalHeading('Connect this node to the gateway?')
            ->modalDescription('The region cannot be changed later without retiring this node\'s link kit. The link key is shown once, right after connecting.')
            ->action(fn () => $this->connect());
    }

    public function saveModesAction(): Action
    {
        return Action::make('saveModes')
            ->button()
            ->label('Save allowed modes')
            ->icon(TablerIcon::DeviceFloppy)
            ->color('gray')
            ->visible(fn () => $this->connected())
            ->action(function () {
                $n = $this->nodeState();
                $this->store()->putNode(new NodeState($n->nodeId, $n->linkId, $n->region, $this->modesFromForm(), $n->targetHost, $n->domains));
                Notification::make()->title('Allowed modes saved.')->success()->send();
            });
    }

    public function saveDomainsAction(): Action
    {
        return Action::make('saveDomains')
            ->button()
            ->label('Save domains')
            ->icon(TablerIcon::World)
            ->color('gray')
            ->visible(fn () => $this->domainOptions !== [])
            ->action(function () {
                $data = $this->form->getState();
                $offered = array_values((array) ($data['domains'] ?? []));
                if ($offered === []) {
                    Notification::make()->title('Offer at least one domain.')->danger()->send();

                    return;
                }
                try {
                    $candidates = $this->candidates();
                } catch (\Throwable $e) {
                    Notification::make()->title(ErrorText::for($e, true))->danger()->send();

                    return;
                }
                $adminOnly = array_values((array) ($data['admin_only'] ?? []));
                $domains = [];
                foreach ($candidates as $c) {
                    if (in_array($c->domain, $offered, true)) {
                        $domains[] = new AllowedDomain($c->domain, $c->custom, in_array($c->domain, $adminOnly, true), $c->validation);
                    }
                }
                $n = $this->nodeState() ?? new NodeState($this->node->id, null, null, Mode::cases(), $data['target_host'] ?? self::DEFAULT_TARGET);
                $this->store()->putNode(new NodeState($n->nodeId, $n->linkId, $n->region, $n->allowedModes, $n->targetHost, $domains));
                GatewayLock::forgetDomains($this->node->id);
                Notification::make()->title('Domains saved. Servers that already have an address keep it.')->success()->send();
            });
    }

    public function disconnectAction(): Action
    {
        return Action::make('disconnect')
            ->button()
            ->label('Disconnect node')
            ->icon(TablerIcon::PlugConnectedX)
            ->color('danger')
            ->visible(fn () => $this->connected())
            ->requiresConfirmation()
            ->modalDescription('This revokes the node\'s link kit at the provider. The link on the node stops working.')
            ->action(fn () => $this->disconnect());
    }

    public function render(): View
    {
        $n = $this->nodeState();

        return view('dylaris-gateway::node-gateway', [
            'state' => $n,
            'servers' => $this->gatewayServers(),
            'snippet' => $this->snippet,
        ]);
    }

    private function connect(): void
    {
        $data = $this->form->getState();
        // A double-click or a second tab must not mint a second kit: one connect per node at a time, re-checked inside.
        // 60 s covers the mint call's 10 s connect and 20 s request timeouts with room to spare.
        $lock = Cache::lock('dylaris-gateway-connect-' . $this->node->id, 60);
        if (!$lock->get()) {
            Notification::make()->title('This node is already being connected.')->danger()->send();

            return;
        }
        try {
            if ($this->connected()) {
                Notification::make()->title('This node is already connected.')->danger()->send();

                return;
            }
            try {
                $kit = app(ProviderClient::class)->mintLinkKit(Str::limit('Pelican: ' . $this->node->name, 64, ''), $data['region']);
            } catch (\Throwable $e) {
                Notification::make()->title(ErrorText::for($e, true))->danger()->send();

                return;
            }
            $this->store()->putNode(new NodeState($this->node->id, $kit->linkId, $data['region'], $this->modesFromForm(), $data['target_host'], $this->nodeState()?->domains));
        } finally {
            $lock->release();
        }
        $this->snippet = self::snippet($kit->linkKey, GatewaySettings::providerUrl(), $data['target_host']);
        $this->kitStatus = 'Link offline';
        Notification::make()->title('Node connected. Start the link with the snippet below; its key is not shown again.')->success()->send();
    }

    private function disconnect(): void
    {
        $servers = $this->gatewayServers();
        if ($servers !== []) {
            Notification::make()
                ->title('Switch these servers to Direct first: ' . implode(', ', array_column($servers, 'name')) . '.')
                ->danger()
                ->send();

            return;
        }
        $n = $this->nodeState();
        try {
            app(ProviderClient::class)->revokeLinkKit((string) $n->linkId);
        } catch (ProviderException $e) {
            // Already gone at the provider: nothing left to revoke, the node is disconnected either way.
            if ($e->status !== 404) {
                Notification::make()->title($e->userMessage)->danger()->send();

                return;
            }
        } catch (\Throwable $e) {
            Notification::make()->title(ErrorText::for($e, true))->danger()->send();

            return;
        }
        $this->store()->putNode(new NodeState($n->nodeId, null, null, $n->allowedModes, $n->targetHost, $n->domains));
        $this->snippet = null;
        $this->kitStatus = null;
        $this->loadProvider();
        $this->form->fill(['region' => null, 'target_host' => $n->targetHost, 'allowed_modes' => array_map(fn (Mode $m) => $m->value, $n->allowedModes)]);
        $this->fillDomains();
        Notification::make()->title('Node disconnected and its link kit revoked.')->success()->send();
    }

    /** Values from the route-only compose file; strings JSON-quoted, which YAML reads as double-quoted. */
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

    private function loadProvider(): void
    {
        $this->problem = null;
        $this->notice = null;
        try {
            $client = app(ProviderClient::class);
            $linkId = $this->nodeState()?->linkId;
            if ($linkId !== null && $linkId !== '') {
                $this->kitStatus = 'The link kit no longer exists at the provider. Disconnect and connect the node again.';
                foreach ($client->listLinkKits() as $kit) {
                    if ($kit->linkId === $linkId) {
                        $this->kitStatus = $kit->online ? 'Link online' : 'Link offline';
                    }
                }

                return;
            }
            $this->regions = [];
            foreach ($client->regions()->items as $r) {
                if ($r->online) {
                    $this->regions[$r->region] = $r->region;
                }
            }
            $this->notice = $this->regions === [] ? self::NO_REGION : null;
        } catch (\Throwable $e) {
            $this->problem = ErrorText::for($e, true);
        }
    }

    /** Where own domains are added and verified: the provider's own panel. */
    public static function ownDomainsUrl(): string
    {
        try {
            return GatewaySettings::providerUrl() . '/nodes?tab=routes';
        } catch (GatewayUnavailable) {
            return GatewaySettings::DEFAULT_URL . '/nodes?tab=routes';
        }
    }

    /**
     * What the admin can offer: the provider's hoster domains, the account's VERIFIED own domains, and whatever is saved
     * on the node but no longer offered by the provider, so it can be unticked.
     *
     * @return AllowedDomain[]
     */
    private function candidates(): array
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
        foreach ($this->nodeState()?->domains ?? [] as $d) {
            $out[$d->domain] ??= $d;
        }

        return array_values($out);
    }

    /** Fills the two domain lists: the saved choice, or every hoster domain while nothing is saved. */
    private function fillDomains(): void
    {
        $this->domainOptions = [];
        try {
            $candidates = $this->candidates();
        } catch (\Throwable $e) {
            $this->problem ??= ErrorText::for($e, true);

            return;
        }
        foreach ($candidates as $c) {
            $this->domainOptions[$c->domain] = $c->domain . ($c->custom ? ' (own domain)' : '');
        }
        $saved = $this->nodeState()?->domains;
        $offered = $saved ?? array_filter($candidates, fn (AllowedDomain $d) => !$d->custom);
        $this->data['domains'] = array_values(array_map(fn (AllowedDomain $d) => $d->domain, $offered));
        $this->data['admin_only'] = array_values(array_map(fn (AllowedDomain $d) => $d->domain, array_filter($saved ?? [], fn (AllowedDomain $d) => $d->adminOnly)));
    }

    /** @return list<array{name: string, mode: string, address: string, error: ?string}> */
    private function gatewayServers(): array
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
        return Server::query()->where('node_id', $this->node->id)->whereIn('id', array_keys($states))->orderBy('name')->get()
            ->map(fn (Server $srv) => [
                'name' => $srv->name,
                'mode' => GatewayWidget::MODE_LABELS[$states[$srv->id]->mode->value],
                'address' => $states[$srv->id]->domain(),
                'error' => $states[$srv->id]->lastError,
            ])->all();
    }

    /** @return Mode[] */
    private function modesFromForm(): array
    {
        $chosen = $this->form->getState()['allowed_modes'] ?? [];

        return array_values(array_filter(Mode::cases(), fn (Mode $m) => in_array($m->value, $chosen, true)));
    }

    private function connected(): bool
    {
        return ($this->nodeState()?->linkId ?? '') !== '';
    }

    private function nodeState(): ?NodeState
    {
        return $this->store()->node($this->node->id);
    }

    private function store(): StateStore
    {
        return app(StateStore::class);
    }

    private function authorizeAccess(): void
    {
        abort_unless(user()?->can('update', $this->node) ?? false, 403);
    }
}
