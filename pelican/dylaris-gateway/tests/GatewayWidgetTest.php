<?php

namespace Dylaris\GatewayPelican\Tests;

use Illuminate\Support\Facades\Cache;
use App\Enums\TablerIcon;
use App\Filament\Server\Pages\Console;
use App\Models\Allocation;
use App\Models\Node;
use App\Models\Role;
use App\Models\Server;
use App\Models\Subuser;
use App\Models\User;
use App\Repositories\Daemon\DaemonServerRepository;
use Dylaris\GatewayPanel\AllowedDomain;
use Dylaris\GatewayPanel\Mode;
use Dylaris\GatewayPanel\ModeService;
use Dylaris\GatewayPanel\NodeState;
use Dylaris\GatewayPanel\ServerState;
use Dylaris\GatewayPanel\StateStore;
use Dylaris\GatewayPelican\DylarisGatewayPlugin;
use Dylaris\GatewayPelican\EloquentStateStore;
use Dylaris\GatewayPelican\Filament\Server\Pages\GatewayPage;
use Dylaris\GatewayPelican\GatewaySettings;
use Dylaris\GatewayPelican\Filament\Server\Widgets\GatewayWidget;
use Dylaris\GatewayPelican\Permissions;
use Filament\Facades\Filament;
use Filament\Panel;
use Livewire\Livewire;
use Mockery\MockInterface;

class GatewayWidgetTest extends PluginTestCase
{
    private const FLOODGATE = 'FGKEY-0123456789';

    private FakeProvider $provider;

    private Server $server;

    /** Wings' answer to getDetails: state and uptime in milliseconds. */
    private array $details = ['state' => 'offline', 'utilization' => ['uptime' => 0]];

    /** @var list<string> power actions sent to Wings */
    private array $power = [];

