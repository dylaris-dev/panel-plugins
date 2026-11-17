<?php

namespace Dylaris\GatewayPelican\Tests;

use App\Tests\Integration\IntegrationTestCase;
use Dylaris\GatewayPelican\Providers\DylarisGatewayServiceProvider;

abstract class PluginTestCase extends IntegrationTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        // What PluginService::loadPlugins does for an enabled plugin, which it skips under test.
        $app->register(DylarisGatewayServiceProvider::class);
        $app->afterResolving('migrator', fn ($migrator) => $migrator->path(__DIR__ . '/../database/migrations'));

        return $app;
    }
}
