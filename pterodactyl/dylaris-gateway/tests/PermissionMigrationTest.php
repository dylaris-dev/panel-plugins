<?php

namespace Dylaris\GatewayPteroTests;

use Pterodactyl\Models\Subuser;
use Pterodactyl\Models\User;

/** An update from 0.1.0 must take nothing away: ext.dylaris-gateway.manage becomes view, mode and address. */
class PermissionMigrationTest extends ExtensionTestCase
{
    private const NEW = ['ext.dylaris-gateway.view', 'ext.dylaris-gateway.mode', 'ext.dylaris-gateway.address'];

    private function migration(): object
    {
        return require dirname(__DIR__) . '/database/migrations/2026_11_17_000001_split_gateway_permission.php';
    }

    private function subuser(array $permissions): Subuser
    {
        return Subuser::query()->create(['user_id' => User::factory()->create()->id, 'server_id' => $this->createServerModel()->id, 'permissions' => $permissions]);
    }

    public function test_manage_becomes_the_three_new_permissions_and_others_stay(): void
    {
        $manager = $this->subuser(['control.console', 'ext.dylaris-gateway.manage']);
        $plain = $this->subuser(['control.console', 'file.read']);

        $this->migration()->up();
        $this->migration()->up(); // a second run changes nothing

        $this->assertSame(['control.console', ...self::NEW], $manager->fresh()->permissions);
        $this->assertSame(['control.console', 'file.read'], $plain->fresh()->permissions);
    }

    public function test_down_gives_manage_back(): void
    {
        $sub = $this->subuser(['control.console', 'ext.dylaris-gateway.address']);

        $this->migration()->down();

        $this->assertSame(['control.console', 'ext.dylaris-gateway.manage'], $sub->fresh()->permissions);
    }
}