    /** Runs inside the Wings call, to model a change made while it is under way. */
    private ?\Closure $duringWings = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(DaemonServerRepository::class, function (MockInterface $m) {
            $m->shouldReceive('setServer')->andReturnSelf();
            $m->shouldReceive('sync');
            $m->shouldReceive('power')->andReturnUsing(function (string $action) {
                $this->power[] = $action;

                return new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response(204));
            });
            $m->shouldReceive('getDetails')->andReturnUsing(function () {
                if ($this->duringWings !== null) {
                    ($this->duringWings)();
                }

                return $this->details;
            });
        });
        $this->provider = FakeProvider::bind($this->app);
        $node = Node::factory()->create();
        $primary = Allocation::factory()->create(['node_id' => $node->id, 'ip' => '203.0.113.10', 'port' => 25565]);
        $this->server = $this->createServerModel(['node_id' => $node->id, 'allocation_id' => $primary->id]);
        $this->actingAs($this->server->user);
        Filament::setCurrentPanel(Filament::getPanel('server'));
        Filament::setTenant($this->server);
    }

    protected function tearDown(): void
    {
        Filament::setCurrentPanel(null);
        parent::tearDown();
    }

    private function store(): EloquentStateStore
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

    private function widget(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(GatewayWidget::class, ['server' => $this->server]);
    }

    /** 0.2.0: a page of its own in the server navigation; the console widget is gone. */
    public function test_the_gateway_page_is_registered_on_the_server_panel_only(): void
    {
        $server = Panel::make()->id('server');
        (new DylarisGatewayPlugin())->register($server);
        $this->assertContains(GatewayPage::class, $server->getPages());

        $admin = Panel::make()->id('admin');
        (new DylarisGatewayPlugin())->register($admin);
        $this->assertNotContains(GatewayPage::class, $admin->getPages());

        $this->assertNotContains(GatewayWidget::class, (new Console())->getWidgets());
        $this->assertSame('Gateway', GatewayPage::getNavigationLabel());
    }

    public function test_the_three_permissions_are_registered_for_subusers_and_roles(): void
    {
        foreach (['gateway.view', 'gateway.mode', 'gateway.address'] as $p) {
            $this->assertTrue(Subuser::doesPermissionExist($p), $p);
        }
        $this->assertFalse(Subuser::doesPermissionExist('gateway.manage'));
        $this->assertSame(['view', 'mode', 'address'], array_values(Role::getPermissionList()['gateway']));
        $this->assertSame('DYLARIS gateway', trans('dylaris-gateway::permissions.gateway_title'));
        $this->assertStringContainsString('The old address stops working', trans('dylaris-gateway::permissions.gateway_address'));
    }

    private function subuser(string ...$permissions): User
    {
        $user = User::factory()->create();
        Subuser::query()->create(['user_id' => $user->id, 'server_id' => $this->server->id, 'permissions' => ['control.console', ...$permissions]]);
        $this->actingAs($user);
        $this->server->unsetRelation('subusers'); // the panel caches the subuser list on the server model

        return $user;
    }

    public function test_a_subuser_without_a_gateway_permission_does_not_get_the_page(): void
    {
        $this->subuser();

        $this->assertFalse(GatewayPage::canAccess());
        $this->widget()->assertForbidden();
    }

    public function test_owner_admin_and_permitted_subusers_get_the_page(): void
    {
        $this->assertTrue(GatewayPage::canAccess(), 'owner');
        $this->widget()->assertOk()->assertSee('Gateway');

        foreach (['gateway.view', 'gateway.mode', 'gateway.address'] as $p) {
            $this->subuser($p);
            $this->assertTrue(GatewayPage::canAccess(), $p);
        }

        $admin = User::factory()->create();
        $admin->syncRoles(Role::getRootAdmin());
        $this->actingAs($admin);
        $this->assertTrue(GatewayPage::canAccess(), 'admin');
    }

    public function test_view_only_sees_address_and_status_and_changes_nothing(): void
    {
        $this->nodeConnected();
        $this->inMode(Mode::Both);
        $this->subuser('gateway.view');

        $this->widget()
            ->assertOk()
            ->assertSee('mc.play.example.net')
            ->assertFormFieldIsHidden('mode')
            ->assertFormFieldIsHidden('domain')
            ->assertFormFieldIsHidden('label')
            ->assertFormFieldIsHidden('bedrock')
            ->assertActionHidden('apply')
            ->assertActionHidden('changeAddress');
    }

    public function test_mode_only_switches_without_choosing_the_address(): void
    {
        $this->nodeConnected();
        $this->server->update(['name' => 'Lobby']);
        $this->subuser('gateway.mode');

        $this->widget()
            ->assertFormFieldIsHidden('domain')
            ->assertFormFieldIsHidden('label')
            ->assertActionHidden('changeAddress')
            ->fillForm(['mode' => 'both', 'label' => 'sneaky'])
            ->callAction('apply')
            ->assertNotified('Restart the server to apply the change.');

        $this->assertSame('lobby.play.example.net', $this->store()->get($this->server->id)->domain(), 'the plugin picked the address');
    }

    public function test_address_only_changes_the_address_but_not_the_mode(): void
    {
        $this->nodeConnected();
        $this->inMode(Mode::Both);
        $this->subuser('gateway.address');
        $this->provider->on('DELETE routes/mc.play.example.net', 200, ['success' => true]);

        $this->widget()
            ->assertFormFieldIsHidden('mode')
            ->assertActionHidden('apply')
            ->assertFormSet(['domain' => 'play.example.net', 'label' => 'mc'])
            ->fillForm(['label' => 'newname'])
            ->callAction('changeAddress')
            ->assertNotified('The gateway address is now newname.play.example.net. The old address mc.play.example.net no longer works: tell your players.');

        $this->assertSame([Mode::Both, 'newname.play.example.net'], [$this->store()->get($this->server->id)->mode, $this->store()->get($this->server->id)->domain()]);
        $this->assertSame(1, $this->providerCalls('DELETE routes/mc.play.example.net'));
    }

    public function test_changing_the_address_behind_the_gateway_asks_first_and_says_the_old_one_stops(): void
    {
        $this->nodeConnected();
        $this->inMode(Mode::Both);

        $this->widget()
            ->assertActionHasLabel('changeAddress', 'Change address')
            ->fillForm(['label' => 'other'])
            ->mountAction('changeAddress')
            ->assertActionMounted('changeAddress')
            ->assertMountedActionModalSee('the old address mc.play.example.net stops working');
    }

    public function test_the_domain_dropdown_lists_the_node_domains_and_hides_admins_only_ones(): void
    {
        $this->store()->putNode(new NodeState($this->server->node_id, 'link-1', 'eu', Mode::cases(), '172.18.0.1', [
            new AllowedDomain('play.example.net', false, false, 'alphanumeric'),
            new AllowedDomain('vip.example.com', true, true),
            new AllowedDomain('own.example.com', true),
        ]));

        $this->assertSame(['play.example.net' => 'play.example.net', 'own.example.com' => 'own.example.com (own domain, the whole address)'], $this->widget()->instance()->domainOptions());

        $admin = User::factory()->create();
        $admin->syncRoles(Role::getRootAdmin());
        $this->actingAs($admin);
        $this->assertArrayHasKey('vip.example.com', $this->widget()->instance()->domainOptions());
    }

    public function test_only_admins_only_domains_leave_the_address_fields_out_and_say_so(): void
    {
        $this->store()->putNode(new NodeState($this->server->node_id, 'link-1', 'eu', Mode::cases(), '172.18.0.1', [new AllowedDomain('vip.example.com', true, true)]));
        $this->inMode(Mode::Both);
        $this->subuser('gateway.address');

        $this->widget()->assertSee(ModeService::NO_DOMAINS)->assertFormFieldIsHidden('domain')->assertActionHidden('changeAddress');
    }

    public function test_an_own_domain_takes_no_label(): void
    {
        $this->store()->putNode(new NodeState($this->server->node_id, 'link-1', 'eu', Mode::cases(), '172.18.0.1', [
            new AllowedDomain('play.example.net', false, false, 'alphanumeric'),
            new AllowedDomain('own.example.com', true),
        ]));

        $this->widget()
            ->assertFormFieldIsVisible('label')
            ->fillForm(['domain' => 'own.example.com'])
            ->assertFormFieldIsHidden('label')
            ->fillForm(['mode' => 'both'])
            ->callAction('apply')
            ->assertNotified('Restart the server to apply the change.');

        $this->assertSame([['linkId' => 'link-1', 'customDomain' => 'own.example.com', 'targetHost' => '172.18.0.1', 'targetPort' => 25565]], $this->provider->bodies('POST routes'));
    }

    public function test_restart_now_is_offered_after_a_switch_and_restarts_through_wings(): void
    {
        $this->nodeConnected();
        $this->widget()->assertActionHidden('restart');

        $this->widget()
            ->fillForm(['mode' => 'both'])
            ->callAction('apply')
            ->assertActionVisible('restart')
            ->callAction('restart')
            ->assertNotified('The server is restarting.');

        $this->assertSame(['restart'], $this->power);
    }

    public function test_restart_now_needs_the_restart_permission(): void
    {
        $this->nodeConnected();
        $s = $this->inMode(Mode::Both);
        $s->restartNeeded = true;
        $this->store()->put($s);

        $this->subuser('gateway.mode');
        $this->widget()->assertSee('Restart the server to apply the change.')->assertDontSee('Restart now');
        $this->subuser('gateway.mode', 'control.restart');
        $this->widget()->assertSee('Restart now');
    }

    public function test_a_server_of_another_egg_gets_no_gateway(): void
    {
        $this->nodeConnected();
        GatewaySettings::current()->forceFill(['eggs' => []])->save();

        $this->widget()
            ->assertSee(ModeService::MINECRAFT_ONLY)
            ->assertFormFieldIsHidden('mode')
            ->assertFormFieldIsHidden('domain')
            ->assertActionHidden('apply')
            ->assertActionHidden('changeAddress');
    }

    public function test_a_server_already_behind_the_gateway_keeps_it_until_an_admin_switches_it(): void
    {
        $this->nodeConnected();
        $this->inMode(Mode::Both);
        GatewaySettings::current()->forceFill(['eggs' => []])->save();

        $this->widget()->assertSee(ModeService::NO_LONGER_ALLOWED)->assertFormFieldIsHidden('mode')->assertSee('mc.play.example.net');

        $admin = User::factory()->create();
        $admin->syncRoles(Role::getRootAdmin());
        $this->actingAs($admin);
        $this->assertSame(['direct' => 'Direct', 'both' => 'Both'], $this->widget()->instance()->modeOptions());
    }

    public function test_owner_sees_only_the_node_modes_and_admin_sees_all(): void
    {
        $this->nodeConnected([Mode::Direct, Mode::Both]);
        $this->assertSame(['direct' => 'Direct', 'both' => 'Both'], $this->widget()->instance()->modeOptions());

        $admin = User::factory()->create();
        $admin->syncRoles(Role::getRootAdmin());
        $this->actingAs($admin);
        $this->assertSame(['direct' => 'Direct', 'both' => 'Both', 'gateway_only' => 'Gateway only'], $this->widget()->instance()->modeOptions());
    }

    public function test_switching_to_both_goes_through_the_mode_service(): void
    {
        $this->nodeConnected();

        $this->widget()
            ->assertFormFieldIsVisible('label')
            ->fillForm(['mode' => 'both', 'label' => 'survival'])
            ->callAction('apply')
            ->assertHasNoFormErrors()
            ->assertNotified('Restart the server to apply the change.')
            ->assertSee('Restart the server to apply the change.')
            ->assertSee('survival.play.example.net');

        $this->assertSame([[
            'linkId' => 'link-1', 'subdomain' => 'survival', 'hosterDomain' => 'play.example.net', 'targetHost' => '172.18.0.1', 'targetPort' => 25565,
        ]], $this->provider->bodies('POST routes'));
        $s = $this->store()->get($this->server->id);
        $this->assertSame([Mode::Both, true], [$s->mode, $s->restartNeeded]);
        $this->assertSame(1, Allocation::query()->where('server_id', $this->server->id)->where('ip', '127.0.0.1')->count());
    }

    public function test_a_refused_change_shows_the_sentence_and_changes_nothing(): void
    {
        // Allowed on the node, but the node has no kit.
        $this->store()->putNode(new NodeState($this->server->node_id, null, null, Mode::cases()));

        $this->widget()
            ->fillForm(['mode' => 'both'])
            ->callAction('apply')
            ->assertNotified(ModeService::NOT_CONNECTED);

        $this->assertNull($this->store()->get($this->server->id));
        $this->assertSame([], $this->provider->bodies('POST routes'));
    }

    public function test_a_provider_error_shows_its_sentence(): void
    {
        $this->nodeConnected();
        $this->provider->on('POST routes', 400, ['error' => 'This target is not allowed for your account.', 'code' => 'target_not_allowed']);

        $this->widget()
            ->fillForm(['mode' => 'both', 'label' => 'survival'])
            ->callAction('apply')
            ->assertNotified('This target is not allowed for your account.');

        $this->assertNull($this->store()->get($this->server->id));
    }

    public function test_the_label_field_shows_the_remembered_address(): void
    {
        $this->nodeConnected();
        $this->inMode(Mode::Direct);

        $this->widget()->assertFormFieldIsVisible('label')->assertFormSet(['label' => 'mc', 'domain' => 'play.example.net'])->assertActionHasLabel('changeAddress', 'Save address');
    }

    public function test_real_ip_is_only_offered_in_gateway_only(): void
    {
        $this->nodeConnected();
        $this->inMode(Mode::Both);
        $this->widget()->assertFormFieldIsHidden('real_ip');

        $this->inMode(Mode::GatewayOnly);
        $this->provider->on('PATCH routes/mc.play.example.net/proxy-protocol', 200, ['success' => true]);
        $this->widget()
            ->assertFormFieldIsVisible('real_ip')
            ->assertSee('proxies.proxy-protocol: true')
            ->assertSee('config/paper-global.yml')
            ->assertSee('haproxy-protocol = true')
            ->set('data.real_ip', true);

        $this->assertSame([['enabled' => true]], $this->provider->bodies('PATCH routes/mc.play.example.net/proxy-protocol'));
        $this->assertTrue($this->store()->get($this->server->id)->proxyProtocol);
    }

    public function test_a_failed_switch_flips_back_and_says_why(): void
    {
        $this->nodeConnected();
        $this->inMode(Mode::Both);
        $this->provider->on('PUT routes/mc.play.example.net/bedrock', 403, ['error' => 'Bedrock is not part of your plan.', 'code' => 'forbidden']);

        $this->widget()
            ->set('data.bedrock', true)
            ->assertNotified('Bedrock is not part of your plan.')
            ->assertFormSet(['bedrock' => false]);

        $this->assertFalse($this->store()->get($this->server->id)->bedrock);
    }

    public function test_the_floodgate_key_is_a_file_download_never_in_the_page(): void
    {
        $this->nodeConnected();
        $this->inMode(Mode::Both, bedrock: true);
        $this->provider->on('GET routes/mc.play.example.net/floodgate-key', 200, ['domain' => 'mc.play.example.net', 'key' => base64_encode(self::FLOODGATE)]);

        $this->widget()
            ->assertSee('plugins/floodgate/key.pem')
            ->assertDontSee(self::FLOODGATE)
            ->callAction('floodgateKey')
            ->assertFileDownloaded('key.pem', self::FLOODGATE)
            ->assertDontSee(self::FLOODGATE)
            ->assertDontSee(base64_encode(self::FLOODGATE));
    }

    /** Pelican renders actions as icon buttons by default (ButtonStyle), so an action without an icon is invisible. */
    public function test_every_action_has_an_icon(): void
    {
        $this->nodeConnected();
        $this->inMode(Mode::Both, bedrock: true);

        $this->widget()
            ->assertActionHasIcon('apply', TablerIcon::Check)
            ->assertActionHasIcon('changeAddress', TablerIcon::Pencil)
            ->assertActionHasIcon('restart', TablerIcon::Reload)
            ->assertActionHasIcon('floodgateKey', TablerIcon::Download);
    }

    public function test_status_line_and_last_error(): void
    {
        $this->nodeConnected();
        $s = $this->inMode(Mode::Both);
        $s->lastError = 'This node is not connected to the gateway.';
        $this->store()->put($s);
        $this->provider->on('GET link-kits', 200, ['items' => [['link_id' => 'link-1', 'online' => true]]]);
        $this->provider->on('GET routes', 200, ['items' => [['domain' => 'mc.play.example.net', 'link_id' => 'link-1', 'target_ip' => '172.18.0.1', 'target_port' => 25565]]]);
        $this->provider->on('GET players', 200, ['items' => [['domain' => 'mc.play.example.net', 'java' => 3, 'bedrock' => 1]]]);

        $this->widget()
            ->assertSee('Route active, link online, 3 Java and 1 Bedrock players.')
            ->assertSee('This node is not connected to the gateway.');
    }

    /** Found live: a letters-only hoster domain refused the short id the field used to offer. */
    public function test_the_offered_label_follows_the_validation_and_the_server_name(): void
    {
        $this->nodeConnected();
        $this->server->update(['name' => 'Paper A 1.21']);
        $this->provider->on('GET regions', 200, ['hosterDomains' => [['domain' => 'eu.example.net', 'validation' => 'letters']], 'items' => []]);

        $this->widget()->assertFormSet(['label' => 'papera']);
    }

    /** Found live: leaving Gateway only with Real player IP on locked everybody out, and nothing said so. */
    public function test_leaving_gateway_only_with_real_ip_asks_first(): void
    {
        $this->nodeConnected();
        $s = $this->inMode(Mode::GatewayOnly);
        $s->proxyProtocol = true;
        $s->publicPort = 25565;
        $this->store()->put($s);

        $this->widget()
            ->fillForm(['mode' => 'both'])
            ->mountAction('apply')
            ->assertActionMounted('apply')
            ->assertMountedActionModalSee('Turn off proxy-protocol');
    }

    public function test_a_plain_switch_needs_no_confirmation(): void
    {
        $this->nodeConnected();
        $this->widget()
            ->fillForm(['mode' => 'both'])
            ->mountAction('apply')
            ->assertActionNotMounted('apply')
            ->assertNotified('Restart the server to apply the change.');
    }

    /** Found live: a server on a node without a link still read "Route active, link online". */
    public function test_a_node_without_a_link_is_not_shown_as_active(): void
    {
        $this->inMode(Mode::Both); // the server's node has no link
        $this->provider->on('GET routes', 200, ['items' => [['domain' => 'mc.play.example.net', 'link_id' => 'link-1', 'target_ip' => '172.18.0.1', 'target_port' => 25565]]]);

        $this->widget()
            ->assertSee('This node is not connected to the gateway.')
            ->assertDontSee('Route active');
    }

    public function test_a_status_read_that_fails_says_so(): void
    {
        $this->nodeConnected();
        $this->inMode(Mode::Both);
        $this->provider->on('GET link-kits', 503, ['error' => 'The gateway is under maintenance.', 'code' => 'unavailable']);

        $this->widget()->assertOk()->assertSee('The gateway is under maintenance.');
    }

    public function test_the_restart_banner_clears_once_the_server_started_after_the_change(): void
    {
        $this->nodeConnected();
        $s = $this->inMode(Mode::Both);
        $s->restartNeeded = true;
        $this->store()->put($s);
        $this->store()->markRestartNeeded($this->server->id, time() - 100);

        // Running for 200 s: started before the change, the banner stays.
        $this->details = ['state' => 'running', 'utilization' => ['uptime' => 200_000]];
        $this->widget()->assertSee('Restart the server to apply the change.');
        $this->assertTrue($this->store()->get($this->server->id)->restartNeeded);

        // Running for 50 s: started after the change, the banner goes.
        cache()->flush();
        $this->details = ['state' => 'running', 'utilization' => ['uptime' => 50_000]];
        $this->widget()->assertDontSee('Restart the server to apply the change.');
        $this->assertFalse($this->store()->get($this->server->id)->restartNeeded);
    }

    /** Clearing the banner writes only the flag: a mode change made during the Wings call must survive. */
    public function test_clearing_the_banner_keeps_a_change_made_meanwhile(): void
    {
        $this->nodeConnected();
        $s = $this->inMode(Mode::Both);
        $s->restartNeeded = true;
        $this->store()->put($s);
        $this->store()->markRestartNeeded($this->server->id, time() - 100);
        $this->details = ['state' => 'running', 'utilization' => ['uptime' => 50_000]];
        $this->duringWings = function () {
            $now = $this->store()->get($this->server->id);
            $now->mode = Mode::GatewayOnly;
            $now->publicPort = 25565;
            $this->store()->put($now);
        };

        $this->widget();

        $after = $this->store()->get($this->server->id);
        $this->assertSame(Mode::GatewayOnly, $after->mode);
        $this->assertSame(25565, $after->publicPort);
        $this->assertFalse($after->restartNeeded);
    }

    public function test_the_change_lock_outlives_a_slow_change(): void
    {
        $this->assertSame(300, \Dylaris\GatewayPelican\GatewayLock::CHANGE_SECONDS);
    }

    public function test_the_restart_time_is_recorded_when_the_flag_is_set(): void
    {
        $s = $this->inMode(Mode::Both);
        $this->assertNull($this->store()->restartNeededSince($this->server->id));

        $s->restartNeeded = true;
        $this->store()->put($s);
        $since = $this->store()->restartNeededSince($this->server->id);
        $this->assertEqualsWithDelta(time(), $since, 5);

        $this->store()->put($s); // a later write with the flag still set keeps the first time
        $this->assertSame($since, $this->store()->restartNeededSince($this->server->id));

        $s->restartNeeded = false;
        $this->store()->put($s);
        $this->assertNull($this->store()->restartNeededSince($this->server->id));
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
        $lock = Cache::lock('dylaris-gateway-reconcile', 600);
        $this->assertTrue($lock->get());

        $this->widget()
            ->fillForm(['mode' => 'direct'])
            ->callAction('apply')
            ->assertNotified(ModeService::BUSY)
            ->set('data.real_ip', true)
            ->assertNotified(ModeService::BUSY)
            ->set('data.bedrock', true)
            ->assertNotified(ModeService::BUSY);

        $s = $this->store()->get($this->server->id);
        $this->assertSame([Mode::GatewayOnly, false, false], [$s->mode, $s->proxyProtocol, $s->bedrock]);
        $this->assertSame(0, $this->providerCalls('DELETE routes/mc.play.example.net'));
        $this->assertSame('The gateway is being updated, try again in a moment.', ModeService::BUSY);

        $lock->release();
        $this->inMode(Mode::Both);
        $this->provider->on('DELETE routes/mc.play.example.net', 200, ['success' => true]);
        $this->widget()->fillForm(['mode' => 'direct'])->callAction('apply')->assertNotified('Restart the server to apply the change.');
        $this->assertTrue(Cache::lock('dylaris-gateway-reconcile', 1)->get(), 'the change released the lock');
    }

    /** A console view must not spend the provider's read budget every time. */
    public function test_page_views_reuse_the_label_and_the_status(): void
    {
        $this->nodeConnected();
        $this->widget();
        $first = $this->providerCalls('GET regions');
        $this->widget();
        $this->assertSame($first, $this->providerCalls('GET regions'), 'label suggestion and domains cached');

        $this->inMode(Mode::Both);
        $this->widget();
        $this->widget();
        $this->assertSame(1, $this->providerCalls('GET routes'), 'status cached per server');
    }

    public function test_page_views_wait_while_the_provider_asked_to_wait(): void
    {
        $this->nodeConnected();
        $this->store()->put(new ServerState(99999, Mode::Direct, '', '', $this->server->node_id, 25565, retryAfterUntil: time() + 60));
        $this->widget()->assertOk();
        $this->inMode(Mode::Both);
        $s = $this->store()->get($this->server->id);
        $s->retryAfterUntil = time() + 60;
        $this->store()->put($s);
        $this->widget()->assertOk()->assertSee(ModeService::PROVIDER_BUSY);

        $this->assertSame([], $this->provider->calls);
    }

    /** Parity with Pterodactyl. */
    public function test_a_mode_the_node_no_longer_offers_is_said(): void
    {
        $this->nodeConnected([Mode::Direct]);
        $this->inMode(Mode::Both);

        $this->widget()->assertSee(ModeService::notOffered('Both'));

        $admin = User::factory()->create();
        $admin->syncRoles(Role::getRootAdmin());
        $this->actingAs($admin);
        $this->widget()->assertDontSee('no longer offered');
    }
}
