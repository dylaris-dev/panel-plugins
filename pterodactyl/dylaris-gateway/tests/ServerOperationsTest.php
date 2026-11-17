<?php

namespace Dylaris\GatewayPteroTests;

use Dylaris\GatewayPanel\Mode;
use Dylaris\GatewayPanel\Provider\ProviderClient;
use Dylaris\GatewayPanel\ReconcileReport;
use Dylaris\GatewayPanel\ServerState;
use Dylaris\GatewayPanel\StateStore;
use Dylaris\GatewayPtero\ReconcileRunner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Events\Server\OperationCompleted;
use Pterodactyl\Models\Extension;

class ServerOperationsTest extends ExtensionTestCase
{
    private const RUN = ['GET routes', 'GET link-kits'];

    private function operation(string $operation): void
    {
        Event::dispatch(new OperationCompleted('8f3c5f2e-0000-4000-8000-000000000001', $operation, true, '8f3c5f2e-0000-4000-8000-000000000001'));
    }

    private function putStates(): void
    {
        // Servers the event does not name: the run is a full one, not one for the operated server.
        $store = $this->app->make(StateStore::class);
        $store->put(new ServerState(41, Mode::Both, 'a', 'play.example.net', 1, 25565));
        $store->put(new ServerState(42, Mode::Both, 'b', 'play.example.net', 1, 25566));
    }

    public function test_provision_and_reinstall_reconcile_every_server_after_the_response(): void
    {
        $provider = RecordingProvider::bind($this->app);
        $this->putStates();

        foreach (['install', 'backup', 'delete', 'suspend', 'unsuspend', 'transfer'] as $other) {
            $this->operation($other);
        }
        $this->app->terminate();
        $this->assertSame([], $provider->calls, 'only provision and reinstall trigger a reconcile');

        $this->operation('provision');
        $this->assertSame([], $provider->calls, 'the run waits until the response is sent');
        $this->app->terminate();
        $this->assertSame(self::RUN, array_slice($provider->calls, 0, 2));

        $provider->calls = [];
        $this->putStates();
        $this->operation('reinstall');
        $this->app->terminate();
        $this->assertSame(self::RUN, array_slice($provider->calls, 0, 2));
    }

    public function test_several_operations_in_one_request_share_one_run(): void
    {
        $provider = RecordingProvider::bind($this->app);
        $this->putStates();

        $this->operation('provision');
        $this->operation('provision');
        $this->operation('reinstall');
        $this->app->terminate();

        $this->assertSame(1, count(array_keys($provider->calls, 'GET routes', true)));
    }

    public function test_a_run_is_skipped_while_another_holds_the_lock(): void
    {
        $provider = RecordingProvider::bind($this->app);
        $this->putStates();
        $held = Cache::lock(ReconcileRunner::LOCK, 600);
        $this->assertTrue($held->get());
        Log::spy();

        $this->operation('provision');
        $this->app->terminate();

        $this->assertSame([], $provider->calls);
        Log::shouldHaveReceived('info')->once()->withArgs(fn (string $m) => $m === 'DYLARIS gateway reconcile skipped: another run is in progress.');

        $held->release();
        $this->assertInstanceOf(ReconcileReport::class, ReconcileRunner::run(), 'the lock is free again after a finished run');
        $this->assertSame(self::RUN, array_slice($provider->calls, 0, 2));
        $this->assertTrue(Cache::lock(ReconcileRunner::LOCK, 600)->get(), 'a run releases the lock');
    }

    public function test_a_failing_reconcile_is_logged_and_never_thrown(): void
    {
        $this->putStates();
        $this->app->bind(ProviderClient::class, fn () => throw new \RuntimeException('provider exploded'));
        Log::spy();

        $this->operation('provision');
        $this->app->terminate();

        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message) => $message === 'DYLARIS gateway reconcile failed: provider exploded');
        $this->assertNull(Extension::query()->where('identifier', self::ID)->value('error'), 'the panel did not record the extension as failed');
        $this->assertTrue(Cache::lock(ReconcileRunner::LOCK, 600)->get(), 'a failed run releases the lock');
    }

    public function test_an_unconfigured_gateway_stays_quiet(): void
    {
        $this->putStates();
        Log::spy();

        $this->operation('provision');
        $this->app->terminate();

        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
    }
}
