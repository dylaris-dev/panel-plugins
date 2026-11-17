<?php

namespace Dylaris\GatewayPteroTests;

use Dylaris\GatewayPanel\Mode;
use Dylaris\GatewayPanel\ServerState;
use Dylaris\GatewayPanel\StateStore;
use Dylaris\GatewayPtero\ReconcileRunner;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Services\Extensions\ExtensionConsoleRegistry;

class ReconcileCommandTest extends ExtensionTestCase
{
    public function test_it_is_scheduled_every_five_minutes(): void
    {
        // What the panel does when artisan starts: it hands the scheduler to every available extension.
        $schedule = new Schedule();
        $this->app->make(ExtensionConsoleRegistry::class)->schedule($schedule);
        $events = array_values(array_filter(
            $schedule->events(),
            fn ($e) => str_contains((string) $e->command, 'dylaris-gateway:reconcile'),
        ));

        $this->assertCount(1, $events);
        $this->assertSame('*/5 * * * *', $events[0]->expression);
    }

    public function test_the_command_runs_through_the_reconcile_runner_and_its_lock(): void
    {
        $provider = RecordingProvider::bind($this->app);
        $this->app->make(StateStore::class)->put(new ServerState(41, Mode::Both, 'a', 'play.example.net', 1, 25565));

        // The runner's lock is held: the command must skip, which only ReconcileRunner::run() does.
        $held = Cache::lock(ReconcileRunner::LOCK, 600);
        $this->assertTrue($held->get());
        Log::spy();
        $this->artisan('dylaris-gateway:reconcile')->expectsOutput('Reconcile skipped: another run is in progress.')->assertExitCode(0);
        $this->assertSame([], $provider->calls);
        Log::shouldHaveReceived('info')->once()->withArgs(fn (string $m) => $m === 'DYLARIS gateway reconcile skipped: another run is in progress.');

        $held->release();
        // The summary, the same as Pelican's command prints.
        $this->artisan('dylaris-gateway:reconcile')->expectsOutputToContain('Routes: ')->assertExitCode(0);
        $this->assertSame(['GET routes', 'GET link-kits'], array_slice($provider->calls, 0, 2));
        $this->assertTrue(Cache::lock(ReconcileRunner::LOCK, 600)->get(), 'the run released the lock');
    }

    /** A failed run fails the command, like Pelican's; not being set up yet is not a failure. */
    public function test_a_failed_run_exits_non_zero(): void
    {
        $this->artisan('dylaris-gateway:reconcile')->expectsOutputToContain('not set up yet')->assertExitCode(0);

        $provider = FakeProvider::bind($this->app);
        $provider->on('GET routes', 500, ['error' => 'Internal error.', 'code' => 'internal']);
        $this->app->make(StateStore::class)->put(new ServerState(41, Mode::Both, 'a', 'play.example.net', 1, 25565));
        Log::spy();

        $this->artisan('dylaris-gateway:reconcile')->expectsOutputToContain('Internal error.')->assertExitCode(1);
    }
}
