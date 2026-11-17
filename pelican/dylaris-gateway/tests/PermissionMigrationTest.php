<?php

namespace Dylaris\GatewayPelican\Tests;

use App\Models\Subuser;
use App\Models\User;

/** An update from 0.1.0 must take nothing away: gateway.manage becomes view, mode and address. */
class PermissionMigrationTest extends PluginTestCase
{
    private function migration(): object
    {
        return require __DIR__ . '/../database/migrations/2026_11_17_000001_split_gateway_permission.php';
    }

    private function subuser(array $permissions): Subuser
    {
        $server = $this->createServerModel();

        return Subuser::query()->create(['user_id' => User::factory()->create()->id, 'server_id' => $server->id, 'permissions' => $permissions]);
    }

    public function test_manage_becomes_the_three_new_permissions_and_others_stay(): void
    {
        $manager = $this->subuser(['control.console', 'gateway.manage']);
        $plain = $this->subuser(['control.console', 'file.read']);
        $already = $this->subuser(['gateway.manage', 'gateway.view']);

        $this->migration()->up();

        $this->assertSame(['control.console', 'gateway.view', 'gateway.mode', 'gateway.address'], $manager->fresh()->permissions);
        $this->assertSame(['control.console', 'file.read'], $plain->fresh()->permissions);
        $this->assertSame(['gateway.view', 'gateway.mode', 'gateway.address'], $already->fresh()->permissions);

        $this->migration()->up(); // a second run changes nothing
        $this->assertSame(['control.console', 'gateway.view', 'gateway.mode', 'gateway.address'], $manager->fresh()->permissions);
    }

    /** Review 0.2.0: a rollback must never give a view-only subuser the old all-in-one permission. */
    public function test_down_gives_manage_back_only_for_mode_and_address(): void
    {
        $full = $this->subuser(['control.console', 'gateway.view', 'gateway.mode', 'gateway.address']);
        $both = $this->subuser(['gateway.mode', 'gateway.address']);
        $view = $this->subuser(['control.console', 'gateway.view']);
        $mode = $this->subuser(['gateway.mode']);
        $plain = $this->subuser(['file.read']);

        $this->migration()->down();

        $this->assertSame(['control.console', 'gateway.manage'], $full->fresh()->permissions);
        $this->assertSame(['gateway.manage'], $both->fresh()->permissions);
        $this->assertSame(['control.console'], $view->fresh()->permissions);
        $this->assertSame([], $mode->fresh()->permissions);
        $this->assertSame(['file.read'], $plain->fresh()->permissions);
    }
}
