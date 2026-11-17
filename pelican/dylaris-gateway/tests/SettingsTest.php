<?php

namespace Dylaris\GatewayPelican\Tests;

use App\Enums\TablerIcon;
use App\Models\Egg;
use Dylaris\GatewayPanel\ModeService;
use Dylaris\GatewayPanel\Provider\Account;
use Dylaris\GatewayPanel\Provider\ProviderClient;
use Dylaris\GatewayPanel\Reconciler;
use Dylaris\GatewayPanel\StatusService;
use Dylaris\GatewayPelican\DylarisGatewayPlugin;
use Dylaris\GatewayPelican\GatewaySettings;
use Dylaris\GatewayPelican\GatewayUnavailable;
use Livewire\Livewire;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class SettingsTest extends PluginTestCase
{
    private const KEY = 'dgk_test_0123456789abcdef';

    protected function tearDown(): void
    {
        unset($_SERVER[GatewaySettings::URL_ENV], $_ENV[GatewaySettings::URL_ENV]);
        parent::tearDown();
    }

    public function test_the_api_key_is_stored_encrypted(): void
    {
        (new DylarisGatewayPlugin())->saveSettings(['api_key' => self::KEY]);

        $raw = DB::table('dylaris_gateway_settings')->value('api_key');
        $this->assertNotSame(self::KEY, $raw);
        $this->assertStringNotContainsString(self::KEY, $raw);
        $this->assertSame(self::KEY, Crypt::decryptString($raw));
    }

    public function test_a_blank_key_keeps_the_saved_one(): void
    {
        $plugin = new DylarisGatewayPlugin();
        $plugin->saveSettings(['api_key' => self::KEY]);
        $plugin->saveSettings(['api_key' => '']);
        $plugin->saveSettings(['api_key' => null]);

        $this->assertSame(1, DB::table('dylaris_gateway_settings')->count());
        $this->assertSame(self::KEY, GatewaySettings::current()->api_key);
    }

    public function test_the_form_never_gets_the_key(): void
    {
        $plugin = new DylarisGatewayPlugin();
        $this->assertNull($plugin->getSettingsFormData()['api_key']);

        $plugin->saveSettings(['api_key' => self::KEY]);
        $this->assertNull($plugin->getSettingsFormData()['api_key']);
        $this->assertArrayNotHasKey('api_key', GatewaySettings::current()->toArray());
    }

    /** 0.2.0: the provider is fixed. The form has no URL field, so no key can be pointed anywhere else. */
    public function test_the_provider_url_is_fixed_and_only_an_env_var_overrides_it(): void
    {
        $names = array_map(fn ($c) => $c->getName(), (new DylarisGatewayPlugin())->getSettingsForm());
        $this->assertSame(['api_key', 'eggs'], $names);
        $this->assertSame('https://panel.dylaris.com', GatewaySettings::providerUrl());

        // A URL saved by 0.1.0 is not read any more.
        DB::table('dylaris_gateway_settings')->insert(['id' => 1, 'provider_url' => 'https://attacker.example']);
        $this->assertSame('https://panel.dylaris.com', GatewaySettings::providerUrl());

        $_SERVER[GatewaySettings::URL_ENV] = $_ENV[GatewaySettings::URL_ENV] = 'https://staging.example/ ';
        $this->assertSame('https://staging.example', GatewaySettings::providerUrl());
        Livewire::test(SettingsFormHost::class)->assertSee('Calls go only to https://staging.example.');
    }

    public function test_the_provider_url_is_used_for_every_call(): void
    {
        (new DylarisGatewayPlugin())->saveSettings(['api_key' => self::KEY]);
        $client = $this->app->make(ProviderClient::class);

        $url = (fn () => $this->baseUrl)->call($client);
        $this->assertSame('https://panel.dylaris.com', $url);
    }

    /** Pelican renders actions as icon buttons by default (ButtonStyle), so an action without an icon is invisible. */
    public function test_the_connection_test_action_has_an_icon(): void
    {
        $key = collect((new DylarisGatewayPlugin())->getSettingsForm())->first(fn ($c) => $c->getName() === 'api_key');
        $icons = array_map(fn ($a) => $a->getIcon(), $key->getHintActions());

        $this->assertSame([TablerIcon::PlugConnected], $icons);
    }

    public function test_services_resolve_from_saved_settings(): void
    {
        (new DylarisGatewayPlugin())->saveSettings(['api_key' => self::KEY]);

        $this->assertInstanceOf(ProviderClient::class, $this->app->make(ProviderClient::class));
        $this->assertInstanceOf(ModeService::class, $this->app->make(ModeService::class));
        $this->assertInstanceOf(Reconciler::class, $this->app->make(Reconciler::class));
        $this->assertInstanceOf(StatusService::class, $this->app->make(StatusService::class));
    }

    public function test_without_settings_the_client_says_what_to_do(): void
    {
        $this->expectExceptionMessage('enter the API key in the plugin settings');
        $this->app->make(ProviderClient::class);
    }

    /** @return iterable<string, array{array, ?string}> */
    public static function accounts(): iterable
    {
        $ok = ['product' => 'managed', 'managed' => true, 'suspended' => false, 'scopes' => ['gateway.read', 'gateway.write']];
        yield 'managed with both scopes' => [$ok, null];
        yield 'route-only product' => [['product' => 'route_only', 'managed' => false] + $ok, 'This key has no managed gateway plan.'];
        yield 'no product at all' => [['product' => '', 'managed' => false] + $ok, 'This key has no managed gateway plan.'];
        yield 'suspended' => [['suspended' => true] + $ok, 'This account is suspended.'];
        yield 'no write scope' => [['scopes' => ['gateway.read']] + $ok, 'This key lacks the gateway.write permission.'];
        yield 'no read scope' => [['scopes' => ['gateway.write']] + $ok, 'This key lacks the gateway.read permission.'];
        yield 'no scopes' => [['scopes' => []] + $ok, 'This key lacks the gateway.read and gateway.write permissions.'];
    }

    #[DataProvider('accounts')]
    public function test_account_problem(array $account, ?string $want): void
    {
        $this->assertSame($want, DylarisGatewayPlugin::accountProblem(Account::fromArray($account)));
    }

    public function test_connection_problem_reads_the_account_and_names_provider_errors(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['success' => true, 'account' => ['product' => 'managed', 'managed' => true, 'scopes' => ['gateway.read']]])),
            new Response(401, [], json_encode(['success' => false, 'error' => 'Invalid API key.', 'code' => 'unauthorized'])),
        ]));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $client = new ProviderClient('https://provider.example', self::KEY, new Client(['handler' => $stack]));

        $this->assertSame('This key lacks the gateway.write permission.', DylarisGatewayPlugin::connectionProblem($client));
        $this->assertSame('Invalid API key.', DylarisGatewayPlugin::connectionProblem($client));
        $this->assertSame('https://provider.example/api/external/gateway/account', (string) $history[0]['request']->getUri());
    }

    public function test_the_connection_test_uses_the_typed_key_or_the_saved_one(): void
    {
        try {
            DylarisGatewayPlugin::testKey('');
            $this->fail('a key without any saved');
        } catch (GatewayUnavailable $e) {
            $this->assertSame('Enter an API key first.', $e->getMessage());
        }
        (new DylarisGatewayPlugin())->saveSettings(['api_key' => self::KEY]);

        $this->assertSame(self::KEY, DylarisGatewayPlugin::testKey(' '));
        $this->assertSame('typed-key', DylarisGatewayPlugin::testKey(' typed-key '));
    }

    public function test_a_key_the_app_key_cannot_decrypt_is_reported_not_crashed_on(): void
    {
        (new DylarisGatewayPlugin())->saveSettings(['api_key' => self::KEY]);
        // What a changed APP_KEY looks like from here: a payload that no longer decrypts.
        DB::table('dylaris_gateway_settings')->update(['api_key' => Crypt::encryptString('x') . 'tampered']);

        Livewire::test(SettingsFormHost::class)->assertOk()->assertSee(GatewaySettings::KEY_UNREADABLE);
        $this->assertSame('The stored API key can no longer be read. Enter it again.', GatewaySettings::KEY_UNREADABLE);
        try {
            $this->app->make(ProviderClient::class);
            $this->fail('the client resolved without a readable key');
        } catch (GatewayUnavailable $e) {
            $this->assertSame(GatewaySettings::KEY_UNREADABLE, $e->getMessage());
        }

        // Entering it again fixes it.
        (new DylarisGatewayPlugin())->saveSettings(['api_key' => self::KEY]);
        $this->assertSame(self::KEY, GatewaySettings::current()->apiKey());
    }

    private function egg(string $name, array $tags, array $features): Egg
    {
        $egg = Egg::query()->firstOrFail()->replicate();
        $egg->forceFill(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => $name, 'tags' => $tags, 'features' => $features])->save();

        return $egg;
    }

    public function test_by_default_only_the_java_minecraft_eggs_may_use_the_gateway(): void
    {
        $paper = $this->egg('Paper test', ['minecraft'], ['eula', 'java_version']);
        $rust = $this->egg('Rust test', ['rust'], ['steam_disk_space']);
        $bedrock = $this->egg('Bedrock Dedicated Server', ['minecraft', 'bedrock'], []);

        $this->assertTrue(GatewaySettings::eggAllowed($paper));
        $this->assertFalse(GatewaySettings::eggAllowed($rust));
        $this->assertFalse(GatewaySettings::eggAllowed($bedrock));
        $this->assertContains($paper->id, DylarisGatewayPlugin::allowedEggIds());
        $this->assertNotContains($rust->id, DylarisGatewayPlugin::allowedEggIds());
    }

    public function test_the_admin_list_decides_once_saved(): void
    {
        $paper = $this->egg('Paper test', ['minecraft'], ['eula']);
        $rust = $this->egg('Rust test', ['rust'], []);

        Livewire::test(SettingsFormHost::class)
            ->assertFormSet(['eggs' => DylarisGatewayPlugin::detectedEggIds()])
            ->fillForm(['eggs' => [$rust->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([$rust->id], GatewaySettings::current()->eggs);
        $this->assertTrue(GatewaySettings::eggAllowed($rust));
        $this->assertFalse(GatewaySettings::eggAllowed($paper));
    }

    /** Until saved the detection follows new eggs; once saved the list is exact, like on Pterodactyl. */
    public function test_an_egg_imported_after_saving_needs_ticking(): void
    {
        $before = $this->egg('Purpur before', ['minecraft'], ['java_version']);
        $this->assertTrue(GatewaySettings::eggAllowed($before), 'unsaved: detected');

        (new DylarisGatewayPlugin())->saveSettings(['api_key' => self::KEY, 'eggs' => DylarisGatewayPlugin::detectedEggIds()]);
        $later = $this->egg('Purpur later', ['minecraft'], ['java_version']);

        $this->assertTrue(GatewaySettings::eggAllowed($before));
        $this->assertFalse(GatewaySettings::eggAllowed($later));
    }
}
