<?php

namespace Dylaris\GatewayPteroTests;

use Dylaris\GatewayPanel\DomainEntry;
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

    /** Owner review 0.2.0: the sync's write is column-scoped; the admin's switch is never written back from a read. */
    public function test_merging_domains_never_writes_the_switch_of_a_stored_row(): void
    {
        FakeProvider::bind($this->app);
        $this->store()->putDomains([new DomainEntry('play.example.net', DomainEntry::OFFICIAL, true, 'letters')]);
        $racing = new \Dylaris\GatewayPanel\DomainList($this->app->make(\Dylaris\GatewayPanel\Provider\ProviderClient::class), new RacingStore(
            $this->store(),
            fn () => $this->store()->setDomainEnabled('play.example.net', false),
        ));

        $racing->sync(1_800_000_000);

        $rows = $this->store()->domains();
        $this->assertSame(['play.example.net', false, false, 1_800_000_000], [$rows[0]->domain, $rows[0]->enabled, $rows[0]->gone, $rows[0]->lastSeenAt], 'the toggle between read and write survives');

        $this->store()->mergeDomains([new DomainEntry('play.example.net', DomainEntry::OFFICIAL, true, 'dns', 'eu', gone: true, lastSeenAt: 5)]);
        $row = $this->store()->domains()[0];
        $this->assertSame([false, 'dns', 'eu', true, 5], [$row->enabled, $row->validation, $row->region, $row->gone, $row->lastSeenAt]);

        $this->store()->deleteDomain('play.example.net');
        $this->assertNotContains('play.example.net', array_map(fn ($e) => $e->domain, $this->store()->domains()));
    }
}
