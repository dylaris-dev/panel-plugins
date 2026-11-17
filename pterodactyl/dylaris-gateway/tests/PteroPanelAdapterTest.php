<?php

namespace Dylaris\GatewayPteroTests;

use Dylaris\GatewayPanel\AllocationTaken;
use Dylaris\GatewayPanel\PanelAdapter;
use Dylaris\GatewayPtero\PteroPanelAdapter;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Tests\Support\Fakes\FakeDaemonServer;

class PteroPanelAdapterTest extends ExtensionTestCase
{
    private const PUBLIC_IP = '203.0.113.10';

    /** Counts Wings syncs: the adapter must go through UpdatesServerBuild, never raw model writes. */
    private FakeDaemonServer $wings;

    private PanelAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wings = new FakeDaemonServer();
        $this->adapter = $this->app->make(PanelAdapter::class);
    }

    private function node(): Node
    {
        return Node::factory()->for(Location::factory())->create();
    }

    private function server(int $port = 25565, array $attrs = []): Server
    {
        $node = $this->node();
        $primary = Allocation::factory()->create(['node_id' => $node->id, 'ip' => self::PUBLIC_IP, 'port' => $port]);

        return $this->createServerModel(['node_id' => $node->id, 'allocation_id' => $primary->id] + $attrs);
    }

    public function test_the_container_gives_the_ptero_adapter(): void
    {
        $this->assertInstanceOf(PteroPanelAdapter::class, $this->adapter);
    }

    public function test_server_maps_the_ptero_model(): void
    {
        $s = $this->server();

        $p = $this->adapter->server($s->id);

        $this->assertSame($s->id, $p->id);
        $this->assertSame($s->uuidShort, $p->shortId);
        $this->assertSame($s->node_id, $p->nodeId);
        $this->assertSame([self::PUBLIC_IP, 25565, $s->id], [$p->primary->ip, $p->primary->port, $p->primary->serverId]);
        $this->assertCount(1, $p->allocations);
        $this->assertNull($this->adapter->server($s->id + 1000));
        $this->assertSame([$s->id], $this->adapter->allServerIds());
    }

    public function test_loopback_create_assign_primary_unassign(): void
    {
        $s = $this->server(25565, ['allocation_limit' => 5, 'database_limit' => 2, 'backup_limit' => 3]);
        $publicId = $s->allocation_id;

        $this->assertNull($this->adapter->findLoopback($s->node_id, 25565));

        $l = $this->adapter->findOrCreateLoopback($s->node_id, 25565);
        $this->assertSame(['127.0.0.1', 25565, $s->node_id, null], [$l->ip, $l->port, $l->nodeId, $l->serverId]);
        $this->assertSame($l->id, $this->adapter->findOrCreateLoopback($s->node_id, 25565)->id, 'a second call reuses the allocation');
        $this->assertEquals($l, $this->adapter->findLoopback($s->node_id, 25565));
        $this->assertSame(1, Allocation::query()->where('ip', '127.0.0.1')->count());

        // Both: the loopback joins the public allocation.
        $this->adapter->assign($s->id, $l->id);
        $this->adapter->assign($s->id, $l->id); // idempotent
        $p = $this->adapter->server($s->id);
        $this->assertSame($publicId, $p->primary->id);
        $this->assertEqualsCanonicalizing([$publicId, $l->id], array_map(fn ($a) => $a->id, $p->allocations));
        $this->assertSame($s->id, $this->adapter->findLoopback($s->node_id, 25565)->serverId);

        // Gateway only: the loopback becomes primary and the public allocation goes back to the pool.
        $this->adapter->setPrimary($s->id, $l->id);
        $this->adapter->unassign($s->id, $publicId);
        $p = $this->adapter->server($s->id);
        $this->assertSame($l->id, $p->primary->id);
        $this->assertSame([$l->id], array_map(fn ($a) => $a->id, $p->allocations));
        $this->assertNull(Allocation::query()->find($publicId)->server_id);

        // Back to direct.
        $this->adapter->assign($s->id, $publicId);
        $this->adapter->setPrimary($s->id, $publicId);
        $this->adapter->unassign($s->id, $l->id);
        $this->adapter->deleteIfUnassigned($l->id);
        $p = $this->adapter->server($s->id);
        $this->assertSame($publicId, $p->primary->id);
        $this->assertCount(1, $p->allocations);
        $this->assertNull($this->adapter->findLoopback($s->node_id, 25565));

        // Every change synced Wings, and the build limits survived UpdatesServerBuild's defaults.
        $this->wings->assertSyncedTimes(6);
        $s->refresh();
        $this->assertSame([5, 2, 3], [$s->allocation_limit, $s->database_limit, $s->backup_limit]);
    }

    public function test_null_build_limits_stay_null(): void
    {
        $s = $this->server(25565, ['allocation_limit' => null, 'database_limit' => null]);
        $l = $this->adapter->findOrCreateLoopback($s->node_id, 25565);

        $this->adapter->assign($s->id, $l->id);

        $s->refresh();
        $this->assertNull($s->allocation_limit);
        $this->assertNull($s->database_limit);
    }

    public function test_a_loopback_held_by_another_server_is_refused(): void
    {
        $a = $this->server(25565);
        $b = $this->createServerModel(['node_id' => $a->node_id]);
        $l = $this->adapter->findOrCreateLoopback($a->node_id, 25565);
        $this->adapter->assign($a->id, $l->id);

        try {
            $this->adapter->assign($b->id, $l->id);
            $this->fail('assign took another server\'s allocation');
        } catch (AllocationTaken $e) {
            $this->assertSame(25565, $e->port);
        }
        $this->assertSame($a->id, Allocation::query()->find($l->id)->server_id);

        $this->expectException(AllocationTaken::class);
        $this->adapter->findOrCreateLoopback($a->node_id, 25565);
    }

    public function test_an_allocation_on_another_node_is_refused(): void
    {
        $s = $this->server();
        $other = Allocation::factory()->create(['node_id' => $this->node()->id]);

        $this->expectException(\RuntimeException::class);
        $this->adapter->assign($s->id, $other->id);
    }

    public function test_unassign_ignores_an_allocation_the_server_does_not_hold(): void
    {
        $a = $this->server();
        $b = $this->createServerModel(['node_id' => $a->node_id]);

        $this->adapter->unassign($b->id, $a->allocation_id);

        $this->assertSame($a->id, Allocation::query()->find($a->allocation_id)->server_id);
        $this->wings->assertNothingHappened();
    }

    public function test_free_public_prefers_the_port_then_the_lowest(): void
    {
        $s = $this->server(25565);
        $node = $s->node_id;
        $mk = fn (string $ip, int $port) => Allocation::factory()->create(['node_id' => $node, 'ip' => $ip, 'port' => $port]);
        $high = $mk(self::PUBLIC_IP, 25590);
        $low = $mk(self::PUBLIC_IP, 25570);
        $mk('127.0.0.1', 25500); // loopback never counts as public
        Allocation::factory()->create(['node_id' => $this->node()->id, 'ip' => self::PUBLIC_IP, 'port' => 25501]);

        $this->assertSame($high->id, $this->adapter->freePublic($node, 25590)->id);
        $this->assertSame($low->id, $this->adapter->freePublic($node, 25565)->id, 'the preferred port is taken by the server itself');
        $this->assertSame($low->id, $this->adapter->freePublic($node, null)->id);

        $low->delete();
        $high->delete();
        $this->assertNull($this->adapter->freePublic($node, 25565));
    }

    public function test_delete_if_unassigned_keeps_assigned_ones(): void
    {
        $s = $this->server();
        $free = Allocation::factory()->create(['node_id' => $s->node_id]);

        $this->adapter->deleteIfUnassigned($s->allocation_id);
        $this->adapter->deleteIfUnassigned($free->id);
        $this->adapter->deleteIfUnassigned($free->id + 1000);

        $this->assertNotNull(Allocation::query()->find($s->allocation_id));
        $this->assertNull(Allocation::query()->find($free->id));
    }

    public function test_set_primary_refuses_an_allocation_the_server_does_not_hold(): void
    {
        $a = $this->server(25565);
        $b = $this->server(25566);
        $free = Allocation::factory()->create(['node_id' => $a->node_id, 'ip' => self::PUBLIC_IP, 'port' => 25599]);

        $e = null;
        try {
            $this->adapter->setPrimary($a->id, $free->id);
        } catch (\Throwable $e) {
        }
        $this->assertSame(\RuntimeException::class, $e ? $e::class : null, 'a free allocation must not become the primary');
        $this->assertSame("Allocation {$free->id} is not assigned to server {$a->id}.", $e->getMessage());
        try {
            $this->adapter->setPrimary($a->id, $b->allocation_id);
            $this->fail('another server\'s allocation became the primary');
        } catch (AllocationTaken $e) {
            $this->assertSame(25566, $e->port);
        }

        $this->assertSame($a->allocation_id, $a->fresh()->allocation_id);
        $this->assertSame($b->id, Allocation::query()->find($b->allocation_id)->server_id);
        $this->assertNull($free->fresh()->server_id);
        $this->wings->assertNothingHappened();
    }

    /** On a multi-IP node the customer's own address comes back, and no loopback is ever public. */
    public function test_free_public_prefers_the_remembered_ip_and_skips_every_loopback(): void
    {
        $node = $this->node()->id;
        $mk = fn (string $ip, int $port) => Allocation::factory()->create(['node_id' => $node, 'ip' => $ip, 'port' => $port, 'server_id' => null]);
        $other = $mk('10.0.0.1', 25565);
        $mine = $mk('203.0.113.7', 25565);
        $sameIp = $mk('203.0.113.7', 25590);
        $mk('127.0.0.2', 2000);
        $mk('::1', 2001);

        $this->assertSame($mine->id, $this->adapter->freePublic($node, 25565, '203.0.113.7')->id);
        $mine->delete();
        $this->assertSame($sameIp->id, $this->adapter->freePublic($node, 25565, '203.0.113.7')->id, 'the same IP before the same port');
        $this->assertSame($other->id, $this->adapter->freePublic($node, 25565)->id);
        $other->delete();
        $sameIp->delete();
        $this->assertNull($this->adapter->freePublic($node, null), '127.0.0.2 and ::1 are not public');
    }
}
