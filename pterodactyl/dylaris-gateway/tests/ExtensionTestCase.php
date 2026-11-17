<?php

namespace Dylaris\GatewayPteroTests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Pterodactyl\Models\Extension;
use Pterodactyl\Services\Extensions\ExtensionRepository;
use Pterodactyl\Tests\Integration\IntegrationTestCase;

abstract class ExtensionTestCase extends IntegrationTestCase
{
    use DatabaseTransactions;

    public const ID = 'dylaris-gateway';

    protected function setUp(): void
    {
        parent::setUp();
        // A provider that fails is skipped by the panel, not reported; say why instead of failing on a missing binding.
        $this->assertTrue(
            $this->app->make(ExtensionRepository::class)->isAvailable(self::ID),
            'The extension did not load: ' . Extension::query()->where('identifier', self::ID)->value('error'),
        );
    }
}
