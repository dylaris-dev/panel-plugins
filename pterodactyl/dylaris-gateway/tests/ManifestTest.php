<?php

namespace Dylaris\GatewayPteroTests;

use Pterodactyl\Services\Extensions\ExtensionRepository;

class ManifestTest extends ExtensionTestCase
{
    public function test_the_manifest_requires_the_php_floor_the_plugins_are_tested_on(): void
    {
        $manifest = $this->app->make(ExtensionRepository::class)->discovered()->get(self::ID);

        $this->assertSame(['panel' => '^2.0.0-beta.2', 'sdk' => '^2.0.0-beta.6', 'php' => '^8.4'], $manifest->requirements);
    }

    /** Found live: on the Network page a subuser also needed allocation.read; the Gateway page needs only the gateway permission. */
    public function test_the_server_gateway_page_is_a_screen_gated_by_the_gateway_permission_only(): void
    {
        $json = json_decode((string) file_get_contents(dirname(__DIR__) . '/extension.json'), true);
        $screens = array_column($json['ui']['screens'], null, 'id');

        $this->assertSame('server', $screens['server-gateway']['area']);
        $this->assertSame(['ext.dylaris-gateway.view', 'ext.dylaris-gateway.mode', 'ext.dylaris-gateway.address'], $screens['server-gateway']['permission']);
        $this->assertSame(['server.allocations'], $json['ui']['views']);
        $this->assertNotNull($this->app->make(ExtensionRepository::class)->discovered()->get(self::ID), 'the panel accepts the manifest');
    }

    /** The marketplace rules want the outbound calls named. */
    public function test_the_description_names_where_calls_go(): void
    {
        $manifest = json_decode(file_get_contents(dirname(__DIR__) . '/extension.json'), true);
        $this->assertStringContainsString('Calls go only to the DYLARIS provider at https://panel.dylaris.com.', $manifest['description']);
        $this->assertSame('0.2.0', $manifest['version']);
    }
}
