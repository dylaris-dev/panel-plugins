<?php

namespace Dylaris\GatewayPteroTests;

use Dylaris\GatewayPanel\DomainEntry;
use Dylaris\GatewayPanel\DomainSync;
use Dylaris\GatewayPtero\ReconcileRunner;
use Illuminate\Support\Facades\Cache;
use Dylaris\GatewayPanel\Mode;
use Dylaris\GatewayPanel\ModeService;
use Dylaris\GatewayPanel\NodeState;
use Dylaris\GatewayPanel\ServerState;
use Dylaris\GatewayPanel\StateStore;
use Dylaris\GatewayPtero\Permissions;
use Dylaris\GatewayPtero\PteroStateStore;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Data\ServerState as WingsState;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Subuser;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Extensions\ExtensionPermissionRegistry;
use Pterodactyl\Tests\Support\Fakes\FakeDaemonServer;

class ServerGatewayRoutesTest extends ExtensionTestCase
{
    private const FLOODGATE = 'FGKEY-0123456789';

    private FakeProvider $provider;

    private Server $server;

    /** Wings' uptime for the server, in milliseconds. */
    private int $uptimeMs = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = FakeProvider::bind($this->app);
        $node = Node::factory()->for(Location::factory())->create();
        $primary = Allocation::factory()->create(['node_id' => $node->id, 'ip' => '203.0.113.10', 'port' => 25565]);
        $this->server = $this->createServerModel(['node_id' => $node->id, 'allocation_id' => $primary->id]);
        $test = $this;
        $this->app->bind(ReadsServerState::class, fn () => new class($test) implements ReadsServerState {
            public function __construct(private readonly ServerGatewayRoutesTest $test) {}

            public function read(Server $server): WingsState
            {
                return new WingsState('running', false, 0, 0, 0, 0, 0, $this->test->uptimeMs());
            }
        });
    }

    /** Runs inside the Wings call, to model a change made while it is under way. */
    public ?\Closure $duringWings = null;

    public function uptimeMs(): int
    {
        if ($this->duringWings !== null) {
            ($this->duringWings)();
        }

        return $this->uptimeMs;
    }

    private function url(string $path = '', ?Server $server = null): string
    {
        return '/api/client/servers/' . ($server ?? $this->server)->uuid . '/extensions/dylaris-gateway' . $path;
    }

    private function store(): PteroStateStore
    {
        return $this->app->make(StateStore::class);
    }

    /** @param Mode[] $allowed */
    private function nodeConnected(array $allowed = [Mode::Direct, Mode::Both, Mode::GatewayOnly]): void
    {
        $this->store()->putNode(new NodeState($this->server->node_id, 'link-1', 'eu', $allowed, '172.18.0.1'));
    }

    private function inMode(Mode $mode, bool $bedrock = false): ServerState
    {
        $s = new ServerState($this->server->id, $mode, 'mc', 'play.example.net', $this->server->node_id, 25565, bedrock: $bedrock);
        $this->store()->put($s);

        return $s;
    }

    /** One user per test: the panel refuses a second actingAs() user in the same test. */
    private function asOwner(): static
    {
        return $this->actingAs($this->server->user);
    }

    /** @param list<string> $permissions */
    private function asSubuser(array $permissions): static
    {
        $user = User::factory()->create();
        Subuser::query()->create(['user_id' => $user->id, 'server_id' => $this->server->id, 'permissions' => $permissions]);

        return $this->actingAs($user);
    }

    public function test_the_three_permissions_are_registered_for_subusers(): void
    {
        $this->assertSame(['ext.dylaris-gateway.view', 'ext.dylaris-gateway.mode', 'ext.dylaris-gateway.address'], [Permissions::VIEW, Permissions::MODE, Permissions::ADDRESS]);
        $group = $this->app->make(ExtensionPermissionRegistry::class)->all()['ext.dylaris-gateway'] ?? null;
        $this->assertNotNull($group, 'the extension registered its permission group');
        $this->assertSame(['view', 'mode', 'address'], array_keys($group['keys']));
    }

    public function test_a_subuser_without_the_permission_is_refused_everywhere(): void
    {
        $this->nodeConnected();
        $this->asSubuser(['control.console']);

        $this->getJson($this->url())->assertForbidden();
        $this->postJson($this->url('/mode'), ['mode' => 'both', 'label' => 'survival'])->assertForbidden();
        $this->postJson($this->url('/real-ip'), ['enabled' => true])->assertForbidden();
        $this->postJson($this->url('/bedrock'), ['enabled' => true])->assertForbidden();
        $this->get($this->url('/floodgate-key'))->assertForbidden();

        $this->assertSame([], $this->provider->calls);
        $this->assertNull($this->store()->get($this->server->id));
    }

    public function test_another_users_server_is_not_reachable(): void
    {
        $other = $this->createServerModel();
        $this->asOwner();

        $this->getJson($this->url('', $other))->assertNotFound();
        $this->postJson($this->url('/mode', $other), ['mode' => 'both', 'label' => 'x'])->assertNotFound();
    }

    public function test_a_subuser_with_the_permission_switches_to_both(): void
    {
        $wings = new FakeDaemonServer();
        $this->nodeConnected();
        $this->asSubuser([Permissions::MODE, Permissions::ADDRESS]);
        $this->server->update(['name' => 'Survival 1']);

        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.mode', 'direct')
            ->assertJsonPath('data.first_enable', true)
            ->assertJsonPath('data.label', 'survival1');

        $this->postJson($this->url('/mode'), ['mode' => 'both', 'label' => 'survival'])->assertOk()
            ->assertJsonPath('message', 'Restart the server to apply the change.')
            ->assertJsonPath('data.mode', 'both')
            ->assertJsonPath('data.address', 'survival.play.example.net')
            ->assertJsonPath('data.restart_needed', true);

        $this->assertSame([[
            'linkId' => 'link-1', 'subdomain' => 'survival', 'hosterDomain' => 'play.example.net', 'targetHost' => '172.18.0.1', 'targetPort' => 25565,
        ]], $this->provider->bodies('POST routes'));
        $this->assertSame(1, Allocation::query()->where('server_id', $this->server->id)->where('ip', '127.0.0.1')->count());
        $wings->assertSynced();
    }

    public function test_an_owner_cannot_set_a_mode_the_node_does_not_allow(): void
    {
        $this->nodeConnected([Mode::Direct, Mode::Both]);
        $this->asOwner();

        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.modes', [['value' => 'direct', 'label' => 'Direct'], ['value' => 'both', 'label' => 'Both']]);
        $this->postJson($this->url('/mode'), ['mode' => 'gateway_only', 'label' => 'survival'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'This mode is not allowed on this node.');

        $this->assertNull($this->store()->get($this->server->id));
        $this->assertSame([], $this->provider->bodies('POST routes'));
    }

    public function test_an_admin_is_offered_every_mode(): void
    {
        $this->nodeConnected([Mode::Direct]);
        $this->actingAs(User::factory()->create(['root_admin' => true]));

        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.modes', [['value' => 'direct', 'label' => 'Direct'], ['value' => 'both', 'label' => 'Both'], ['value' => 'gateway_only', 'label' => 'Gateway only']])
            ->assertJsonPath('data.not_offered', null);
    }

    public function test_a_mode_the_node_no_longer_offers_stays_and_the_page_says_so(): void
    {
        $this->nodeConnected([Mode::Direct]);
        $this->inMode(Mode::GatewayOnly);
        $this->asOwner();

        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.mode', 'gateway_only')
            ->assertJsonPath('data.modes', [['value' => 'direct', 'label' => 'Direct'], ['value' => 'gateway_only', 'label' => 'Gateway only']])
            ->assertJsonPath('data.not_offered', 'Gateway only is no longer offered on this node. The server keeps it until you switch to another mode.');

        $this->assertSame(Mode::GatewayOnly, $this->store()->get($this->server->id)->mode);
        $this->assertSame([], $this->provider->bodies('POST routes'));
    }

    /** Found live: a letters-only hoster domain refused the short id the field used to offer. */
    public function test_the_offered_label_follows_the_validation(): void
    {
        $this->nodeConnected();
        $this->asOwner();
        $this->server->update(['name' => 'Paper A 1.21']);
        $this->provider->on('GET regions', 200, ['hosterDomains' => [['domain' => 'eu.example.net', 'validation' => 'letters']], 'items' => []]);

        $this->getJson($this->url())->assertOk()->assertJsonPath('data.label', 'papera');
    }

    /** Found live: leaving Gateway only with Real player IP on locked everybody out, and nothing said so before or after. */
    public function test_leaving_gateway_only_with_real_ip_is_announced_before_and_after(): void
    {
        $this->nodeConnected();
        $this->asOwner();
        $s = $this->inMode(Mode::GatewayOnly);
        $s->proxyProtocol = true;
        $s->publicPort = 25599;
        $this->store()->put($s);
        $free = Allocation::factory()->create(['node_id' => $this->server->node_id, 'ip' => '203.0.113.10', 'port' => 25570]);

        $this->postJson($this->url('/mode/preview'), ['mode' => 'direct'])->assertOk()
            ->assertJsonPath('data.warnings', ['Port 25599 is taken, so the direct port will be 25570.', ModeService::PROXY_OFF]);
        $this->postJson($this->url('/mode/preview'), ['mode' => 'gateway_only'])->assertOk()->assertJsonPath('data.warnings', []);
        $this->assertSame(Mode::GatewayOnly, $this->store()->get($this->server->id)->mode, 'the preview changes nothing');
        $this->assertNotNull($free);
    }

    public function test_the_preview_needs_the_permission(): void
    {
        $this->asSubuser([]);
        $this->postJson($this->url('/mode/preview'), ['mode' => 'direct'])->assertForbidden();
    }

    /** Found live: a server on a node without a link still read "Route active, link online". */
    public function test_a_node_without_a_link_is_not_shown_as_active(): void
    {
        $this->asOwner();
        $this->inMode(Mode::Both); // the server's node has no link
        $this->provider->on('GET routes', 200, ['items' => [['domain' => 'mc.play.example.net', 'link_id' => 'link-1', 'target_ip' => '172.18.0.1', 'target_port' => 25565]]]);

        $this->getJson($this->url())->assertOk()->assertJsonPath('data.status', 'This node is not connected to the gateway.');
    }

    public function test_a_refused_change_says_why_and_changes_nothing(): void
    {
        $this->store()->putNode(new NodeState($this->server->node_id, null, null, Mode::cases()));
        $this->asOwner();

        $this->postJson($this->url('/mode'), ['mode' => 'both', 'label' => 'survival'])
            ->assertStatus(422)
            ->assertJsonPath('error', ModeService::NOT_CONNECTED);
        $this->postJson($this->url('/mode'), ['mode' => 'everything'])->assertStatus(422);

        $this->assertNull($this->store()->get($this->server->id));
    }

    public function test_real_ip_switches_through_the_mode_service(): void
    {
        $this->nodeConnected();
        $this->inMode(Mode::Both);
        $this->asOwner();

        $this->postJson($this->url('/real-ip'), ['enabled' => true])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Real player IP is only available in Gateway only mode.');

        $this->inMode(Mode::GatewayOnly);
        $this->provider->on('PATCH routes/mc.play.example.net/proxy-protocol', 200, ['success' => true]);
        $this->postJson($this->url('/real-ip'), ['enabled' => true])->assertOk()
            ->assertJsonPath('message', 'Real player IP is on.')
            ->assertJsonPath('data.real_ip', true);
        $this->assertSame([['enabled' => true]], $this->provider->bodies('PATCH routes/mc.play.example.net/proxy-protocol'));
    }

    public function test_bedrock_failure_says_why(): void
    {
        $this->nodeConnected();
        $this->inMode(Mode::Both);
        $this->provider->on('PUT routes/mc.play.example.net/bedrock', 403, ['error' => 'Bedrock is not part of your plan.', 'code' => 'forbidden']);
        $this->asOwner();

        $this->postJson($this->url('/bedrock'), ['enabled' => true])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Bedrock is not part of your plan.');
        $this->assertFalse($this->store()->get($this->server->id)->bedrock);
    }

    public function test_the_floodgate_key_is_a_file_download_never_in_the_page(): void
    {
        $this->nodeConnected();
        $this->inMode(Mode::Both, bedrock: true);
        $this->provider->on('GET routes/mc.play.example.net/floodgate-key', 200, ['domain' => 'mc.play.example.net', 'key' => base64_encode(self::FLOODGATE)]);
        $this->asOwner();

        $page = $this->getJson($this->url())->assertOk()->assertJsonPath('data.bedrock', true);
        $this->assertStringNotContainsString(self::FLOODGATE, $page->getContent());

        $download = $this->get($this->url('/floodgate-key'))->assertOk()->assertDownload('key.pem');
        $this->assertSame(self::FLOODGATE, $download->streamedContent());
    }

    public function test_status_and_last_error(): void
    {
        $this->nodeConnected();
        $s = $this->inMode(Mode::Both);
        $s->lastError = 'This node is not connected to the gateway.';
        $this->store()->put($s);
        $this->provider->on('GET link-kits', 200, ['items' => [['link_id' => 'link-1', 'online' => true]]]);
        $this->provider->on('GET routes', 200, ['items' => [['domain' => 'mc.play.example.net', 'link_id' => 'link-1', 'target_ip' => '172.18.0.1', 'target_port' => 25565]]]);
        $this->provider->on('GET players', 200, ['items' => [['domain' => 'mc.play.example.net', 'java' => 3, 'bedrock' => 1]]]);
        $this->asOwner();

        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.status', 'Route active, link online, 3 Java and 1 Bedrock players.')
            ->assertJsonPath('data.last_error', 'This node is not connected to the gateway.');
    }

    /** Clearing the banner writes only the flag: a mode change made during the Wings call must survive. */
    public function test_clearing_the_banner_keeps_a_change_made_meanwhile(): void
    {
        $this->nodeConnected();
        $this->asOwner();
        $s = $this->inMode(Mode::Both);
        $s->restartNeeded = true;
        $this->store()->put($s);
        $this->store()->markRestartNeeded($this->server->id, time() - 100);
        $this->uptimeMs = 50_000;
        $this->duringWings = function () {
            $now = $this->store()->get($this->server->id);
            $now->mode = Mode::GatewayOnly;
            $now->publicPort = 25565;
            $this->store()->put($now);
        };

        $this->getJson($this->url())->assertOk();

        $after = $this->store()->get($this->server->id);
        $this->assertSame(Mode::GatewayOnly, $after->mode);
        $this->assertSame(25565, $after->publicPort);
        $this->assertFalse($after->restartNeeded);
    }

    public function test_the_change_lock_outlives_a_slow_change(): void
    {
        $this->assertSame(300, \Dylaris\GatewayPtero\ReconcileRunner::CHANGE_SECONDS);
    }

    public function test_the_restart_banner_clears_once_the_server_started_after_the_change(): void
    {
        $this->nodeConnected();
        $s = $this->inMode(Mode::Both);
        $s->restartNeeded = true;
        $this->store()->put($s);
        $this->store()->markRestartNeeded($this->server->id, time() - 100);
        $this->asOwner();

        // Running for 200 s: started before the change, the banner stays.
        $this->uptimeMs = 200_000;
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.restart_needed', true);
        $this->assertTrue($this->store()->get($this->server->id)->restartNeeded);

        // Running for 50 s: started after the change, the banner goes.
        $this->uptimeMs = 50_000;
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.restart_needed', false);
        $this->assertFalse($this->store()->get($this->server->id)->restartNeeded);
    }

    private function providerCalls(string $key): int
    {
        return count(array_filter($this->provider->calls, fn ($c) => $c['key'] === $key));
    }

    /** A mode change waits for the reconcile instead of racing it. */
    public function test_a_change_while_the_gateway_lock_is_held_is_refused(): void
    {
        $this->nodeConnected();
        $this->inMode(Mode::GatewayOnly);
        $this->asOwner();
        $lock = Cache::lock(ReconcileRunner::LOCK, 600);
        $this->assertTrue($lock->get());

        $this->postJson($this->url('/mode'), ['mode' => 'both'])->assertStatus(422)->assertJsonPath('error', ModeService::BUSY);
        $this->postJson($this->url('/real-ip'), ['enabled' => true])->assertStatus(422)->assertJsonPath('error', ModeService::BUSY);
        $this->postJson($this->url('/bedrock'), ['enabled' => true])->assertStatus(422)->assertJsonPath('error', ModeService::BUSY);

        $s = $this->store()->get($this->server->id);
        $this->assertSame([Mode::GatewayOnly, false, false], [$s->mode, $s->proxyProtocol, $s->bedrock]);
        $this->assertSame('The gateway is being updated, try again in a moment.', ModeService::BUSY);

        $lock->release();
        $this->provider->on('PUT routes/mc.play.example.net/bedrock', 200, ['success' => true]);
        $this->postJson($this->url('/bedrock'), ['enabled' => true])->assertOk();
        $this->assertTrue(Cache::lock(ReconcileRunner::LOCK, 1)->get(), 'the change released the lock');
    }

    /** A page view must not spend the provider's read budget every time. */
    public function test_page_views_reuse_the_label_and_the_status(): void
    {
        $this->nodeConnected();
        $this->asOwner();
        $this->getJson($this->url())->assertOk();
        $first = $this->providerCalls('GET regions');
        $this->getJson($this->url())->assertOk();
        $this->assertSame($first, $this->providerCalls('GET regions'), 'label suggestion and domains cached');

        $this->inMode(Mode::Both);
        $before = $this->providerCalls('GET routes'); // the name suggestion read the list once while Direct
        $this->getJson($this->url())->assertOk();
        $this->getJson($this->url())->assertOk();
        $this->assertSame($before + 1, $this->providerCalls('GET routes'), 'status cached per server');
    }

    public function test_page_views_wait_while_the_provider_asked_to_wait(): void
    {
        $this->nodeConnected();
        $this->store()->put(new ServerState(99999, Mode::Direct, '', '', $this->server->node_id, 25565, retryAfterUntil: time() + 60));
        $this->asOwner();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.label', '');
        $s = $this->inMode(Mode::Both);
        $s->retryAfterUntil = time() + 60;
        $this->store()->put($s);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.status', ModeService::PROVIDER_BUSY);

        $this->assertSame([], $this->provider->calls);
    }

    public function test_view_only_reads_but_changes_nothing(): void
    {
        $this->nodeConnected();
        $this->inMode(Mode::Both);
        $this->asSubuser([Permissions::VIEW]);

        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.address', 'mc.play.example.net')
            ->assertJsonPath('data.can_mode', false)
            ->assertJsonPath('data.can_address', false)
            ->assertJsonPath('data.domains', []);
        $this->postJson($this->url('/mode'), ['mode' => 'direct'])->assertForbidden();
        $this->postJson($this->url('/address'), ['domain' => 'play.example.net', 'label' => 'x'])->assertForbidden();
        $this->postJson($this->url('/bedrock'), ['enabled' => true])->assertForbidden();
        $this->get($this->url('/floodgate-key'))->assertForbidden();
        $this->assertSame(Mode::Both, $this->store()->get($this->server->id)->mode);
    }

    public function test_mode_only_switches_and_the_extension_picks_the_address(): void
    {
        $wings = new FakeDaemonServer();
        $this->nodeConnected();
        $this->server->update(['name' => 'Lobby']);
        $this->asSubuser([Permissions::MODE]);

        $this->getJson($this->url())->assertOk()->assertJsonPath('data.can_mode', true)->assertJsonPath('data.can_address', false);
        $this->postJson($this->url('/mode'), ['mode' => 'both', 'label' => 'sneaky', 'domain' => 'play.example.net'])->assertOk();
        $this->postJson($this->url('/address'), ['domain' => 'play.example.net', 'label' => 'x'])->assertForbidden();

        $this->assertSame('lobby.play.example.net', $this->store()->get($this->server->id)->domain());
    }

    public function test_address_only_renames_behind_the_gateway_new_route_first_old_one_last(): void
    {
        $this->nodeConnected();
        $this->inMode(Mode::Both);
        $this->asSubuser([Permissions::ADDRESS]);
        $this->provider->on('DELETE routes/mc.play.example.net', 200, ['success' => true]);

        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.can_mode', false)
            ->assertJsonPath('data.can_address', true)
            ->assertJsonPath('data.domain', 'play.example.net')
            ->assertJsonPath('data.label', 'mc');
        $this->postJson($this->url('/mode'), ['mode' => 'direct'])->assertForbidden();
        $this->postJson($this->url('/address'), ['domain' => 'play.example.net', 'label' => 'newname'])->assertOk()
            ->assertJsonPath('message', 'The gateway address is now newname.play.example.net. The old address mc.play.example.net no longer works: tell your players.')
            ->assertJsonPath('data.address', 'newname.play.example.net');

        $keys = array_column($this->provider->calls, 'key');
        $this->assertLessThan(array_search('DELETE routes/mc.play.example.net', $keys, true), array_search('POST routes', $keys, true));
        $this->assertSame(Mode::Both, $this->store()->get($this->server->id)->mode);
    }

    public function test_a_failed_rename_keeps_the_old_address(): void
    {
        $this->nodeConnected();
        $this->inMode(Mode::Both);
        $this->asOwner();
        $this->provider->on('POST routes', 409, ['error' => 'That address is already in use.', 'code' => 'domain_taken']);

        $this->postJson($this->url('/address'), ['domain' => 'play.example.net', 'label' => 'taken'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'That address is already in use.');

        $this->assertSame('mc.play.example.net', $this->store()->get($this->server->id)->domain());
        $this->assertNotContains('DELETE routes/mc.play.example.net', array_column($this->provider->calls, 'key'));
    }

    public function test_the_domain_list_of_the_node_is_offered_and_admins_only_ones_are_hidden(): void
    {
        $this->store()->putNode(new NodeState($this->server->node_id, 'link-1', 'eu', Mode::cases(), '172.18.0.1'));
        $this->store()->putDomains([
            new DomainEntry('play.example.net', DomainEntry::OFFICIAL, true, 'alphanumeric', 'eu'),
            new DomainEntry('us.example.net', DomainEntry::OFFICIAL, true, 'alphanumeric', 'us'),
            new DomainEntry('own.example.com', DomainEntry::OWN, true, ownState: 'verified'),
            new DomainEntry('pending.example.com', DomainEntry::OWN, true, ownState: 'pending'),
            new DomainEntry('vip.example.com', DomainEntry::OWN, false, ownState: 'verified'),
        ]);
        $this->store()->putDomainSync(new DomainSync(time(), time()));
        $wings = new FakeDaemonServer();
        $this->asOwner();

        $this->getJson($this->url())->assertOk()->assertJsonPath('data.domains', [
            ['value' => 'play.example.net', 'label' => 'play.example.net (eu)', 'custom' => false],
            ['value' => 'own.example.com', 'label' => 'own.example.com (own domain, the whole address)', 'custom' => true],
        ]);
        $this->getJson($this->url())->assertJsonPath('data.no_domains', null);
        $this->postJson($this->url('/mode'), ['mode' => 'both', 'domain' => 'vip.example.com'])->assertStatus(422)
            ->assertJsonPath('error', 'That domain is not offered for this server.');
        $this->postJson($this->url('/mode'), ['mode' => 'both', 'domain' => 'own.example.com', 'label' => 'ignored'])->assertOk()
            ->assertJsonPath('data.address', 'own.example.com');
        $this->assertSame([['linkId' => 'link-1', 'customDomain' => 'own.example.com', 'targetHost' => '172.18.0.1', 'targetPort' => 25565]], $this->provider->bodies('POST routes'));
    }

    public function test_a_server_of_another_egg_gets_no_gateway(): void
    {
        $this->nodeConnected();
        $this->asOwner();
        $this->setEggs([]);

        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.not_allowed', ModeService::MINECRAFT_ONLY)
            ->assertJsonPath('data.modes', [['value' => 'direct', 'label' => 'Direct']])
            ->assertJsonPath('data.can_address', false);
        $this->postJson($this->url('/mode'), ['mode' => 'both'])->assertStatus(422)->assertJsonPath('error', ModeService::MINECRAFT_ONLY);
        $this->assertSame([], $this->provider->bodies('POST routes'));
    }

    public function test_a_server_already_behind_the_gateway_keeps_it_until_an_admin_switches_it(): void
    {
        $this->nodeConnected();
        $this->inMode(Mode::Both);
        $this->setEggs([]);
        $this->provider->on('DELETE routes/mc.play.example.net', 200, ['success' => true]);
        $wings = new FakeDaemonServer();
        $this->actingAs(User::factory()->create(['root_admin' => true]));

        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.not_allowed', ModeService::NO_LONGER_ALLOWED)
            ->assertJsonPath('data.address', 'mc.play.example.net')
            ->assertJsonPath('data.modes', [['value' => 'direct', 'label' => 'Direct'], ['value' => 'both', 'label' => 'Both']]);
        $this->postJson($this->url('/mode'), ['mode' => 'gateway_only'])->assertStatus(422)->assertJsonPath('error', ModeService::NO_LONGER_ALLOWED);
        $this->postJson($this->url('/mode'), ['mode' => 'direct'])->assertOk();
    }

    /** @param list<int> $eggIds */
    private function setEggs(array $eggIds): void
    {
        \Illuminate\Support\Facades\DB::table('extension_settings')->updateOrInsert(
            ['extension' => self::ID, 'scope' => 'global', 'key' => 'eggs'],
            ['value' => json_encode($eggIds), 'is_secret' => false],
        );
    }
}
