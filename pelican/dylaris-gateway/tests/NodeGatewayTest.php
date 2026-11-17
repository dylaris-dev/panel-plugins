<?php

namespace Dylaris\GatewayPelican\Tests;

use Illuminate\Support\Facades\Cache;
use App\Enums\TablerIcon;
use App\Filament\Admin\Resources\Nodes\Pages\EditNode;
use App\Models\Node;
use App\Models\Role;
use App\Models\User;
use Dylaris\GatewayPanel\Mode;
use Dylaris\GatewayPanel\NodeState;
use Dylaris\GatewayPanel\ServerState;
use Dylaris\GatewayPanel\StateStore;
use Dylaris\GatewayPelican\Livewire\NodeGateway;
use Dylaris\GatewayPelican\Providers\DylarisGatewayServiceProvider;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

class NodeGatewayTest extends PluginTestCase
{
    private const LINK_KEY = 'lk_0123456789abcdef0123456789abcdef';

    private FakeProvider $provider;

    private Node $node;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create();
        $admin->syncRoles(Role::getRootAdmin());
        $this->actingAs($admin);
        $this->provider = FakeProvider::bind($this->app);
        $this->node = Node::factory()->create(['name' => 'node-a']);
    }

    protected function tearDown(): void
    {
        Filament::setCurrentPanel(null);
        parent::tearDown();
    }

    private function store(): StateStore
    {
        return $this->app->make(StateStore::class);
    }

    private function connected(string $linkId = 'link-1'): void
    {
        $this->store()->putNode(new NodeState($this->node->id, $linkId, 'eu', Mode::cases(), '172.18.0.1'));
    }

    public function test_the_node_edit_page_has_a_gateway_tab(): void
    {
        $tabs = (fn () => $this->getTabs())->call(new EditNode());

        $this->assertContains('Gateway', array_map(fn ($t) => $t->getLabel(), $tabs));
        $this->assertCount(1, array_filter($tabs, fn ($t) => $t->getLabel() === 'Gateway'), 'one tab, however often the provider boots');

        Livewire::test(EditNode::class, ['record' => $this->node->getKey()])->assertOk()->assertSee('Gateway');
    }

    public function test_a_user_who_cannot_edit_the_node_is_refused(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(NodeGateway::class, ['node' => $this->node])->assertForbidden();
    }

    public function test_connect_mints_a_kit_and_shows_the_key_once(): void
    {
        $this->provider->on('POST link-kits', 200, ['link_id' => 'link-new', 'link_key' => self::LINK_KEY]);

        Livewire::test(NodeGateway::class, ['node' => $this->node])
            ->assertSee('Changing the region later means retiring this node')
            ->fillForm(['region' => 'us', 'target_host' => '172.20.0.1', 'allowed_modes' => ['direct', 'both']])
            ->callAction('connect')
            ->assertHasNoFormErrors()
            ->assertSee(self::LINK_KEY)
            ->assertSee('image: ghcr.io/dylaris-dev/gateway-link:latest')
            ->assertSee('network_mode: host')
            ->assertSee('restart: unless-stopped')
            ->assertSee('stop_grace_period: 6h10m')
            ->assertSee('CORE_URL: &quot;https://panel.dylaris.com&quot;', false)
            ->assertSee('LINK_DRAIN_TIMEOUT: &quot;6h&quot;', false)
            ->assertSee('LINK_ALLOWED_TARGETS: &quot;172.20.0.1&quot;', false)
            ->assertSee('LOCAL_HOST: &quot;172.20.0.1&quot;', false);

        $this->assertSame([['name' => 'Pelican: node-a', 'region' => 'us']], $this->provider->bodies('POST link-kits'));
        $n = $this->store()->node($this->node->id);
        $this->assertSame(['link-new', 'us', '172.20.0.1', [Mode::Direct, Mode::Both]], [$n->linkId, $n->region, $n->targetHost, $n->allowedModes]);
        foreach (['dylaris_gateway_nodes', 'dylaris_gateway_servers', 'dylaris_gateway_settings'] as $table) {
            $this->assertStringNotContainsString(self::LINK_KEY, json_encode(DB::table($table)->get()), "$table holds the link key");
        }

        // The next visit shows the connection, never the key.
        Livewire::test(NodeGateway::class, ['node' => $this->node])
            ->assertDontSee(self::LINK_KEY)
            ->assertSee('link-new');
    }

    public function test_a_failed_mint_shows_the_provider_sentence_and_stores_nothing(): void
    {
        $this->provider->on('POST link-kits', 429, ['error' => 'Too many link kits minted this hour.', 'code' => 'rate_limited']);

        Livewire::test(NodeGateway::class, ['node' => $this->node])
            ->fillForm(['region' => 'eu'])
            ->callAction('connect')
            ->assertNotified('Too many link kits minted this hour.');

        $this->assertNull($this->store()->node($this->node->id)?->linkId);
    }

    public function test_the_interface_ip_must_be_an_ip(): void
    {
        Livewire::test(NodeGateway::class, ['node' => $this->node])
            ->assertFormSet(['target_host' => '172.18.0.1', 'allowed_modes' => ['direct', 'both', 'gateway_only']])
            ->fillForm(['region' => 'eu', 'target_host' => 'not-an-ip'])
            ->callAction('connect')
            ->assertHasFormErrors(['target_host']);

        $this->assertSame([], $this->provider->bodies('POST link-kits'));
    }

    public function test_disconnect_revokes_the_kit(): void
    {
        $this->connected();

        Livewire::test(NodeGateway::class, ['node' => $this->node])->callAction('disconnect');

        $this->assertCount(1, $this->provider->bodies('DELETE link-kits/link-1'));
        $this->assertNull($this->store()->node($this->node->id)->linkId);
    }

    public function test_disconnect_is_refused_while_servers_use_the_gateway(): void
    {
        $this->connected();
        $server = $this->createServerModel(['node_id' => $this->node->id]);
        $this->store()->put(new ServerState($server->id, Mode::Both, 'mc', 'play.example.net', $this->node->id, 25565));

        Livewire::test(NodeGateway::class, ['node' => $this->node])
            ->assertSee($server->name)
            ->assertSee('mc.play.example.net')
            ->callAction('disconnect')
            ->assertNotified('Switch these servers to Direct first: ' . $server->name . '.');

        $this->assertSame([], $this->provider->bodies('DELETE link-kits/link-1'));
        $this->assertSame('link-1', $this->store()->node($this->node->id)->linkId);
    }

    public function test_kit_online_status_comes_from_the_provider(): void
    {
        $this->connected();
        $this->provider->on('GET link-kits', 200, ['items' => [['link_id' => 'link-1', 'online' => true]]]);
        Livewire::test(NodeGateway::class, ['node' => $this->node])->assertSee('Link online');

        $this->provider->on('GET link-kits', 200, ['items' => [['link_id' => 'link-1', 'online' => false]]]);
        Livewire::test(NodeGateway::class, ['node' => $this->node])->assertSee('Link offline');
    }

    public function test_allowed_modes_can_change_while_connected(): void
    {
        $this->connected();

        Livewire::test(NodeGateway::class, ['node' => $this->node])
            ->fillForm(['allowed_modes' => ['gateway_only']])
            ->callAction('saveModes')
            ->assertHasNoFormErrors();

        $n = $this->store()->node($this->node->id);
        $this->assertSame(['link-1', [Mode::GatewayOnly]], [$n->linkId, $n->allowedModes]);
    }

    /** Pelican renders actions as icon buttons by default (ButtonStyle), so an action without an icon is invisible. */
    public function test_only_the_actions_for_the_connection_state_are_shown(): void
    {
        Livewire::test(NodeGateway::class, ['node' => $this->node])
            ->assertSeeHtml("mountAction('connect')")
            ->assertDontSeeHtml("mountAction('saveModes')")
            ->assertDontSeeHtml("mountAction('disconnect')");

        $this->connected();
        Livewire::test(NodeGateway::class, ['node' => $this->node])
            ->assertDontSeeHtml("mountAction('connect')")
            ->assertSeeHtml("mountAction('saveModes')")
            ->assertSeeHtml("mountAction('disconnect')");
    }

    public function test_every_action_has_an_icon(): void
    {
        Livewire::test(NodeGateway::class, ['node' => $this->node])->assertActionHasIcon('connect', TablerIcon::PlugConnected);

        $this->connected();
        Livewire::test(NodeGateway::class, ['node' => $this->node])
            ->assertActionHasIcon('saveModes', TablerIcon::DeviceFloppy)
            ->assertActionHasIcon('disconnect', TablerIcon::PlugConnectedX);
    }

    public function test_an_unconfigured_plugin_says_so_on_the_page(): void
    {
        // Back to the real binding, with no settings saved.
        (new DylarisGatewayServiceProvider($this->app))->register();
        DB::table('dylaris_gateway_settings')->delete();

        Livewire::test(NodeGateway::class, ['node' => $this->node])
            ->assertSee('The DYLARIS gateway is not set up yet');
    }

    /** The link key must not ride along in the Livewire snapshot of every later request. */
    public function test_the_link_key_is_not_kept_in_the_component_state(): void
    {
        $this->provider->on('POST link-kits', 200, ['link_id' => 'link-new', 'link_key' => self::LINK_KEY]);

        $c = Livewire::test(NodeGateway::class, ['node' => $this->node])
            ->fillForm(['region' => 'eu'])
            ->callAction('connect')
            ->assertSee(self::LINK_KEY);

        $this->assertStringNotContainsString(self::LINK_KEY, json_encode($c->getData()));
        $c->fillForm(['allowed_modes' => ['direct']])->callAction('saveModes')->assertDontSee(self::LINK_KEY);
    }

    public function test_no_online_region_is_said(): void
    {
        $this->provider->on('GET regions', 200, ['hosterDomains' => [], 'items' => [['region' => 'eu', 'wildcard' => '*.x', 'online' => false]]]);

        Livewire::test(NodeGateway::class, ['node' => $this->node])
            ->assertSee(NodeGateway::NO_REGION);
        $this->assertSame('No gateway region is online right now, so this node cannot be connected yet. Try again later.', NodeGateway::NO_REGION);
    }

    /** A double-click or a second tab must not mint a second kit. */
    public function test_a_second_connect_mints_no_second_kit(): void
    {
        $this->provider->on('POST link-kits', 200, ['link_id' => 'link-new', 'link_key' => self::LINK_KEY]);
        $page = Livewire::test(NodeGateway::class, ['node' => $this->node])->fillForm(['region' => 'eu']);
        $lock = Cache::lock('dylaris-gateway-connect-' . $this->node->id, 60);
        $this->assertTrue($lock->get());
        $page->callAction('connect')->assertNotified('This node is already being connected.');
        $lock->release();

        $this->assertSame([], $this->provider->bodies('POST link-kits'));
        $this->assertNull($this->store()->node($this->node->id)?->linkId);
    }

    /** 0.2.0: the per-node domain list is gone; the tab points to the panel-wide one. */
    public function test_the_tab_has_no_domain_list_and_points_to_the_panel_wide_one(): void
    {
        $this->connected();

        Livewire::test(NodeGateway::class, ['node' => $this->node])
            ->assertSee('under Gateway domains')
            ->assertDontSee('Address domains')
            ->assertActionDoesNotExist('saveDomains');
    }
}
