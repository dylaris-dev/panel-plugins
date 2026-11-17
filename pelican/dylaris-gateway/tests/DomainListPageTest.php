<?php

namespace Dylaris\GatewayPelican\Tests;

use App\Models\Node;
use App\Models\Role;
use App\Models\User;
use Dylaris\GatewayPanel\DomainEntry;
use Dylaris\GatewayPanel\DomainSync;
use Dylaris\GatewayPanel\Mode;
use Dylaris\GatewayPanel\NodeState;
use Dylaris\GatewayPanel\StateStore;
use Dylaris\GatewayPelican\Console\SyncDomainsCommand;
use Dylaris\GatewayPelican\Filament\Admin\Pages\GatewayDomains;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/** 0.2.0: the panel-wide domain list, its sync and the migration from per-node lists. */
class DomainListPageTest extends PluginTestCase
{
    private FakeProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create();
        $admin->syncRoles(Role::getRootAdmin());
        $this->actingAs($admin);
        $this->provider = FakeProvider::bind($this->app);
    }

    private function store(): StateStore
    {
        return $this->app->make(StateStore::class);
    }

    private function migration(): object
    {
        return require __DIR__ . '/../database/migrations/2026_11_17_000002_create_domain_list.php';
    }

    public function test_the_page_is_on_the_admin_panel_only(): void
    {
        $admin = Panel::make()->id('admin');
        (new \Dylaris\GatewayPelican\DylarisGatewayPlugin())->register($admin);
        $this->assertContains(GatewayDomains::class, $admin->getPages());

        $server = Panel::make()->id('server');
        (new \Dylaris\GatewayPelican\DylarisGatewayPlugin())->register($server);
        $this->assertNotContains(GatewayDomains::class, $server->getPages());
    }

    public function test_only_plugin_admins_open_it(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertFalse(GatewayDomains::canAccess());
        Livewire::test(GatewayDomains::class)->assertForbidden();
    }

    public function test_opening_an_empty_list_syncs_it_and_shows_the_region(): void
    {
        Livewire::test(GatewayDomains::class)
            ->assertSee('play.example.net')
            ->assertSee('eu')
            ->assertSee('Official')
            ->assertSee('Last synced with DYLARIS');

        $this->assertSame(['GET regions', 'GET custom-domains'], array_column($this->provider->calls, 'key'));
        $this->assertEquals([new DomainEntry('play.example.net', DomainEntry::OFFICIAL, true, 'alphanumeric', 'eu', lastSeenAt: $this->store()->domains()[0]->lastSeenAt)], $this->store()->domains());
    }

    public function test_the_switch_and_refresh(): void
    {
        $page = Livewire::test(GatewayDomains::class)
            ->callAction('toggle', arguments: ['domain' => 'play.example.net', 'on' => false])
            ->assertNotified('Only admins can pick this domain now. Existing addresses keep working.');
        $this->assertFalse($this->store()->domains()[0]->enabled);

        // The provider drops the domain: Refresh keeps it, marked, and the switch stays.
        $this->provider->on('GET regions', 200, ['hosterDomains' => [], 'items' => []]);
        $page->callAction('refresh')->assertNotified('The domain list is up to date.')->assertSee('No longer offered');
        $this->assertSame([true, false], [$this->store()->domains()[0]->gone, $this->store()->domains()[0]->enabled]);

        $this->provider->on('GET regions', 503, ['error' => 'The provider is down.']);
        $page->callAction('refresh')->assertNotified('The provider is down.');
        Livewire::test(GatewayDomains::class)->assertSee('The last sync with DYLARIS failed')->assertSee('play.example.net');
    }

    public function test_an_own_domain_is_added_shows_its_record_and_is_verified(): void
    {
        $this->provider->on('POST custom-domains', 200, ['domain' => ['domain' => 'own.example.com', 'state' => 'pending', 'txtName' => '_dylaris-verify.own.example.com', 'txtValue' => 'tok-123']]);
        $this->provider->on('POST custom-domains/own.example.com/verify-txt', 409, ['error' => 'The TXT record was not found yet. DNS changes can take a few minutes to publish.']);

        $page = Livewire::test(GatewayDomains::class)
            ->callAction('addOwn', data: ['domain' => 'Own.Example.com'])
            ->assertNotified('Domain added. Publish its TXT record, then verify it.')
            ->assertSee('_dylaris-verify.own.example.com')
            ->assertSee('tok-123')
            ->assertSee('Own domain, not verified yet');
        $this->assertSame([['domain' => 'own.example.com']], $this->provider->bodies('POST custom-domains'));

        $page->callAction('verify', arguments: ['domain' => 'own.example.com'])
            ->assertNotified('The TXT record was not found yet. DNS changes can take a few minutes to publish.');
        $this->assertSame('pending', $this->store()->domains()[1]->ownState);

        $this->provider->on('POST custom-domains/own.example.com/verify-txt', 200, ['success' => true]);
        $page->callAction('verify', arguments: ['domain' => 'own.example.com'])
            ->assertNotified('own.example.com is verified. Users can pick it once it is enabled.')
            ->assertSee('Own domain, verified');

        $this->provider->on('DELETE custom-domains/own.example.com', 200, ['success' => true]);
        $page->callAction('remove', arguments: ['domain' => 'own.example.com'])->assertNotified('Domain removed from the list.');
        $this->assertSame(['play.example.net'], array_map(fn ($e) => $e->domain, $this->store()->domains()));
    }

    public function test_the_sync_command_runs_hourly_and_prints_the_list(): void
    {
        $events = array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            fn ($e) => str_contains((string) $e->command, 'dylaris-gateway:sync-domains'),
        ));
        $this->assertCount(1, $events);
        $this->assertSame('0 * * * *', $events[0]->expression);
        $this->assertSame('dylaris-gateway:sync-domains', (new SyncDomainsCommand())->getName());

        $this->artisan('dylaris-gateway:sync-domains')
            ->expectsOutputToContain('Domains: 1 official, 0 own, 0 no longer offered; 0 admins only.')
            ->assertExitCode(0);

        $this->provider->on('GET regions', 503, ['error' => 'down']);
        $this->artisan('dylaris-gateway:sync-domains')->expectsOutputToContain('Domain sync failed, the stored list stays: down')->assertExitCode(1);
        $this->assertCount(1, $this->store()->domains());
    }

    public function test_the_migration_moves_node_lists_into_the_panel_wide_list(): void
    {
        $a = Node::factory()->create();
        $b = Node::factory()->create();
        $this->store()->putNode(new NodeState($a->id, 'link-1', 'eu', Mode::cases()));
        $this->store()->putNode(new NodeState($b->id, null, null, Mode::cases()));
        $this->migration()->down();
        $this->assertTrue(Schema::hasColumn('dylaris_gateway_nodes', 'domains'));
        DB::table('dylaris_gateway_nodes')->where('node_id', $a->id)->update(['domains' => json_encode([
            ['domain' => 'play.example.net', 'custom' => false, 'admin_only' => false, 'validation' => 'alphanumeric'],
            ['domain' => 'vip.example.com', 'custom' => true, 'admin_only' => true, 'validation' => ''],
        ])]);
        DB::table('dylaris_gateway_nodes')->where('node_id', $b->id)->update(['domains' => json_encode([
            ['domain' => 'vip.example.com', 'custom' => true, 'admin_only' => false, 'validation' => ''],
        ])]);

        $this->migration()->up();

        $this->assertFalse(Schema::hasColumn('dylaris_gateway_nodes', 'domains'));
        $this->assertEquals([
            new DomainEntry('play.example.net', DomainEntry::OFFICIAL, true, 'alphanumeric'),
            new DomainEntry('vip.example.com', DomainEntry::OWN, false),
        ], $this->store()->domains());
        $this->assertEquals(new DomainSync(null, null, null, false), $this->store()->domainSync(), 'every node had a list: new official domains start off');

        // The first use syncs: regions and own states arrive, an official domain nobody ticked starts off.
        $this->provider->on('GET regions', 200, ['hosterDomains' => [['domain' => 'play.example.net', 'validation' => 'alphanumeric'], ['domain' => 'new.example.net', 'validation' => 'dns']], 'items' => [['region' => 'eu', 'wildcard' => '*.play.example.net', 'online' => true]]]);
        $this->provider->on('GET custom-domains', 200, ['items' => [['domain' => 'vip.example.com', 'state' => 'verified']]]);
        $this->artisan('dylaris-gateway:sync-domains')->expectsOutputToContain('Domains: 2 official, 1 own, 0 no longer offered; 2 admins only.');
        $this->assertSame(['new.example.net' => false, 'play.example.net' => true, 'vip.example.com' => false], array_column(array_map(fn ($e) => ['d' => $e->domain, 'on' => $e->enabled], $this->store()->domains()), 'on', 'd'));
    }

    public function test_a_node_without_a_saved_list_enables_every_official_domain(): void
    {
        $a = Node::factory()->create();
        Node::factory()->create(); // never opened its Gateway tab: no row at all
        $this->store()->putNode(new NodeState($a->id, 'link-1', 'eu', Mode::cases()));
        $this->migration()->down();
        DB::table('dylaris_gateway_nodes')->update(['domains' => json_encode([['domain' => 'play.example.net', 'custom' => false, 'admin_only' => true, 'validation' => 'dns']])]);

        $this->migration()->up();

        $this->assertTrue($this->store()->domains()[0]->enabled);
        $this->assertTrue($this->store()->domainSync()->newOfficialEnabled);
    }

    public function test_down_writes_the_list_back_to_every_node(): void
    {
        $a = Node::factory()->create();
        $this->store()->putNode(new NodeState($a->id, 'link-1', 'eu', Mode::cases()));
        $this->store()->putDomains([
            new DomainEntry('play.example.net', DomainEntry::OFFICIAL, true, 'alphanumeric', 'eu'),
            new DomainEntry('old.example.net', DomainEntry::OFFICIAL, true, 'dns', gone: true),
            new DomainEntry('vip.example.com', DomainEntry::OWN, false, ownState: 'verified'),
        ]);

        $this->migration()->down();

        $this->assertSame([
            ['domain' => 'play.example.net', 'custom' => false, 'admin_only' => false, 'validation' => 'alphanumeric'],
            ['domain' => 'vip.example.com', 'custom' => true, 'admin_only' => true, 'validation' => ''],
        ], json_decode(DB::table('dylaris_gateway_nodes')->where('node_id', $a->id)->value('domains'), true));
        $this->assertFalse(Schema::hasTable('dylaris_gateway_domains'));
        $this->migration()->up();
    }
}
