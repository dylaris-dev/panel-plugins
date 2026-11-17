<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel\Tests;

use Dylaris\GatewayPanel\Allocation;
use Dylaris\GatewayPanel\Mode;
use Dylaris\GatewayPanel\ModeService;
use Dylaris\GatewayPanel\NodeState;
use Dylaris\GatewayPanel\Reconciler;
use Dylaris\GatewayPanel\Testing\FakePanel;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class ReconcilerTest extends TestCase
{
    private const ALL = [Mode::Direct, Mode::Both, Mode::GatewayOnly];
    private const LISTS = ['GET routes', 'GET link-kits'];
    private const NOT_CONNECTED = Reconciler::NOT_CONNECTED;

    private FakeProvider $provider;
    private FakePanel $panel;
    private CopyingStateStore $store;
    private ModeService $modes;
    private Reconciler $reconciler;
    private Allocation $public;
    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        $this->provider = new FakeProvider();
        $this->panel = new FakePanel();
        $this->store = new CopyingStateStore();
        $this->store->putNode(new NodeState(1, 'link-1', 'e2e', self::ALL));
        $this->public = $this->panel->addAllocation(1, '10.0.0.1', 25565);
        $this->panel->addServer(7, 1, $this->public);
        $client = $this->provider->client();
        $this->modes = new ModeService($client, $this->panel, $this->store);
        $this->reconciler = new Reconciler($client, $this->panel, $this->store, fn () => $this->now);
    }

    /** Brings servers into $mode through ModeService, then clears every call log. */
    private function start(Mode $mode, int ...$serverIds): void
    {
        foreach ($serverIds ?: [7] as $id) {
            $this->modes->apply($id, $mode);
        }
        $this->clearCalls();
    }

    private function clearCalls(): void
    {
        $this->provider->calls = $this->provider->bodies = $this->panel->calls = [];
    }

    private function addServer(int $id, int $port): void
    {
        $this->panel->addServer($id, 1, $this->panel->addAllocation(1, '10.0.0.1', $port));
    }

    private function domain(int $serverId = 7): string
    {
        return $this->store->get($serverId)->domain();
    }

    /** @return list<string> the server's allocations as "ip:port", primary first */
    private function allocs(int $serverId = 7): array
    {
        $s = $this->panel->server($serverId);
        $rest = array_filter($s->allocations, fn (Allocation $a) => $a->id !== $s->primary->id);
        $out = array_map(fn (Allocation $a) => "{$a->ip}:{$a->port}", array_values($rest));
        sort($out);
        return ["{$s->primary->ip}:{$s->primary->port}", ...$out];
    }

    /** @return list<int> ports of every 127.0.0.1 allocation on the node, assigned or not */
    private function loopbacks(int $nodeId): array
    {
        $ports = [];
        for ($id = 1; $id <= 50; $id++) {
            $a = $this->panel->allocation($id);
            if ($a?->ip === '127.0.0.1' && $a->nodeId === $nodeId) {
                $ports[] = $a->port;
            }
        }
        return $ports;
    }

    /** @return list<string> panel calls that change something */
    private function panelWrites(): array
    {
        $writes = array_filter($this->panel->calls, fn (array $c) => !in_array($c[0], ['server', 'allServerIds', 'freePublic', 'findLoopback'], true));
        return array_values(array_map(fn (array $c) => $c[0], $writes));
    }

    private function callCount(string $call): int
    {
        return count(array_keys($this->provider->calls, $call, true));
    }

    public function testDeletedServerCleansRouteAndLoopback(): void
    {
        $this->start(Mode::GatewayOnly);
        $domain = $this->domain();
        $this->panel->removeServer(7);

        $r = $this->reconciler->run();

        $this->assertSame([...self::LISTS, "DELETE routes/{$domain}"], $this->provider->calls);
        $this->assertArrayNotHasKey($domain, $this->provider->routes);
        $this->assertSame([], $this->loopbacks(1), 'loopback deleted');
        $this->assertNotNull($this->panel->allocation($this->public->id), 'the public allocation is not ours to delete');
        $this->assertSame(['deleteIfUnassigned'], $this->panelWrites());
        $this->assertNull($this->store->get(7));
        $this->assertCount(1, $r->fixed);
        $this->assertSame([], $r->errors);
    }

    public function testDeletedServerWhoseLoopbackIsGoneCreatesNothing(): void
    {
        $this->start(Mode::Both);
        $loopback = $this->panel->findLoopback(1, 25565);
        $this->panel->removeServer(7);
        $this->panel->deleteIfUnassigned($loopback->id); // the panel already cleaned it up
        $this->clearCalls();

        $this->reconciler->run();

        $this->assertSame([], $this->panelWrites(), 'no allocation created just to be deleted');
        $this->assertSame([], $this->loopbacks(1));
        $this->assertNull($this->store->get(7));
    }

    public function testPortChangeRetargets(): void
    {
        $this->start(Mode::Both);
        // The admin moves the server to another public port.
        $q = $this->panel->addAllocation(1, '10.0.0.1', 25570);
        $this->panel->assign(7, $q->id);
        $this->panel->setPrimary(7, $q->id);
        $this->panel->unassign(7, $this->public->id);
        $this->clearCalls();

        $r = $this->reconciler->run();

        $this->assertSame(['10.0.0.1:25570', '127.0.0.1:25570'], $this->allocs());
        $this->assertSame([25570], $this->loopbacks(1), 'old loopback deleted');
        $this->assertSame([...self::LISTS, 'POST routes'], $this->provider->calls);
        $this->assertSame(['linkId' => 'link-1', 'targetHost' => '172.18.0.1', 'targetPort' => 25570], array_intersect_key(
            $this->provider->bodies[2],
            ['linkId' => 0, 'targetHost' => 0, 'targetPort' => 0],
        ));
        $this->assertSame(25570, $this->provider->routes[$this->domain()]['target_port']);
        $s = $this->store->get(7);
        $this->assertSame(25570, $s->port);
        $this->assertTrue($s->restartNeeded, 'Wings binds the new loopback only after a restart');
        $this->assertCount(1, $r->fixed);

        $this->clearCalls();
        $this->reconciler->run();
        $this->assertSame(self::LISTS, $this->provider->calls, 'a second run has nothing to fix');
        $this->assertSame([], $this->panelWrites());
    }

    public function testTransferToUnconnectedNodeRecordsErrorOnly(): void
    {
        $this->start(Mode::Both);
        $domain = $this->domain();
        $routeBefore = $this->provider->routes[$domain];
        // No link at all, and a link the provider does not list (kit revoked).
        foreach ([null, 'link-gone'] as $linkId) {
            $this->store->putNode(new NodeState(2, $linkId, null, self::ALL));
            $this->panel->removeServer(7);
            $this->panel->addServer(7, 2, $this->panel->addAllocation(2, '10.0.0.9', 25565));
            $this->clearCalls();

            $r = $this->reconciler->run();

            $this->assertSame([7 => self::NOT_CONNECTED], $r->errors);
            $this->assertSame([], $r->fixed);
            $this->assertSame(self::LISTS, $this->provider->calls);
            $this->assertSame([], $this->panelWrites());
            $s = $this->store->get(7);
            $this->assertSame(self::NOT_CONNECTED, $s->lastError);
            $this->assertSame(Mode::Both, $s->mode);
            $this->assertSame(1, $s->nodeId);
            $this->assertSame($routeBefore, $this->provider->routes[$domain]);
        }
    }

    /** Found live: a server moved back to its connected node kept the old error forever. */
    public function testErrorClearsOnceNothingIsLeftToFix(): void
    {
        $this->start(Mode::Both);
        $s = $this->store->get(7);
        $s->lastError = self::NOT_CONNECTED;
        $this->store->put($s);

        $r = $this->reconciler->run();

        $this->assertSame([], $r->errors);
        $this->assertSame([], $r->fixed);
        $this->assertSame(self::LISTS, $this->provider->calls);
        $this->assertSame([], $this->panelWrites());
        $this->assertNull($this->store->get(7)->lastError);
    }

    public function testTransferToConnectedNodeMovesRoute(): void
    {
        $this->start(Mode::Both);
        $this->store->putNode(new NodeState(2, 'link-2', 'e2e', self::ALL, '172.18.0.2'));
        $this->provider->kits[] = ['link_id' => 'link-2', 'online' => true];
        $this->panel->removeServer(7);
        $this->panel->addServer(7, 2, $this->panel->addAllocation(2, '10.0.0.9', 25565));
        $this->clearCalls();

        $r = $this->reconciler->run();

        $this->assertSame([], $r->errors);
        $this->assertSame(['10.0.0.9:25565', '127.0.0.1:25565'], $this->allocs());
        $this->assertSame([25565], $this->loopbacks(2));
        $this->assertSame([], $this->loopbacks(1), 'loopback on the old node deleted');
        $route = $this->provider->routes[$this->domain()];
        $this->assertSame(['link-2', '172.18.0.2', 25565], [$route['link_id'], $route['target_ip'], $route['target_port']]);
        $this->assertSame(2, $this->store->get(7)->nodeId);
        $this->assertNull($this->store->get(7)->lastError);
    }

    public function testMissingRouteIsRecreated(): void
    {
        $this->start(Mode::GatewayOnly);
        $domain = $this->domain();
        unset($this->provider->routes[$domain]);

        $r = $this->reconciler->run();

        $this->assertSame([...self::LISTS, 'POST routes'], $this->provider->calls);
        $this->assertSame(['link-1', '172.18.0.1', 25565], [
            $this->provider->routes[$domain]['link_id'],
            $this->provider->routes[$domain]['target_ip'],
            $this->provider->routes[$domain]['target_port'],
        ]);
        $this->assertSame([], $this->panelWrites());
        $this->assertSame(['127.0.0.1:25565'], $this->allocs());
        $this->assertCount(1, $r->fixed);
    }

    public function testForeignRoutesAreLeftAlone(): void
    {
        $foreign = ['domain' => 'foreign.own.e2e.test', 'link_id' => 'link-1', 'target_ip' => '172.18.0.1', 'target_port' => 25599];
        $this->provider->routes[$foreign['domain']] = $foreign;

        $r = $this->reconciler->run();
        $this->assertSame([], $this->provider->calls, 'no state at all: nothing to reconcile, no provider calls');
        $this->assertSame([], $r->fixed);

        $this->start(Mode::Both);
        $r = $this->reconciler->run();

        $this->assertSame(self::LISTS, $this->provider->calls);
        $this->assertSame($foreign, $this->provider->routes[$foreign['domain']]);
        $this->assertSame([], $r->fixed);
        $this->assertSame([], $this->panelWrites());
    }

    public function testDirectLeftoversAreCleaned(): void
    {
        $this->start(Mode::Both);
        $domain = $this->domain();
        $route = $this->provider->routes[$domain];
        $this->panel->failNext('unassign');
        $this->modes->apply(7, Mode::Direct); // route deleted, loopback left behind
        $this->provider->routes[$domain] = $route; // and the plugin's own route is back at the provider
        $this->clearCalls();

        $r = $this->reconciler->run();

        $this->assertSame(['10.0.0.1:25565'], $this->allocs());
        $this->assertSame([], $this->loopbacks(1));
        $this->assertSame([...self::LISTS, "DELETE routes/{$domain}"], $this->provider->calls);
        $this->assertArrayNotHasKey($domain, $this->provider->routes);
        $this->assertSame(Mode::Direct, $this->store->get(7)->mode);
        $this->assertCount(1, $r->fixed);

        $this->clearCalls();
        $this->reconciler->run();
        $this->assertSame(self::LISTS, $this->provider->calls);
        $this->assertSame([], $this->panelWrites());
    }

    public function testDirectLeftoverDoesNotDeleteAnotherServersRoute(): void
    {
        $this->addServer(8, 25566);
        $this->modes->apply(7, Mode::Both, 'lobby');
        $this->modes->apply(7, Mode::Direct); // 7 keeps the label, the route is gone
        $this->modes->apply(8, Mode::Both, 'lobby'); // the provider says lobby is free again
        $this->clearCalls();
        $domain = $this->domain(8);
        $this->assertSame($domain, $this->domain(7));
        $route = $this->provider->routes[$domain];

        foreach (['direct' => fn () => null, 'deleted' => fn () => $this->panel->removeServer(7)] as $case => $change) {
            $change();
            $this->reconciler->run();
            $this->reconciler->run();

            $this->assertNotContains("DELETE routes/{$domain}", $this->provider->calls, $case);
            $this->assertSame($route, $this->provider->routes[$domain], $case);
            $this->assertSame(['10.0.0.1:25566', '127.0.0.1:25566'], $this->allocs(8), $case);
        }
        $this->assertNull($this->store->get(7), 'the deleted server state is still cleaned up');
    }

    public function testRestartNeededSurvivesRateLimitDuringPortChange(): void
    {
        $this->start(Mode::Both);
        $s = $this->store->get(7);
        $s->restartNeeded = false; // the owner restarted
        $this->store->put($s);
        $q = $this->panel->addAllocation(1, '10.0.0.1', 25570);
        $this->panel->assign(7, $q->id);
        $this->panel->setPrimary(7, $q->id);
        $this->panel->unassign(7, $this->public->id);
        $this->provider->failOn['POST routes'] = FakeProvider::rateLimited(60);

        $r = $this->reconciler->run();

        $this->assertSame(60, $r->stoppedForRetryAfter);
        $this->assertSame(['10.0.0.1:25570', '127.0.0.1:25565', '127.0.0.1:25570'], $this->allocs(), 'new loopback assigned before the 429');
        $s = $this->store->get(7);
        $this->assertTrue($s->restartNeeded, 'the new loopback needs a restart, even though the run stopped');
        $this->assertSame($this->now + 60, $s->retryAfterUntil);

        $this->now += 60;
        $this->reconciler->run();
        $this->assertSame(25570, $this->provider->routes[$this->domain()]['target_port']);
        $this->assertSame(['10.0.0.1:25570', '127.0.0.1:25570'], $this->allocs());
        $this->assertTrue($this->store->get(7)->restartNeeded);
    }

    public function testFailureIsRecordedAndRunContinues(): void
    {
        $this->addServer(8, 25566);
        $this->start(Mode::Both, 7, 8);
        $this->provider->routes = [];
        $this->provider->failOn['POST routes'] = new Response(503, [], '{"code":"unavailable","error":"down"}');

        $r = $this->reconciler->run();

        $this->assertSame([7 => 'down'], $r->errors);
        $this->assertSame('down', $this->store->get(7)->lastError);
        $this->assertArrayHasKey($this->domain(8), $this->provider->routes, 'the next server is still fixed');
        $this->assertCount(1, $r->fixed);

        $r = $this->reconciler->run();
        $this->assertSame([], $r->errors);
        $this->assertArrayHasKey($this->domain(7), $this->provider->routes);
        $this->assertNull($this->store->get(7)->lastError);
    }

    public function testRateLimitStopsRunEarly(): void
    {
        $this->addServer(8, 25566);
        $this->addServer(9, 25567);
        $this->start(Mode::Both, 7, 8, 9);
        $this->provider->routes = [];
        $this->provider->failOn['POST routes'] = FakeProvider::rateLimited(60);

        $r = $this->reconciler->run();

        $this->assertSame(60, $r->stoppedForRetryAfter);
        $this->assertSame([...self::LISTS, 'POST routes'], $this->provider->calls, 'nothing after the 429');
        $this->assertSame([], $this->panelWrites());
        foreach ([7, 8, 9] as $id) {
            $this->assertSame($this->now + 60, $this->store->get($id)->retryAfterUntil);
        }

        $this->clearCalls();
        $this->now += 59;
        $r = $this->reconciler->run();
        $this->assertSame([], $this->provider->calls, 'inside the window: no provider call at all');
        $this->assertSame(1, $r->stoppedForRetryAfter);

        $this->now += 1;
        $r = $this->reconciler->run();
        $this->assertNull($r->stoppedForRetryAfter);
        $this->assertSame(3, $this->callCount('POST routes'));
        $this->assertCount(3, $this->provider->routes);
    }

    public function testRateLimitWithoutRetryAfterWaitsSixtySeconds(): void
    {
        $this->start(Mode::Both);
        $this->provider->failOn['GET routes'] = FakeProvider::rateLimited(null);

        $r = $this->reconciler->run();

        $this->assertSame(60, $r->stoppedForRetryAfter);
        $this->assertSame(['GET routes'], $this->provider->calls);
        $this->assertSame($this->now + 60, $this->store->get(7)->retryAfterUntil);
    }

    public function testOneListCallPerRun(): void
    {
        $this->addServer(8, 25566);
        $this->addServer(9, 25567);
        $this->start(Mode::Both, 7, 8);
        $this->start(Mode::GatewayOnly, 9);
        unset($this->provider->routes[$this->domain(8)]);

        $this->reconciler->run();

        $this->assertSame(1, $this->callCount('GET routes'));
        $this->assertSame(1, $this->callCount('GET link-kits'));
        $this->assertSame(1, $this->callCount('POST routes'));
    }

    public function testTheSummaryCountsRoutesAndErrors(): void
    {
        $this->addServer(8, 25566);
        $this->addServer(9, 25567);
        $this->start(Mode::Both, 7, 8, 9);
        unset($this->provider->routes[$this->domain(7)]); // missing at the provider: created
        $this->panel->removeServer(8); // deleted in the panel: its route deleted
        $q = $this->panel->addAllocation(1, '10.0.0.1', 25590);
        $this->panel->assign(9, $q->id);
        $this->panel->setPrimary(9, $q->id); // port changed: retargeted

        $r = $this->reconciler->run();

        $this->assertSame([1, 1, 1, 0], [$r->created, $r->deleted, $r->retargeted, count($r->errors)]);
        $this->assertSame('Routes: 1 created, 1 deleted, 1 retargeted. Errors: 0.', $r->summary()[0]);
        $this->assertCount(4, $r->summary(), 'plus one line per fixed server');
    }

    /** A direct server kept "survival"; the hoster later made survival.<domain> in the dashboard, on another link. */
    public function testADirectStateNeverDeletesARouteOnALinkThePluginDoesNotHold(): void
    {
        $this->modes->apply(7, Mode::Both, 'survival');
        $this->modes->apply(7, Mode::Direct);
        $domain = $this->domain();
        $dashboard = ['domain' => $domain, 'link_id' => 'dashboard-link', 'target_ip' => '10.9.9.9', 'target_port' => 25565];
        $this->provider->routes[$domain] = $dashboard;

        foreach (['direct' => fn () => null, 'deleted' => fn () => $this->panel->removeServer(7)] as $case => $change) {
            $change();
            $this->clearCalls();
            $this->reconciler->run();

            $this->assertNotContains("DELETE routes/{$domain}", $this->provider->calls, $case);
            $this->assertSame($dashboard, $this->provider->routes[$domain], $case);
        }
        $this->assertNull($this->store->get(7), 'the deleted server state is still cleaned up');
    }

    /** A public allocation on a gateway only server makes "Gateway only" a lie; say so, change nothing. */
    public function testAPublicAllocationOnAGatewayOnlyServerIsReported(): void
    {
        $this->start(Mode::GatewayOnly);
        $extra = $this->panel->addAllocation(1, '10.0.0.1', 25580);
        $this->panel->assign(7, $extra->id); // a subuser with allocation.create added one
        $this->clearCalls();

        $r = $this->reconciler->run();

        $want = Reconciler::exposed('10.0.0.1', 25580);
        $this->assertSame([7 => $want], $r->errors);
        $this->assertSame($want, $this->store->get(7)->lastError);
        $this->assertSame(Mode::GatewayOnly, $this->store->get(7)->mode);
        $this->assertSame([], $this->panelWrites(), 'no automatic change');
        $this->assertSame('Gateway only, but the server holds the public allocation 10.0.0.1:25580, so players can still join it directly. Remove it in the panel (make the 127.0.0.1 allocation the primary first if it is the primary), or switch the mode to Both.', $want);

        $this->panel->unassign(7, $extra->id);
        $this->reconciler->run();
        $this->assertNull($this->store->get(7)->lastError, 'cleared once the allocation is gone');
    }

    /** A mode change that lands while a run works must not be written back to the old mode. */
    public function testARunNeverWritesBackAStaleRow(): void
    {
        $this->start(Mode::GatewayOnly);
        unset($this->provider->routes[$this->domain()]); // gives the run something to write
        $this->panel->onServer = function (int $id) {
            $this->panel->onServer = null;
            $s = $this->store->get($id);
            $s->mode = Mode::Direct; // the switch to Direct finished meanwhile
            $this->store->put($s);
        };

        $this->reconciler->run();

        $this->assertSame(Mode::Direct, $this->store->get(7)->mode);
    }

    /** An unanswered proxy-off call leaves the provider and the state apart; the run brings the provider in line. */
    public function testProxyProtocolFollowsTheState(): void
    {
        $this->start(Mode::GatewayOnly);
        $this->modes->setRealIp(7, true);
        $this->provider->routes[$this->domain()]['proxy_protocol'] = false;
        $this->clearCalls();

        $r = $this->reconciler->run();

        $this->assertSame([...self::LISTS, "PATCH routes/{$this->domain()}/proxy-protocol"], $this->provider->calls);
        $this->assertSame(['enabled' => true], $this->provider->bodies[2]);
        $this->assertCount(1, $r->fixed);
    }

    public function testAPrimaryPortOf1024OrBelowIsReported(): void
    {
        $this->start(Mode::Both);
        $low = $this->panel->addAllocation(1, '10.0.0.1', 1000);
        $this->panel->assign(7, $low->id);
        $this->panel->setPrimary(7, $low->id);
        $this->clearCalls();

        $r = $this->reconciler->run();

        $this->assertSame([7 => ModeService::portTooLow(1000)], $r->errors);
        $this->assertNotContains('findOrCreateLoopback', $this->panelWrites());
    }
}
