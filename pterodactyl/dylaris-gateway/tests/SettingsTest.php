<?php

namespace Dylaris\GatewayPteroTests;

use Dylaris\GatewayPanel\ModeService;
use Dylaris\GatewayPanel\Provider\Account;
use Dylaris\GatewayPanel\Provider\ProviderClient;
use Dylaris\GatewayPanel\Reconciler;
use Dylaris\GatewayPanel\StatusService;
use Dylaris\GatewayPtero\GatewaySettings;
use Dylaris\GatewayPtero\GatewayUnavailable;
use Dylaris\GatewayPtero\TestConnectionController;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettingValueGuard;

class SettingsTest extends ExtensionTestCase
{
    private const KEY = 'dgk_test_0123456789abcdef';

    private const SETTINGS = '/api/admin/extensions/dylaris-gateway/settings';

    private const TEST_CONNECTION = '/api/admin/extensions/dylaris-gateway/test-connection';

    private ?User $admin = null;

    /** Saves through the panel's own admin settings endpoint, as the auto-rendered form does. */
    private function save(array $settings): TestResponse
    {
        // One admin per test: a second actingAs() user in the same test is refused as not being a panel session (403).
        $this->admin ??= User::factory()->create(['root_admin' => true]);

        return $this->actingAs($this->admin)->patchJson(self::SETTINGS, ['settings' => $settings]);
    }

    private function storedKey(): ?object
    {
        return DB::table('extension_settings')->where('extension', self::ID)->where('scope', 'global')->where('key', 'api_key')->first();
    }

    public function test_the_api_key_is_stored_encrypted(): void
    {
        $this->save(['api_key' => self::KEY])->assertOk();

        $row = $this->storedKey();
        $this->assertTrue((bool) $row->is_secret);
        $this->assertStringNotContainsString(self::KEY, $row->value);
        $this->assertSame(self::KEY, ExtensionSettingValueGuard::decode($row->value, true));
        $this->assertSame(self::KEY, GatewaySettings::apiKey());
    }

    public function test_the_admin_form_never_gets_the_key(): void
    {
        $this->save(['api_key' => self::KEY])
            ->assertOk()
            ->assertDontSee(self::KEY);

        $this->getJson(self::SETTINGS)
            ->assertOk()
            ->assertDontSee(self::KEY)
            ->assertJsonPath('data.schema.0.input', 'api_key')
            ->assertJsonPath('data.schema.0.field', 'password')
            ->assertJsonPath('data.schema.0.value', ExtensionSettingDefinition::MASK);
    }

    public function test_a_blank_or_masked_key_keeps_the_saved_one(): void
    {
        $this->save(['api_key' => self::KEY])->assertOk();
        $this->save(['api_key' => ''])->assertOk();
        $this->save(['api_key' => ExtensionSettingDefinition::MASK])->assertOk();
        $this->save([])->assertOk();

        $this->assertSame(self::KEY, GatewaySettings::apiKey());
    }

    public function test_services_resolve_from_saved_settings(): void
    {
        $this->save(['api_key' => self::KEY])->assertOk();

        $this->assertInstanceOf(ProviderClient::class, $this->app->make(ProviderClient::class));
        $this->assertInstanceOf(ModeService::class, $this->app->make(ModeService::class));
        $this->assertInstanceOf(Reconciler::class, $this->app->make(Reconciler::class));
        $this->assertInstanceOf(StatusService::class, $this->app->make(StatusService::class));
    }

    public function test_without_settings_the_client_says_what_to_do(): void
    {
        $this->expectException(GatewayUnavailable::class);
        $this->expectExceptionMessage('enter the API key in the extension settings');
        $this->app->make(ProviderClient::class);
    }

    public function test_account_problem(): void
    {
        $ok = ['product' => 'managed', 'managed' => true, 'suspended' => false, 'scopes' => ['gateway.read', 'gateway.write']];
        $cases = [
            'managed with both scopes' => [$ok, null],
            'route-only product' => [['product' => 'route_only', 'managed' => false] + $ok, 'This key has no managed gateway plan.'],
            'no product at all' => [['product' => '', 'managed' => false] + $ok, 'This key has no managed gateway plan.'],
            'suspended' => [['suspended' => true] + $ok, 'This account is suspended.'],
            'no write scope' => [['scopes' => ['gateway.read']] + $ok, 'This key lacks the gateway.write permission.'],
            'no read scope' => [['scopes' => ['gateway.write']] + $ok, 'This key lacks the gateway.read permission.'],
            'no scopes' => [['scopes' => []] + $ok, 'This key lacks the gateway.read and gateway.write permissions.'],
        ];
        foreach ($cases as $name => [$account, $want]) {
            $this->assertSame($want, TestConnectionController::accountProblem(Account::fromArray($account)), $name);
        }
    }

