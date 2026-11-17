<?php

namespace Dylaris\GatewayPelican\Tests;

use App\Enums\TablerIcon;
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

    public function test_the_api_key_is_stored_encrypted(): void
    {
        (new DylarisGatewayPlugin())->saveSettings(['provider_url' => 'https://provider.example/', 'api_key' => self::KEY]);

        $raw = DB::table('dylaris_gateway_settings')->value('api_key');
        $this->assertNotSame(self::KEY, $raw);
        $this->assertStringNotContainsString(self::KEY, $raw);
        $this->assertSame(self::KEY, Crypt::decryptString($raw));
        $this->assertSame('https://provider.example', GatewaySettings::current()->provider_url);
    }

    public function test_a_blank_key_keeps_the_saved_one(): void
    {
        $plugin = new DylarisGatewayPlugin();
        $plugin->saveSettings(['provider_url' => 'https://provider.example', 'api_key' => self::KEY]);
        $plugin->saveSettings(['provider_url' => 'https://provider.example/', 'api_key' => '']);
        $plugin->saveSettings(['provider_url' => 'https://provider.example', 'api_key' => null]);

        $this->assertSame(1, DB::table('dylaris_gateway_settings')->count());
        $s = GatewaySettings::current();
        $this->assertSame(self::KEY, $s->api_key);
        $this->assertSame('https://provider.example', $s->provider_url);
    }

    public function test_the_form_never_gets_the_key(): void
    {
        $plugin = new DylarisGatewayPlugin();
        $this->assertSame(['provider_url' => 'https://panel.dylaris.com', 'api_key' => null], $plugin->getSettingsFormData());

        $plugin->saveSettings(['provider_url' => 'https://provider.example', 'api_key' => self::KEY]);
        $this->assertSame(['provider_url' => 'https://provider.example', 'api_key' => null], $plugin->getSettingsFormData());
        $this->assertArrayNotHasKey('api_key', GatewaySettings::current()->toArray());
    }

    public function test_an_invalid_url_is_not_saved(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DylarisGatewayPlugin())->saveSettings(['provider_url' => 'ftp://provider.example', 'api_key' => self::KEY]);
    }

    public function test_the_settings_form_has_the_fields(): void
    {
        $names = array_map(fn ($c) => method_exists($c, 'getName') ? $c->getName() : null, (new DylarisGatewayPlugin())->getSettingsForm());

        $this->assertContains('provider_url', $names);
        $this->assertContains('api_key', $names);
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
        (new DylarisGatewayPlugin())->saveSettings(['provider_url' => 'https://provider.example', 'api_key' => self::KEY]);

        $this->assertInstanceOf(ProviderClient::class, $this->app->make(ProviderClient::class));
        $this->assertInstanceOf(ModeService::class, $this->app->make(ModeService::class));
        $this->assertInstanceOf(Reconciler::class, $this->app->make(Reconciler::class));
        $this->assertInstanceOf(StatusService::class, $this->app->make(StatusService::class));
    }

    public function test_without_settings_the_client_says_what_to_do(): void
    {
        $this->expectExceptionMessage('enter the provider URL and API key');
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

    public function test_the_connection_test_sends_the_saved_key_only_to_the_saved_url(): void
    {
        $refused = function (string $url, string $key, string $want): void {
            try {
                DylarisGatewayPlugin::testCredentials($url, $key);
                $this->fail("credentials returned for $url");
            } catch (GatewayUnavailable $e) {
                $this->assertSame($want, $e->getMessage(), $url);
            }
        };
        $refused('', '', 'Enter a provider URL and an API key first.');
        (new DylarisGatewayPlugin())->saveSettings(['provider_url' => 'https://provider.example', 'api_key' => self::KEY]);

        $this->assertSame(['https://provider.example', self::KEY], DylarisGatewayPlugin::testCredentials('', ''));
        $this->assertSame(['https://provider.example', self::KEY], DylarisGatewayPlugin::testCredentials(' https://provider.example/ ', ''));
        $this->assertSame(['https://attacker.example', 'typed-key'], DylarisGatewayPlugin::testCredentials('https://attacker.example', 'typed-key'));
        foreach (['https://attacker.example', 'https://provider.example.attacker.example', 'http://provider.example'] as $url) {
            $refused($url, '', DylarisGatewayPlugin::KEY_FOR_OTHER_URL);
        }
        $refused('ftp://provider.example', 'typed-key', 'Enter a provider URL and an API key first.');
        $this->assertSame('Enter the API key to test a different provider URL: the saved key is only sent to the saved URL.', DylarisGatewayPlugin::KEY_FOR_OTHER_URL);
    }

    /** Pelican swallows exceptions from saveSettings, so a bad URL must be stopped by the form. */
    public function test_the_form_refuses_a_non_http_url(): void
    {
        foreach (['ftp://provider.example', 'javascript://provider.example', 'file:///etc/passwd'] as $url) {
            Livewire::test(SettingsFormHost::class)
                ->fillForm(['provider_url' => $url, 'api_key' => self::KEY])
                ->call('save')
                ->assertHasFormErrors(['provider_url']);
        }
        $this->assertSame(0, DB::table('dylaris_gateway_settings')->count());

        Livewire::test(SettingsFormHost::class)
            ->fillForm(['provider_url' => 'https://provider.example', 'api_key' => self::KEY])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('https://provider.example', GatewaySettings::current()->provider_url);
    }

    public function test_an_http_url_warns_that_the_key_travels_unencrypted(): void
    {
        $warning = 'This URL is not https: the API key would travel unencrypted.';
        Livewire::test(SettingsFormHost::class)
            ->assertDontSee($warning)
            ->fillForm(['provider_url' => 'http://provider.example'])
            ->assertSee($warning);
    }

    public function test_a_key_the_app_key_cannot_decrypt_is_reported_not_crashed_on(): void
    {
        (new DylarisGatewayPlugin())->saveSettings(['provider_url' => 'https://provider.example', 'api_key' => self::KEY]);
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
        (new DylarisGatewayPlugin())->saveSettings(['provider_url' => 'https://provider.example', 'api_key' => self::KEY]);
        $this->assertSame(self::KEY, GatewaySettings::current()->apiKey());
    }

    /** Someone who may edit the settings but never saw the key must not send it to a URL of their own. */
    public function test_a_new_url_needs_the_key_typed_again(): void
    {
        (new DylarisGatewayPlugin())->saveSettings(['provider_url' => 'https://provider.example', 'api_key' => self::KEY]);

        Livewire::test(SettingsFormHost::class)
            ->fillForm(['provider_url' => 'https://attacker.example', 'api_key' => ''])
            ->call('save')
            ->assertHasFormErrors(['provider_url'])
            ->assertSee(DylarisGatewayPlugin::URL_NEEDS_KEY);
        $this->assertSame('https://provider.example', GatewaySettings::current()->provider_url);
        $this->assertSame('Enter the API key again when you change the provider URL.', DylarisGatewayPlugin::URL_NEEDS_KEY);

        // Behind the form too: a refused save keeps both values.
        try {
            (new DylarisGatewayPlugin())->saveSettings(['provider_url' => 'https://attacker.example', 'api_key' => null]);
            $this->fail('saved a new URL with the old key');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(DylarisGatewayPlugin::URL_NEEDS_KEY, $e->getMessage());
        }
        $this->assertSame('https://provider.example', GatewaySettings::current()->provider_url);

        // The same URL with a blank key, and a new URL with a key, both save.
        Livewire::test(SettingsFormHost::class)->fillForm(['provider_url' => 'https://provider.example/', 'api_key' => ''])->call('save')->assertHasNoFormErrors();
        Livewire::test(SettingsFormHost::class)->fillForm(['provider_url' => 'https://other.example', 'api_key' => 'new-key'])->call('save')->assertHasNoFormErrors();
        $this->assertSame(['https://other.example', 'new-key'], [GatewaySettings::current()->provider_url, GatewaySettings::current()->api_key]);
    }
}
