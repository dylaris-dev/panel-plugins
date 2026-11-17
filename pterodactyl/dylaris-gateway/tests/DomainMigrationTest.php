<?php

namespace Dylaris\GatewayPteroTests;

use Dylaris\GatewayPanel\DomainEntry;
use Dylaris\GatewayPanel\DomainSync;
use Dylaris\GatewayPanel\Mode;
use Dylaris\GatewayPanel\NodeState;
use Dylaris\GatewayPanel\StateStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Node;
use Pterodactyl\Tests\Integration\IntegrationTestCase;

/**
 * The 0.2.0 migration on MariaDB. Not an ExtensionTestCase: its DatabaseTransactions cannot hold a schema change (MariaDB
 * commits on DDL), so this test cleans up after itself instead and leaves the schema as the migrations left it.
 */
class DomainMigrationTest extends IntegrationTestCase
{
    /** @var list<int> */
    private array $nodes = [];

    /** @var list<int> */
    private array $locations = [];

    protected function tearDown(): void
    {
        if (!Schema::hasTable('dylaris_gateway_domains')) {
            $this->migration()->up();
        }
        DB::table('dylaris_gateway_domains')->delete();
        DB::table('dylaris_gateway_domain_sync')->delete();
        DB::table('dylaris_gateway_domain_sync')->insert(['id' => 1, 'new_official_enabled' => true]);
        DB::table('dylaris_gateway_nodes')->whereIn('node_id', $this->nodes)->delete();
        Node::query()->whereIn('id', $this->nodes)->delete();
        Location::query()->whereIn('id', $this->locations)->delete();
        parent::tearDown();
    }

    private function migration(): object
    {
        return require dirname(__DIR__) . '/database/migrations/2026_11_17_000002_create_domain_list.php';
    }

    private function store(): StateStore
    {
        return $this->app->make(StateStore::class);
    }

    private function node(): Node
    {
        $node = Node::factory()->for(Location::factory())->create();
        $this->nodes[] = $node->id;
        $this->locations[] = $node->location_id;

        return $node;
    }

    public function test_the_node_lists_move_into_the_panel_wide_list_and_back(): void
    {
        $a = $this->node();
        $b = $this->node();
        // Any other node of the seeded database has no saved list, which by the rule turns new official domains on.
        $othersUnsaved = Node::query()->whereNotIn('id', [$a->id, $b->id])->exists();
        $this->store()->putNode(new NodeState($a->id, 'link-1', 'eu', Mode::cases()));
        $this->store()->putNode(new NodeState($b->id, null, null, Mode::cases()));
        $this->migration()->down();
        $this->assertTrue(Schema::hasColumn('dylaris_gateway_nodes', 'domains'));
        DB::table('dylaris_gateway_nodes')->where('node_id', $a->id)->update(['domains' => json_encode([
            ['domain' => 'play.example.net', 'custom' => false, 'admin_only' => false, 'validation' => 'alphanumeric'],
            ['domain' => 'vip.example.com', 'custom' => true, 'admin_only' => true, 'validation' => ''],
        ])]);
        DB::table('dylaris_gateway_nodes')->where('node_id', $b->id)->update(['domains' => json_encode([
            ['domain' => 'vip.example.com', 'custom' => true, 'admin_only' => false, 'validation' => ''],
        ])]);

        $this->migration()->up();

        $this->assertFalse(Schema::hasColumn('dylaris_gateway_nodes', 'domains'));
        $this->assertEquals([
            new DomainEntry('play.example.net', DomainEntry::OFFICIAL, true, 'alphanumeric'),
            new DomainEntry('vip.example.com', DomainEntry::OWN, false),
        ], $this->store()->domains());
        $this->assertEquals(new DomainSync(null, null, null, $othersUnsaved), $this->store()->domainSync());

        $this->migration()->down();
        $this->assertSame([
            ['domain' => 'play.example.net', 'custom' => false, 'admin_only' => false, 'validation' => 'alphanumeric'],
            ['domain' => 'vip.example.com', 'custom' => true, 'admin_only' => true, 'validation' => ''],
        ], json_decode(DB::table('dylaris_gateway_nodes')->where('node_id', $a->id)->value('domains'), true));
        $this->assertFalse(Schema::hasTable('dylaris_gateway_domains'));

        $this->migration()->up();
        $this->assertTrue(Schema::hasTable('dylaris_gateway_domains'));
    }
}
