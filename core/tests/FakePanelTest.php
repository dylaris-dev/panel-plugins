<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel\Tests;

use Dylaris\GatewayPanel\AllocationTaken;
use Dylaris\GatewayPanel\Testing\FakePanel;
use PHPUnit\Framework\TestCase;

final class FakePanelTest extends TestCase
{
    public function testFindOrCreateLoopbackReusesExisting(): void
    {
        $p = new FakePanel();
        $existing = $p->addAllocation(1, '127.0.0.1', 25565);
        $a = $p->findOrCreateLoopback(1, 25565);
        $this->assertSame($existing->id, $a->id);
        $created = $p->findOrCreateLoopback(1, 25566);
        $this->assertSame('127.0.0.1', $created->ip);
        $this->assertNull($created->serverId);
        $this->assertSame($created->id, $p->findOrCreateLoopback(1, 25566)->id);
    }

    public function testFindLoopbackNeverCreates(): void
    {
        $p = new FakePanel();
        $p->addAllocation(1, '10.0.0.1', 25565);
        $p->addAllocation(2, '127.0.0.1', 25565);
        $this->assertNull($p->findLoopback(1, 25565), 'public IP and other node do not count');
        $this->assertNull($p->allocation(3), 'nothing created');

        $mine = $p->addAllocation(1, '127.0.0.1', 25565);
        $p->addServer(7, 1, $p->addAllocation(1, '10.0.0.1', 25000));
        $p->assign(7, $mine->id);
        $this->assertSame([$mine->id, 7], [$p->findLoopback(1, 25565)?->id, $p->findLoopback(1, 25565)?->serverId], 'found even when assigned');
    }

    public function testFindOrCreateLoopbackRefusesPortHeldByOtherServer(): void
    {
        $p = new FakePanel();
        $a = $p->addAllocation(1, '127.0.0.1', 25565);
        $p->addServer(7, 1, $p->addAllocation(1, '10.0.0.1', 25000));
        $p->assign(7, $a->id);
        $this->expectException(AllocationTaken::class);
        $p->findOrCreateLoopback(1, 25565);
    }

    public function testAssignRefusesAllocationOwnedByOtherServer(): void
    {
        $p = new FakePanel();
        $p->addServer(7, 1, $p->addAllocation(1, '10.0.0.1', 25000));
        $p->addServer(8, 1, $theirs = $p->addAllocation(1, '10.0.0.1', 25001));
        try {
            $p->assign(7, $theirs->id);
            $this->fail('expected AllocationTaken');
        } catch (AllocationTaken $e) {
            $this->assertSame(25001, $e->port);
        }
        $this->assertSame(8, $p->allocation($theirs->id)->serverId);
        $p->assign(8, $theirs->id); // re-assigning to the owner is fine
        $this->assertSame(8, $p->allocation($theirs->id)->serverId);
    }

    public function testFreePublicPrefersRequestedPort(): void
    {
        $p = new FakePanel();
        $p->addAllocation(1, '10.0.0.1', 25000);
        $want = $p->addAllocation(1, '10.0.0.1', 25010);
        $p->addAllocation(1, '127.0.0.1', 25001);
        $this->assertSame($want->id, $p->freePublic(1, 25010)?->id);
    }

    public function testFreePublicFallsBackToNextFree(): void
    {
        $p = new FakePanel();
        $p->addAllocation(1, '127.0.0.1', 24000);
        $p->addAllocation(1, '10.0.0.1', 25020);
        $low = $p->addAllocation(1, '10.0.0.1', 25005);
        $this->assertSame($low->id, $p->freePublic(1, 25010)?->id);
        $this->assertNull($p->freePublic(2, null));
    }

    public function testDeleteIfUnassignedKeepsAssigned(): void
    {
        $p = new FakePanel();
        $kept = $p->addAllocation(1, '10.0.0.1', 25000);
        $gone = $p->addAllocation(1, '10.0.0.1', 25001);
        $p->addServer(7, 1, $p->addAllocation(1, '10.0.0.1', 25002));
        $p->assign(7, $kept->id);
        $p->deleteIfUnassigned($kept->id);
        $p->deleteIfUnassigned($gone->id);
        $this->assertNotNull($p->allocation($kept->id));
        $this->assertNull($p->allocation($gone->id));
    }

    public function testFailNextThrowsOnce(): void
    {
        $p = new FakePanel();
        $p->failNext('freePublic');
        try {
            $p->freePublic(1, null);
            $this->fail('expected failure');
        } catch (\RuntimeException) {
        }
        $this->assertNull($p->freePublic(1, null));
        $this->assertSame('freePublic', $p->calls[0][0]);
    }

    public function testSetPrimaryAndUnassign(): void
    {
        $p = new FakePanel();
        $main = $p->addAllocation(1, '10.0.0.1', 25000);
        $extra = $p->addAllocation(1, '127.0.0.1', 25001);
        $p->addServer(7, 1, $main);
        $p->assign(7, $extra->id);
        $p->setPrimary(7, $extra->id);
        $s = $p->server(7);
        $this->assertSame($extra->id, $s->primary->id);
        $this->assertCount(2, $s->allocations);
        $p->unassign(7, $main->id);
        $this->assertCount(1, $p->server(7)->allocations);
        $this->assertNull($p->server(99));
        $this->assertSame([7], $p->allServerIds());
    }
}
