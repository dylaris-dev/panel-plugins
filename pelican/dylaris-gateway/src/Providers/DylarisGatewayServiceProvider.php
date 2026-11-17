<?php

namespace Dylaris\GatewayPelican\Providers;

use App\Enums\TabPosition;
use App\Filament\Admin\Resources\Nodes\Pages\EditNode;
use App\Models\Node;
use Composer\Autoload\ClassLoader;
use Dylaris\GatewayPanel\DomainList;
use Dylaris\GatewayPanel\ModeService;
use Dylaris\GatewayPanel\PanelAdapter;
use Dylaris\GatewayPanel\Provider\ProviderClient;
use Dylaris\GatewayPanel\Reconciler;
use Dylaris\GatewayPanel\StateStore;
use Dylaris\GatewayPanel\StatusService;
use Dylaris\GatewayPelican\Console\ReconcileCommand;
use Dylaris\GatewayPelican\Console\SyncDomainsCommand;
use Dylaris\GatewayPelican\EloquentStateStore;
use Dylaris\GatewayPelican\Filament\Server\Widgets\GatewayWidget;
use Dylaris\GatewayPelican\GatewaySettings;
use Dylaris\GatewayPelican\GatewayUnavailable;
use Dylaris\GatewayPelican\Livewire\NodeGateway;
use Dylaris\GatewayPelican\PelicanPanelAdapter;
use Dylaris\GatewayPelican\Permissions;
use Filament\Schemas\Components\Livewire as LivewireComponent;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class DylarisGatewayServiceProvider extends ServiceProvider
{
    private static bool $tabRegistered = false;

    public function register(): void
    {
        $this->registerCore();
        $this->mergeConfigFrom(dirname(__DIR__, 2) . '/config/dylaris-gateway.php', 'dylaris-gateway');

        $this->app->singleton(StateStore::class, EloquentStateStore::class);
        $this->app->singleton(PanelAdapter::class, PelicanPanelAdapter::class);
        // Not a singleton: a key saved in the settings takes effect on the next resolve.
        $this->app->bind(ProviderClient::class, function () {
            $s = GatewaySettings::current();
            if (!$s->exists || !$s->hasKey()) {
                throw new GatewayUnavailable('The DYLARIS gateway is not set up yet: enter the API key in the plugin settings.');
            }

            return new ProviderClient(GatewaySettings::providerUrl(), (string) $s->apiKey());
        });
        $this->app->bind(ModeService::class, fn (Application $app) => new ModeService(
            $app->make(ProviderClient::class), $app->make(PanelAdapter::class), $app->make(StateStore::class),
        ));
        $this->app->bind(Reconciler::class, fn (Application $app) => new Reconciler(
            $app->make(ProviderClient::class), $app->make(PanelAdapter::class), $app->make(StateStore::class),
        ));
        $this->app->bind(DomainList::class, fn (Application $app) => new DomainList(
            $app->make(ProviderClient::class), $app->make(StateStore::class),
        ));
        $this->app->bind(StatusService::class, fn (Application $app) => new StatusService(
            $app->make(ProviderClient::class), $app->make(StateStore::class), $app->make(PanelAdapter::class),
        ));
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        // Pelican adds these namespaces for an enabled plugin too; here they also exist under test.
        $this->loadViewsFrom($root . '/resources/views', 'dylaris-gateway');
        $this->loadTranslationsFrom($root . '/lang', 'dylaris-gateway');
        Livewire::component('dylaris-gateway-node', NodeGateway::class);
        Livewire::component('dylaris-gateway-widget', GatewayWidget::class);

        Permissions::register();
        // The tab registry is a static list that appends; another boot in the same process must not add a second tab.
        if (!self::$tabRegistered) {
            self::$tabRegistered = true;
            EditNode::registerCustomTabs(TabPosition::After, Tab::make('gateway')
                ->label('Gateway')
                ->columnSpanFull()
                // Lazy: the tab asks the provider, which must not slow down or break the node page.
                ->schema([LivewireComponent::make(NodeGateway::class, fn (Node $record) => ['node' => $record])->lazyLoaded()->columnSpanFull()]));
        }

        $this->commands([ReconcileCommand::class, SyncDomainsCommand::class]);
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // The lock expires after 10 minutes, not the default 24 h, so a crashed run cannot stop reconciling for a day.
            $schedule->command(ReconcileCommand::class)->everyFiveMinutes()->withoutOverlapping(10);
            $schedule->command(SyncDomainsCommand::class)->hourly()->withoutOverlapping(10);
        });
    }

    /** The shared core ships inside the plugin as core-src/ (tools/build-plugins.sh); it is not a Composer package. */
    private function registerCore(): void
    {
        $dir = dirname(__DIR__, 2) . '/core-src/';
        if (!is_dir($dir)) {
            throw new \RuntimeException("DYLARIS Gateway: $dir is missing; build the plugin with tools/build-plugins.sh.");
        }
        /** @var ClassLoader $loader */
        $loader = File::getRequire(base_path('vendor/autoload.php'));
        if (!array_key_exists('Dylaris\\GatewayPanel\\', $loader->getPrefixesPsr4())) {
            $loader->addPsr4('Dylaris\\GatewayPanel\\', $dir);
        }
        // Several DTO classes share one file, which PSR-4 cannot find.
        require_once $dir . 'Provider/Dto.php';
    }
}
