<?php

namespace Dylaris\GatewayPteroTests;

use Illuminate\Support\Facades\Cache;
use Dylaris\GatewayPanel\Mode;
use Dylaris\GatewayPanel\NodeState;
use Dylaris\GatewayPanel\Provider\ProviderClient;
use Dylaris\GatewayPanel\ServerState;
use Dylaris\GatewayPanel\StateStore;
use Dylaris\GatewayPtero\GatewaySettings;
use Dylaris\GatewayPtero\GatewayUnavailable;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\User;

class NodeGatewayRoutesTest extends ExtensionTestCase
{
    private const LINK_KEY = 'dlk_link_key_shown_once_0123456789';

    private FakeProvider $provider;

    private Node $node;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = FakeProvider::bind($this->app);
        $this->node = Node::factory()->for(Location::factory())->create(['name' => 'node-a']);
    }

    private function url(string $path = ''): string
    {
        return '/api/admin/extensions/dylaris-gateway/nodes/' . $this->node->id . $path;
    }

    /** One user per test: the panel refuses a second actingAs() user in the same test. */
    private function asAdmin(): static
    {
        return $this->actingAs(User::factory()->create(['root_admin' => true]));
    }

    private function store(): StateStore
    {
        return $this->app->make(StateStore::class);
    }

    private function connected(): void
    {
        $this->store()->putNode(new NodeState($this->node->id, 'link-1', 'eu', Mode::cases(), '172.18.0.1'));
    }

    public function test_a_user_who_is_not_a_root_admin_is_refused(): void
    {
        $this->actingAs(User::factory()->create(['root_admin' => false]));

        $this->getJson($this->url())->assertForbidden();
        $this->postJson($this->url('/connect'), ['region' => 'eu', 'target_host' => '172.18.0.1', 'allowed_modes' => ['direct']])->assertForbidden();
        $this->postJson($this->url('/disconnect'))->assertForbidden();
        $this->putJson($this->url('/modes'), ['allowed_modes' => ['direct']])->assertForbidden();

        $this->assertSame([], $this->provider->calls);
        $this->assertNull($this->store()->node($this->node->id));
    }

    public function test_connect_mints_a_kit_and_returns_the_key_exactly_once(): void
    {
        $this->provider->on('POST link-kits', 200, ['link_id' => 'link-new', 'link_key' => self::LINK_KEY]);
        $this->asAdmin();

        $connect = $this->postJson($this->url('/connect'), ['region' => 'us', 'target_host' => '172.20.0.1', 'allowed_modes' => ['direct', 'both']])
            ->assertOk()
            ->assertJsonPath('message', 'Node connected. Start the link with the snippet below; its key is not shown again.');

        // The Pelican plugin's snippet, value for value.
        $this->assertSame(implode("\n", [
            'services:',
            '  link:',
            '    image: ghcr.io/dylaris-dev/gateway-link:latest',
            '    network_mode: host',
            '    restart: unless-stopped',
            '    stop_grace_period: 6h10m',
            '    mem_limit: 4g',
            '    environment:',
            '      LINK_KEY: "' . self::LINK_KEY . '"',
            '      CORE_URL: "https://panel.dylaris.com"',
            '      LINK_DRAIN_TIMEOUT: "6h"',
            '      LINK_ALLOWED_TARGETS: "172.20.0.1"',
            '      LOCAL_HOST: "172.20.0.1"',
            '      # Host networking: keep the link\'s health endpoint off the network.',
            '      LINK_PORT: "127.0.0.1:25540"',
            '      GOMEMLIMIT: "3400MiB"',
        ]), $connect->json('data.snippet'));
        $this->assertSame([['name' => 'Pterodactyl: node-a', 'region' => 'us']], $this->provider->bodies('POST link-kits'));

        $n = $this->store()->node($this->node->id);
        $this->assertSame(['link-new', 'us', '172.20.0.1', [Mode::Direct, Mode::Both]], [$n->linkId, $n->region, $n->targetHost, $n->allowedModes]);
        foreach (['dylaris_gateway_nodes', 'dylaris_gateway_servers', 'extension_settings'] as $table) {
            $this->assertStringNotContainsString(self::LINK_KEY, json_encode(DB::table($table)->get()), "$table holds the link key");
        }

        // Read again: connected, and the key is gone for good.
        $show = $this->getJson($this->url())->assertOk()->assertJsonPath('data.connected', true)->assertJsonPath('data.link_id', 'link-new');
        $this->assertStringNotContainsString(self::LINK_KEY, $show->getContent());

        // A second Connect (double-click) mints nothing and says why.
        $this->postJson($this->url('/connect'), ['region' => 'us', 'target_host' => '172.20.0.1', 'allowed_modes' => ['direct']])
            ->assertStatus(409)
            ->assertJsonPath('error', 'This node is already connected.');
        $this->assertCount(1, $this->provider->bodies('POST link-kits'));
    }

    public function test_a_failed_mint_returns_the_provider_sentence_and_stores_nothing(): void
    {
        $this->provider->on('POST link-kits', 429, ['error' => 'Too many link kits minted this hour.', 'code' => 'rate_limited']);
        $this->asAdmin();

        $this->postJson($this->url('/connect'), ['region' => 'eu', 'target_host' => '172.18.0.1', 'allowed_modes' => ['direct']])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Too many link kits minted this hour.');

        $this->assertNull($this->store()->node($this->node->id));
    }

    public function test_the_interface_ip_must_be_an_ip_and_a_mode_must_be_chosen(): void
    {
        $this->asAdmin();

        $this->postJson($this->url('/connect'), ['region' => 'eu', 'target_host' => 'not-an-ip', 'allowed_modes' => ['direct']])->assertStatus(422);
        $this->postJson($this->url('/connect'), ['region' => 'eu', 'target_host' => '172.18.0.1', 'allowed_modes' => []])->assertStatus(422);
        $this->postJson($this->url('/connect'), ['region' => 'eu', 'target_host' => '172.18.0.1', 'allowed_modes' => ['everything']])->assertStatus(422);

        $this->assertSame([], $this->provider->bodies('POST link-kits'));
    }

    public function test_show_lists_online_regions_and_says_when_none_is_online(): void
    {
        $this->asAdmin();
        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.connected', false)
            ->assertJsonPath('data.regions', ['eu', 'us'])
            ->assertJsonPath('data.target_host', '172.18.0.1')
            ->assertJsonPath('data.allowed_modes', ['direct', 'both', 'gateway_only'])
            ->assertJsonPath('data.notice', null);

        $this->provider->on('GET regions', 200, ['hosterDomains' => [], 'items' => [['region' => 'eu', 'wildcard' => '*.play.example.net', 'online' => false]]]);
        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.regions', [])
            ->assertJsonPath('data.notice', 'No gateway region is online right now, so this node cannot be connected yet. Try again later.');
    }

    public function test_kit_status_and_gateway_servers_come_back_once_connected(): void
    {
        $this->connected();
        $this->provider->on('GET link-kits', 200, ['items' => [['link_id' => 'link-1', 'online' => true]]]);
        $primary = Allocation::factory()->create(['node_id' => $this->node->id]);
        $server = $this->createServerModel(['node_id' => $this->node->id, 'allocation_id' => $primary->id, 'name' => 'Survival']);
        $this->store()->put(new ServerState($server->id, Mode::Both, 'mc', 'play.example.net', $this->node->id, 25565, lastError: 'Route missing.'));
        $this->asAdmin();

        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.kit_status', 'Link online')
            ->assertJsonPath('data.servers', [['name' => 'Survival', 'mode' => 'Both', 'address' => 'mc.play.example.net', 'error' => 'Route missing.']]);
    }

    public function test_allowed_modes_can_change_while_connected(): void
    {
        $this->connected();
        $this->asAdmin();

        $this->putJson($this->url('/modes'), ['allowed_modes' => ['gateway_only']])->assertOk()->assertJsonPath('message', 'Allowed modes saved.');

        $n = $this->store()->node($this->node->id);
        $this->assertSame([[Mode::GatewayOnly], 'link-1', 'eu'], [$n->allowedModes, $n->linkId, $n->region]);
    }

    public function test_disconnect_is_refused_while_servers_use_the_gateway_then_revokes(): void
    {
        $this->connected();
        $primary = Allocation::factory()->create(['node_id' => $this->node->id]);
        $server = $this->createServerModel(['node_id' => $this->node->id, 'allocation_id' => $primary->id, 'name' => 'Survival']);
        $this->store()->put(new ServerState($server->id, Mode::Both, 'mc', 'play.example.net', $this->node->id, 25565));
        $this->provider->on('DELETE link-kits/link-1', 200, ['success' => true]);
        $this->asAdmin();

        $this->postJson($this->url('/disconnect'))->assertStatus(422)->assertJsonPath('error', 'Switch these servers to Direct first: Survival.');
        $this->assertSame([], $this->provider->bodies('DELETE link-kits/link-1'));

        $this->store()->delete($server->id);
        $this->postJson($this->url('/disconnect'))->assertOk()->assertJsonPath('message', 'Node disconnected and its link kit revoked.');
        $this->assertCount(1, $this->provider->bodies('DELETE link-kits/link-1'));
        $this->assertNull($this->store()->node($this->node->id)->linkId);
    }

    public function test_an_unconfigured_extension_says_so(): void
    {
        $this->app->bind(ProviderClient::class, fn () => throw new GatewayUnavailable(GatewaySettings::KEY_UNREADABLE));
        $this->asAdmin();

        $this->getJson($this->url())->assertOk()->assertJsonPath('data.problem', 'The stored API key can no longer be read. Enter it again.');
    }

    /** The provider lists a new kit a moment after the mint; until then the tab says offline, not "retired". */
    public function test_right_after_connect_the_kit_reads_offline(): void
    {
        $this->provider->on('POST link-kits', 200, ['link_id' => 'link-new', 'link_key' => self::LINK_KEY]);
        $this->asAdmin();

        $this->postJson($this->url('/connect'), ['region' => 'us', 'target_host' => '172.20.0.1', 'allowed_modes' => ['direct']])
            ->assertOk()
            ->assertJsonPath('data.kit_status', 'Link offline');
    }

    public function test_the_connect_lock_outlasts_the_mint_timeouts(): void
    {
        $this->provider->on('POST link-kits', 200, ['link_id' => 'link-new', 'link_key' => self::LINK_KEY]);
        $this->asAdmin();
        $held = Cache::lock('dylaris-gateway-connect-' . $this->node->id, 60);
        $this->assertTrue($held->get());

        $this->postJson($this->url('/connect'), ['region' => 'us', 'target_host' => '172.20.0.1', 'allowed_modes' => ['direct']])
            ->assertStatus(409)
            ->assertJsonPath('error', 'This node is already being connected.');
        $this->assertSame(60, \Dylaris\GatewayPtero\Http\NodeGatewayController::CONNECT_LOCK_SECONDS);
        $held->release();
    }
}
