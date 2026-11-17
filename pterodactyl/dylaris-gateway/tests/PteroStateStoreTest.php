<?php

namespace Dylaris\GatewayPteroTests;

use Dylaris\GatewayPanel\Mode;
use Dylaris\GatewayPanel\NodeState;
use Dylaris\GatewayPanel\ServerState;
use Dylaris\GatewayPanel\StateStore;
use Dylaris\GatewayPtero\PteroStateStore;

class PteroStateStoreTest extends ExtensionTestCase
{
    private function store(): StateStore
    {
        return $this->app->make(StateStore::class);
    }

    public function test_the_container_gives_the_ptero_store(): void
    {
        $this->assertInstanceOf(PteroStateStore::class, $this->store());
    }

    public function test_server_state_round_trips_every_field(): void
    {
        $in = new ServerState(7, Mode::GatewayOnly, 'mc', 'play.example.net', 3, 25565, 25570, true, true, true, 'Something failed.', 1_800_000_000, '203.0.113.7');
        $this->store()->put($in);

        $out = $this->store()->get(7);

        $this->assertEquals($in, $out);
        $this->assertNotSame($in, $out);
    }

    public function test_server_state_round_trips_nulls_and_false(): void
    {
        $in = new ServerState(8, Mode::Direct, '', '', 1, 25565);
        $this->store()->put($in);

        $this->assertEquals($in, $this->store()->get(8));
    }

    public function test_put_replaces_and_delete_removes(): void
    {
        $s = new ServerState(9, Mode::Both, 'a', 'h.net', 1, 25565);
        $this->store()->put($s);
        $s->mode = Mode::Direct;
        $s->lastError = 'x';
        $this->store()->put($s);

        $this->assertEquals($s, $this->store()->get(9));
        $this->assertCount(1, $this->store()->all());

        $this->store()->delete(9);
        $this->assertNull($this->store()->get(9));
        $this->assertSame([], $this->store()->all());
    }

    public function test_all_lists_every_server(): void
    {
        $a = new ServerState(2, Mode::Both, 'a', 'h.net', 1, 25565);
        $b = new ServerState(1, Mode::GatewayOnly, 'b', 'h.net', 1, 25566);
        $this->store()->put($a);
        $this->store()->put($b);

        $this->assertEquals([$b, $a], $this->store()->all());
    }

    public function test_node_state_round_trips(): void
    {
        $this->assertNull($this->store()->node(4));

        $n = new NodeState(4, 'link-1', 'eu', [Mode::Both, Mode::GatewayOnly], '172.20.0.1');
        $this->store()->putNode($n);
        $this->assertEquals($n, $this->store()->node(4));

        $n2 = new NodeState(4, null, null, [], '172.18.0.1');
        $this->store()->putNode($n2);
        $this->assertEquals($n2, $this->store()->node(4));
    }
}
