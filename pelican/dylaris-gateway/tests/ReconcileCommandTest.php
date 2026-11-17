<?php

namespace Dylaris\GatewayPelican\Tests;

use Illuminate\Support\Facades\Cache;
use Dylaris\GatewayPanel\Mode;
use Dylaris\GatewayPanel\ServerState;
use Dylaris\GatewayPanel\StateStore;
use Dylaris\GatewayPelican\Console\ReconcileCommand;
use Dylaris\GatewayPelican\DylarisGatewayPlugin;
use Dylaris\GatewayPelican\GatewaySettings;
use Dylaris\GatewayPelican\Providers\DylarisGatewayServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReconcileCommandTest extends PluginTestCase
{
    public function test_it_runs_every_five_minutes(): void
    {
        $events = array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            fn ($e) => str_contains((string) $e->command, 'dylaris-gateway:reconcile'),
        ));

        $this->assertCount(1, $events);
        $this->assertSame('*/5 * * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);
        $this->assertSame(10, $events[0]->expiresAt);
        $this->assertSame('dylaris-gateway:reconcile', (new ReconcileCommand())->getName());
    }

    public function test_it_runs_the_reconciler(): void
    {
        $provider = FakeProvider::bind($this->app);
        $provider->on('GET link-kits', 200, ['items' => [['link_id' => 'link-1', 'online' => true]]]);
        // A deleted server: the reconcile removes its route and its row.
        $this->app->make(StateStore::class)->put(new ServerState(9999, Mode::Both, 'gone', 'play.example.net', 1, 25565));
        $provider->on('GET routes', 200, ['items' => [['domain' => 'gone.play.example.net', 'link_id' => 'link-1', 'target_ip' => '172.18.0.1', 'target_port' => 25565]]]);
        $provider->on('DELETE routes/gone.play.example.net', 200, ['success' => true]);

        $this->artisan('dylaris-gateway:reconcile')
            ->expectsOutputToContain('Routes: 0 created, 1 deleted, 0 retargeted. Errors: 0.')
            ->expectsOutputToContain('Server 9999 was deleted in the panel')
            ->assertExitCode(0);

        $this->assertCount(1, $provider->bodies('DELETE routes/gone.play.example.net'));
        $this->assertNull($this->app->make(StateStore::class)->get(9999));
    }

    public function test_a_failing_run_is_logged_not_thrown(): void
    {
        $provider = FakeProvider::bind($this->app);
        $this->app->make(StateStore::class)->put(new ServerState(9999, Mode::Both, 'x', 'play.example.net', 1, 25565));
        $provider->on('GET routes', 500, ['error' => 'Internal error.', 'code' => 'internal']);
        Log::shouldReceive('error')->once()->withArgs(fn ($msg) => str_contains($msg, 'Internal error.'));

        $this->artisan('dylaris-gateway:reconcile')
            ->expectsOutputToContain('Internal error.')
            ->assertExitCode(1);
    }

    public function test_without_settings_it_does_nothing_and_logs_nothing(): void
    {
        (new DylarisGatewayServiceProvider($this->app))->register();
        Log::spy();

        $this->artisan('dylaris-gateway:reconcile')
            ->expectsOutputToContain('not set up yet')
            ->assertExitCode(0);

        foreach (['error', 'warning', 'info'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
    }

    public function test_an_unreadable_key_is_logged(): void
    {
        (new DylarisGatewayPlugin())->saveSettings(['provider_url' => FakeProvider::URL, 'api_key' => 'dgk_test_api_key']);
        // What a changed APP_KEY looks like from here.
        DB::table('dylaris_gateway_settings')->update(['api_key' => Crypt::encryptString('x') . 'tampered']);
        Log::spy();

        $this->artisan('dylaris-gateway:reconcile')->assertExitCode(0);

        Log::shouldHaveReceived('warning')->once()->with('DYLARIS gateway reconcile skipped: ' . GatewaySettings::KEY_UNREADABLE);
    }

    public function test_a_run_stopped_by_retry_after_is_logged(): void
    {
        $provider = FakeProvider::bind($this->app);
        $this->app->make(StateStore::class)->put(new ServerState(9999, Mode::Both, 'x', 'play.example.net', 1, 25565));
        $provider->on('GET routes', 429, ['error' => 'Too many requests.', 'code' => 'rate_limited'], ['Retry-After' => '30']);
        Log::spy();

        $this->artisan('dylaris-gateway:reconcile')->assertExitCode(0);

        Log::shouldHaveReceived('info')->once()->with('DYLARIS gateway reconcile: the provider asked to wait 30 s; the run stopped.');
    }

    /** The scheduled run and a mode change share one lock. */
    public function test_a_run_while_a_change_holds_the_lock_is_skipped(): void
    {
        $provider = FakeProvider::bind($this->app);
        $this->app->make(StateStore::class)->put(new ServerState(9999, Mode::Both, 'gone', 'play.example.net', 1, 25565));
        $lock = Cache::lock('dylaris-gateway-reconcile', 600);
        $this->assertTrue($lock->get());

        $this->artisan('dylaris-gateway:reconcile')
            ->expectsOutputToContain('Reconcile skipped: another run is in progress.')
            ->assertExitCode(0);

        $this->assertSame([], array_filter($provider->calls, fn ($c) => $c['key'] === 'GET routes'));
        $lock->release();
    }
}
