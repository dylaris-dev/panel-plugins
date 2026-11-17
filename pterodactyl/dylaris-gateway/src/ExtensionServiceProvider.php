<?php

namespace Dylaris\GatewayPtero;

use Dylaris\GatewayPanel\ModeService;
use Dylaris\GatewayPanel\PanelAdapter;
use Dylaris\GatewayPanel\Provider\ProviderClient;
use Dylaris\GatewayPanel\Reconciler;
use Dylaris\GatewayPanel\StateStore;
use Dylaris\GatewayPanel\StatusService;
use Dylaris\GatewayPtero\Console\ReconcileCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Pterodactyl\Events\Server\OperationCompleted;
use Pterodactyl\Extensions\ExtensionProvider;

class ExtensionServiceProvider extends ExtensionProvider
{
    private bool $reconcileQueued = false;

    public function register(): void
    {
        $this->registerCore();
        $this->mergeConfigFrom($this->extensionPath('resources/config/dylaris-gateway.php'), 'dylaris-gateway');

        $this->app->singleton(StateStore::class, PteroStateStore::class);
        $this->app->singleton(PanelAdapter::class, PteroPanelAdapter::class);
        // Not a singleton: a key saved in the settings takes effect on the next resolve.
        $this->app->bind(ProviderClient::class, function () {
            $key = GatewaySettings::apiKey();
            if ($key === null) {
                throw new GatewayUnavailable('The DYLARIS gateway is not set up yet: enter the API key in the extension settings.');
            }

            return new ProviderClient(GatewaySettings::providerUrl(), $key);
        });
        $this->app->bind(ModeService::class, fn (Application $app) => new ModeService(
            $app->make(ProviderClient::class), $app->make(PanelAdapter::class), $app->make(StateStore::class),
        ));
        $this->app->bind(Reconciler::class, fn (Application $app) => new Reconciler(
            $app->make(ProviderClient::class), $app->make(PanelAdapter::class), $app->make(StateStore::class),
        ));
        $this->app->bind(StatusService::class, fn (Application $app) => new StatusService(
            $app->make(ProviderClient::class), $app->make(StateStore::class), $app->make(PanelAdapter::class),
        ));

        $this->registerSettings(GatewaySettings::definition($this->settings()));
        // Become ext.dylaris-gateway.view, .mode and .address (Permissions); owners and root admins hold them implicitly.
        $this->registerPermissions('DYLARIS Gateway', Permissions::keys());
        $this->registerCommands([ReconcileCommand::class]);
        // The command goes through ReconcileRunner, whose lock already keeps two runs apart.
        $this->registerSchedule(fn (Schedule $schedule) => $schedule->command('dylaris-gateway:reconcile')->everyFiveMinutes());
        // An extra trigger on top of the scheduled run. The event carries only uuids, and a full run is one route list read.
        // The panel runs these listeners synchronously in the request (CreateServer, RecordServerInstallResult), so the run
        // waits until the response is sent, and several operations in one request share one run.
        $this->listenToServerOperations(function (OperationCompleted $event) {
            if (!in_array($event->operation, ['provision', 'reinstall'], true) || $this->reconcileQueued) {
                return;
            }
            $this->reconcileQueued = true;
            // Laravel keeps every terminating callback and calls all of them on each terminate(), so one that already ran stays idle.
            $this->app->terminating(function () {
                if (!$this->reconcileQueued) {
                    return;
                }
                $this->reconcileQueued = false;
                ReconcileRunner::run();
            });
        });
    }

    public function boot(): void
    {
        $this->registerApiRoutes();
    }

    /**
     * The shared core ships inside the extension as core-src/ (tools/build-plugins.sh), autoloaded through
     * extension.json; it is not a Composer package.
     */
    private function registerCore(): void
    {
        $dir = $this->extensionPath('core-src');
        if (!is_dir($dir)) {
            throw new \RuntimeException("DYLARIS Gateway: $dir is missing; build the extension with tools/build-plugins.sh.");
        }
        // Several DTO classes share one file, which PSR-4 cannot find.
        require_once $dir . '/Provider/Dto.php';
    }
}
