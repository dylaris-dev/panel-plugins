<?php

namespace Dylaris\GatewayPteroTests;

use Dylaris\GatewayPanel\DomainEntry;
use Dylaris\GatewayPanel\StateStore;
use Illuminate\Console\Scheduling\Schedule;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Extensions\ExtensionConsoleRegistry;

/**
 * 0.2.0: the panel-wide domain list. The migration from per-node lists is tested on Pelican (SQLite): here MariaDB would
 * commit the test transaction on the first schema change. Its rule is core's (DomainListTest).
 */
class DomainListRoutesTest extends ExtensionTestCase
{
    private const URL = '/api/admin/extensions/dylaris-gateway/domains';

    private FakeProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = FakeProvider::bind($this->app);
    }

    private function store(): StateStore
    {
        return $this->app->make(StateStore::class);
    }

    private function asAdmin(): static
    {
        return $this->actingAs(User::factory()->create(['root_admin' => true]));
    }

    public function test_a_user_who_is_not_a_root_admin_is_refused(): void
    {
        $this->actingAs(User::factory()->create(['root_admin' => false]));

        $this->getJson(self::URL)->assertForbidden();
        $this->putJson(self::URL . '/play.example.net/enabled', ['enabled' => false])->assertForbidden();
        $this->assertSame([], $this->provider->calls);
    }

    public function test_the_screen_is_an_admin_screen_of_its_own(): void
    {
        $json = json_decode((string) file_get_contents(dirname(__DIR__) . '/extension.json'), true);
        $screens = array_column($json['ui']['screens'], null, 'id');

        $this->assertSame(['id' => 'gateway-domains', 'area' => 'admin', 'path' => 'gateway-domains', 'nav' => ['label' => 'Gateway domains', 'icon' => 'globe']], $screens['gateway-domains']);
    }

    public function test_an_empty_list_is_synced_when_opened_and_shows_the_region(): void
    {
        $this->asAdmin()->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.problem', null)
            ->assertJsonPath('data.sync_error', null)
            ->assertJsonPath('data.domains.0.domain', 'play.example.net')
            ->assertJsonPath('data.domains.0.region', 'eu')
            ->assertJsonPath('data.domains.0.status', 'Official')
            ->assertJsonPath('data.domains.0.enabled', true);

        $this->assertSame(['GET regions', 'GET custom-domains'], array_column($this->provider->calls, 'key'));
        $this->assertNotNull($this->store()->domainSync()->syncedAt);
    }

    public function test_the_switch_refresh_and_a_failed_refresh(): void
    {
        $this->asAdmin()->getJson(self::URL)->assertOk();

        $this->putJson(self::URL . '/play.example.net/enabled', ['enabled' => false])->assertOk()
            ->assertJsonPath('message', 'Only admins can pick this domain now. Existing addresses keep working.')
            ->assertJsonPath('data.domains.0.enabled', false);
        $this->putJson(self::URL . '/nope.example.net/enabled', ['enabled' => true])->assertStatus(422)
            ->assertJsonPath('error', 'That domain is not in the list.');

        $this->provider->on('GET regions', 200, ['hosterDomains' => [], 'items' => []]);
        $this->postJson(self::URL . '/refresh')->assertOk()
            ->assertJsonPath('message', 'The domain list is up to date.')
            ->assertJsonPath('data.domains.0.status', 'No longer offered')
            ->assertJsonPath('data.domains.0.can_remove', true);

        $this->provider->on('GET regions', 503, ['error' => 'The provider is down.']);
        $this->postJson(self::URL . '/refresh')->assertStatus(422)->assertJsonPath('error', 'The provider is down.');
        $this->getJson(self::URL)->assertJsonPath('data.sync_error', 'The provider is down.')->assertJsonCount(1, 'data.domains');
    }

    public function test_an_own_domain_is_added_verified_and_removed(): void
    {
        $this->provider->on('POST custom-domains', 200, ['domain' => ['domain' => 'own.example.com', 'state' => 'pending', 'txtName' => '_dylaris-verify.own.example.com', 'txtValue' => 'tok-123']]);
        $this->provider->on('POST custom-domains/own.example.com/verify-txt', 409, ['error' => 'The TXT record was not found yet. DNS changes can take a few minutes to publish.']);
        $this->asAdmin()->getJson(self::URL)->assertOk();

        $this->postJson(self::URL, ['domain' => 'Own.Example.com'])->assertOk()
            ->assertJsonPath('message', 'Domain added. Publish its TXT record, then verify it.')
            ->assertJsonPath('data.domains.1.txt_name', '_dylaris-verify.own.example.com')
            ->assertJsonPath('data.domains.1.txt_value', 'tok-123')
            ->assertJsonPath('data.domains.1.status', 'Own domain, not verified yet')
            ->assertJsonPath('data.domains.1.usable', false)
            ->assertJsonPath('data.domains.1.can_verify', true);
        $this->assertSame([['domain' => 'own.example.com']], $this->provider->bodies('POST custom-domains'));
        $this->postJson(self::URL, ['domain' => 'not a domain'])->assertStatus(422)->assertJsonPath('error', 'Enter a domain name like play.example.com.');

        $this->postJson(self::URL . '/own.example.com/verify')->assertStatus(422)
            ->assertJsonPath('error', 'The TXT record was not found yet. DNS changes can take a few minutes to publish.');
        $this->provider->on('POST custom-domains/own.example.com/verify-txt', 200, ['success' => true]);
        $this->postJson(self::URL . '/own.example.com/verify')->assertOk()
            ->assertJsonPath('message', 'own.example.com is verified. Users can pick it once it is enabled.')
            ->assertJsonPath('data.domains.1.txt_value', '')
            ->assertJsonPath('data.domains.1.usable', true);

        $this->provider->on('DELETE custom-domains/own.example.com', 200, ['success' => true]);
        $this->deleteJson(self::URL . '/own.example.com')->assertOk()->assertJsonCount(1, 'data.domains');
        $this->assertSame(['play.example.net'], array_map(fn (DomainEntry $e) => $e->domain, $this->store()->domains()));
    }

    public function test_the_sync_command_is_scheduled_hourly_and_prints_the_list(): void
    {
        $schedule = new Schedule();
        $this->app->make(ExtensionConsoleRegistry::class)->schedule($schedule);
        $events = array_values(array_filter($schedule->events(), fn ($e) => str_contains((string) $e->command, 'dylaris-gateway:sync-domains')));
        $this->assertCount(1, $events);
        $this->assertSame('0 * * * *', $events[0]->expression);

        $this->artisan('dylaris-gateway:sync-domains')
            ->expectsOutputToContain('Domains: 1 official, 0 own, 0 no longer offered; 0 admins only.')
            ->assertExitCode(0);
        $this->provider->on('GET regions', 503, ['error' => 'down']);
        $this->artisan('dylaris-gateway:sync-domains')->expectsOutputToContain('Domain sync failed, the stored list stays: down')->assertExitCode(1);
        $this->assertCount(1, $this->store()->domains());
    }
}
