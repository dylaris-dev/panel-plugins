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
        $this->save(['provider_url' => 'https://provider.example/', 'api_key' => self::KEY])->assertOk();

        $row = $this->storedKey();
        $this->assertTrue((bool) $row->is_secret);
        $this->assertStringNotContainsString(self::KEY, $row->value);
        $this->assertSame(self::KEY, ExtensionSettingValueGuard::decode($row->value, true));
        $this->assertSame(self::KEY, GatewaySettings::apiKey());
        $this->assertSame('https://provider.example', GatewaySettings::providerUrl());
    }

    public function test_the_admin_form_never_gets_the_key(): void
    {
        $this->save(['provider_url' => 'https://provider.example', 'api_key' => self::KEY])
            ->assertOk()
            ->assertDontSee(self::KEY);

        $this->getJson(self::SETTINGS)
            ->assertOk()
            ->assertDontSee(self::KEY)
            ->assertJsonPath('data.schema.1.input', 'api_key')
            ->assertJsonPath('data.schema.1.field', 'password')
            ->assertJsonPath('data.schema.1.value', ExtensionSettingDefinition::MASK)
            ->assertJsonPath('data.schema.0.value', 'https://provider.example');
    }

    public function test_a_blank_or_masked_key_keeps_the_saved_one(): void
    {
        $this->save(['provider_url' => 'https://provider.example', 'api_key' => self::KEY])->assertOk();
        $this->save(['provider_url' => 'https://provider.example', 'api_key' => ''])->assertOk();
        $this->save(['provider_url' => 'https://provider.example', 'api_key' => ExtensionSettingDefinition::MASK])->assertOk();
        $this->save(['provider_url' => 'https://provider.example'])->assertOk();

        $this->assertSame(self::KEY, GatewaySettings::apiKey());
        $this->assertSame('https://provider.example', GatewaySettings::providerUrl());
    }

    public function test_a_non_http_url_is_refused(): void
    {
        foreach (['ftp://provider.example', 'javascript://provider.example', 'file:///etc/passwd', 'not a url'] as $url) {
            $this->save(['provider_url' => $url, 'api_key' => self::KEY])
                ->assertUnprocessable()
                ->assertSee('The provider URL must be an http or https URL.');
        }
        $this->assertSame(0, DB::table('extension_settings')->where('extension', self::ID)->count());
        $this->assertSame(GatewaySettings::DEFAULT_URL, GatewaySettings::providerUrl());
    }

    public function test_services_resolve_from_saved_settings(): void
    {
        $this->save(['provider_url' => 'https://provider.example', 'api_key' => self::KEY])->assertOk();

        $this->assertInstanceOf(ProviderClient::class, $this->app->make(ProviderClient::class));
        $this->assertInstanceOf(ModeService::class, $this->app->make(ModeService::class));
        $this->assertInstanceOf(Reconciler::class, $this->app->make(Reconciler::class));
        $this->assertInstanceOf(StatusService::class, $this->app->make(StatusService::class));
    }

    public function test_without_settings_the_client_says_what_to_do(): void
    {
        $this->expectException(GatewayUnavailable::class);
        $this->expectExceptionMessage('enter the provider URL and API key');
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

    public function test_test_connection_needs_a_key_and_warns_on_http(): void
    {
        $this->actingAs(User::factory()->create(['root_admin' => true]));

        $this->postJson(self::TEST_CONNECTION, [])
            ->assertOk()
            ->assertExactJson(['data' => ['ok' => false, 'message' => 'Enter a provider URL and an API key first.', 'warning' => null]]);
        $this->postJson(self::TEST_CONNECTION, ['provider_url' => 'http://provider.example'])
            ->assertOk()
            ->assertExactJson(['data' => [
                'ok' => false,
                'message' => 'Enter the API key to test a different provider URL: the saved key is only sent to the saved URL.',
                'warning' => 'This URL is not https: the API key would travel unencrypted.',
            ]]);
        $this->postJson(self::TEST_CONNECTION, ['provider_url' => 'ftp://provider.example', 'api_key' => self::KEY])
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.message', 'Enter a provider URL and an API key first.')
            ->assertJsonPath('data.warning', null);
    }

    public function test_test_connection_sends_the_saved_key_only_to_the_saved_url(): void
    {
        $this->save(['provider_url' => 'https://provider.example', 'api_key' => self::KEY])->assertOk();

        foreach (['https://attacker.example', 'https://provider.example.attacker.example', 'http://provider.example'] as $url) {
            $this->postJson(self::TEST_CONNECTION, ['provider_url' => $url])
                ->assertOk()
                ->assertJsonPath('data.ok', false)
                ->assertJsonPath('data.message', TestConnectionController::KEY_FOR_OTHER_URL);
            $this->postJson(self::TEST_CONNECTION, ['provider_url' => $url, 'api_key' => ExtensionSettingDefinition::MASK])
                ->assertJsonPath('data.message', TestConnectionController::KEY_FOR_OTHER_URL);
        }
    }

    public function test_test_connection_is_for_root_admins_only(): void
    {
        $this->actingAs(User::factory()->create(['root_admin' => false]))
            ->postJson(self::TEST_CONNECTION, ['provider_url' => 'https://provider.example', 'api_key' => self::KEY])
            ->assertForbidden();
    }

    public function test_a_key_the_app_key_cannot_decrypt_is_reported_not_crashed_on(): void
    {
        $this->save(['provider_url' => 'https://provider.example', 'api_key' => self::KEY])->assertOk();
        // What a changed APP_KEY looks like from here: a payload that no longer decrypts.
        DB::table('extension_settings')->where('extension', self::ID)->where('key', 'api_key')
            ->update(['value' => json_encode(Crypt::encryptString('x') . 'tampered')]);

        $this->assertSame('The stored API key can no longer be read. Enter it again.', GatewaySettings::KEY_UNREADABLE);
        $this->assertSame('https://provider.example', GatewaySettings::providerUrl(), 'the URL stays readable');
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

    /** An admin who changes the URL must type the key; the saved key never follows a new URL. */
    public function test_a_new_url_needs_the_key_typed_again(): void
    {
        $this->save(['provider_url' => 'https://provider.example', 'api_key' => self::KEY])->assertOk();

        foreach (['', ExtensionSettingDefinition::MASK, null] as $blank) {
            $settings = ['provider_url' => 'https://attacker.example'] + ($blank === null ? [] : ['api_key' => $blank]);
            $this->save($settings)->assertUnprocessable()->assertSee(GatewaySettings::URL_NEEDS_KEY);
        }
        $this->assertSame('https://provider.example', GatewaySettings::providerUrl());
        $this->assertSame('Enter the API key again when you change the provider URL.', GatewaySettings::URL_NEEDS_KEY);

        $this->save(['provider_url' => 'https://provider.example/', 'api_key' => ''])->assertOk();
        $this->save(['provider_url' => 'https://other.example', 'api_key' => 'new-key'])->assertOk();
        $this->assertSame(['https://other.example', 'new-key'], [GatewaySettings::providerUrl(), GatewaySettings::apiKey()]);
    }
}