    public function test_connection_problem_reads_the_account_and_names_provider_errors(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['success' => true, 'account' => ['product' => 'managed', 'managed' => true, 'scopes' => ['gateway.read']]])),
            new Response(401, [], json_encode(['success' => false, 'error' => 'Invalid API key.', 'code' => 'unauthorized'])),
        ]));
        $stack->push(Middleware::history($history));
        $client = new ProviderClient('https://provider.example', self::KEY, new Client(['handler' => $stack]));

        $this->assertSame('This key lacks the gateway.write permission.', TestConnectionController::connectionProblem($client));
        $this->assertSame('Invalid API key.', TestConnectionController::connectionProblem($client));
        $this->assertSame('https://provider.example/api/external/gateway/account', (string) $history[0]['request']->getUri());
    }

    public function test_test_connection_is_for_root_admins_only(): void
    {
        $this->actingAs(User::factory()->create(['root_admin' => false]))
            ->postJson(self::TEST_CONNECTION, ['api_key' => self::KEY])
            ->assertForbidden();
    }

    public function test_a_key_the_app_key_cannot_decrypt_is_reported_not_crashed_on(): void
    {
        $this->save(['api_key' => self::KEY])->assertOk();
        // What a changed APP_KEY looks like from here: a payload that no longer decrypts.
        DB::table('extension_settings')->where('extension', self::ID)->where('key', 'api_key')
            ->update(['value' => json_encode(Crypt::encryptString('x') . 'tampered')]);

        $this->assertSame('The stored API key can no longer be read. Enter it again.', GatewaySettings::KEY_UNREADABLE);
        try {
            $this->app->make(ProviderClient::class);
            $this->fail('the client resolved without a readable key');
        } catch (GatewayUnavailable $e) {
            $this->assertSame(GatewaySettings::KEY_UNREADABLE, $e->getMessage());
        }
        $this->postJson(self::TEST_CONNECTION, [])->assertOk()->assertJsonPath('data.message', GatewaySettings::KEY_UNREADABLE);

        // Entering it again fixes it.
        $this->save(['api_key' => self::KEY])->assertOk();
        $this->assertSame(self::KEY, GatewaySettings::apiKey());
    }

    protected function tearDown(): void
    {
        unset($_SERVER[GatewaySettings::URL_ENV], $_ENV[GatewaySettings::URL_ENV]);
        parent::tearDown();
    }

    /** 0.2.0: the provider is fixed. The settings have no URL, so no key can be pointed anywhere else. */
    public function test_the_provider_url_is_fixed_and_only_an_env_var_overrides_it(): void
    {
        $this->save(['api_key' => self::KEY])->assertOk();
        $inputs = array_column($this->getJson(self::SETTINGS)->assertOk()->json('data.schema'), 'input');
        $this->assertNotContains('provider_url', $inputs);
        $this->assertSame('https://panel.dylaris.com', GatewaySettings::providerUrl());

        // A URL saved by 0.1.0 is not read any more.
        DB::table('extension_settings')->insert(['extension' => self::ID, 'scope' => 'global', 'key' => 'provider_url', 'value' => json_encode('https://attacker.example'), 'is_secret' => false]);
        $this->assertSame('https://panel.dylaris.com', GatewaySettings::providerUrl());
        $url = (fn () => $this->baseUrl)->call($this->app->make(ProviderClient::class));
        $this->assertSame('https://panel.dylaris.com', $url);

        $_SERVER[GatewaySettings::URL_ENV] = $_ENV[GatewaySettings::URL_ENV] = 'https://staging.example/ ';
        $this->assertSame('https://staging.example', GatewaySettings::providerUrl());
    }

    public function test_test_connection_needs_a_key_and_names_the_provider(): void
    {
        $this->actingAs(User::factory()->create(['root_admin' => true]));

        $this->postJson(self::TEST_CONNECTION, [])
            ->assertOk()
            ->assertExactJson(['data' => ['ok' => false, 'message' => 'Enter an API key first.', 'provider_url' => 'https://panel.dylaris.com']]);
    }

    public function test_the_egg_list_is_a_setting_and_defaults_to_the_java_minecraft_eggs(): void
    {
        $this->save(['api_key' => self::KEY])->assertOk();
        $schema = array_column($this->getJson(self::SETTINGS)->assertOk()->json('data.schema'), null, 'input');

        $this->assertSame('multiselect', $schema['eggs']['field']);
        $this->assertEqualsCanonicalizing(GatewaySettings::detectedEggIds(), $schema['eggs']['value']);
        $paper = Egg::query()->where('name', 'Paper')->first();
        $rust = Egg::query()->where('name', 'Rust')->first();
        $this->assertNotNull($paper);
        $this->assertTrue(GatewaySettings::eggAllowed($paper));
        if ($rust !== null) {
            $this->assertFalse(GatewaySettings::eggAllowed($rust));
        }
    }

    public function test_the_admin_egg_list_decides_once_saved(): void
    {
        $paper = Egg::query()->where('name', 'Paper')->firstOrFail();
        $other = Egg::query()->whereNotIn('id', GatewaySettings::detectedEggIds())->firstOrFail();

        $this->save(['eggs' => [$other->id]])->assertOk();
        $this->assertTrue(GatewaySettings::eggAllowed($other));
        $this->assertFalse(GatewaySettings::eggAllowed($paper));

        $this->save(['eggs' => GatewaySettings::detectedEggIds()])->assertOk();
        $this->assertTrue(GatewaySettings::eggAllowed($paper));
        $this->assertFalse(GatewaySettings::eggAllowed($other));
    }
}
