<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel\Tests;

use Dylaris\GatewayPanel\Mode;
use Dylaris\GatewayPanel\ModeService;
use Dylaris\GatewayPanel\NodeState;
use Dylaris\GatewayPanel\Provider\ProviderException;
use Dylaris\GatewayPanel\StatusService;
use Dylaris\GatewayPanel\Testing\FakePanel;
use Dylaris\GatewayPanel\Testing\InMemoryStateStore;
use PHPUnit\Framework\TestCase;

final class StatusServiceTest extends TestCase
{
    public function testStatusCombinesRouteKitAndPlayers(): void
    {
        $provider = new FakeProvider();
        $panel = new FakePanel();
        $store = new InMemoryStateStore();
        $store->putNode(new NodeState(1, 'link-1', 'e2e', [Mode::Direct, Mode::Both, Mode::GatewayOnly]));
        $panel->addServer(7, 1, $panel->addAllocation(1, '10.0.0.1', 25565));
        $panel->addServer(8, 1, $panel->addAllocation(1, '10.0.0.1', 25566));
        (new ModeService($provider->client(), $panel, $store))->apply(7, Mode::Both);
        $domain = $store->get(7)->domain();
        $provider->players = [['domain' => 'other.play.e2e.test', 'java' => 9, 'bedrock' => 9], ['domain' => $domain, 'java' => 3, 'bedrock' => 1]];
        $svc = new StatusService($provider->client(), $store);

        $this->assertSame(
            ['route' => true, 'linkOnline' => true, 'nodeConnected' => true, 'java' => 3, 'bedrock' => 1, 'restartNeeded' => true, 'lastError' => null],
            $svc->forServer(7),
        );

        $provider->kits = [['link_id' => 'link-1', 'online' => false]];
        $provider->routes = [];
        $provider->players = [];
        $store->get(7)->lastError = 'boom';
        $this->assertSame(
            ['route' => false, 'linkOnline' => false, 'nodeConnected' => true, 'java' => 0, 'bedrock' => 0, 'restartNeeded' => true, 'lastError' => 'boom'],
            $svc->forServer(7),
        );

        // Direct, with or without a state row: nothing to ask the provider.
        $provider->calls = [];
        $direct = ['route' => false, 'linkOnline' => false, 'nodeConnected' => false, 'java' => 0, 'bedrock' => 0, 'restartNeeded' => false, 'lastError' => null];
        $this->assertSame($direct, $svc->forServer(8));
        $store->get(7)->mode = Mode::Direct;
        $store->get(7)->restartNeeded = false;
        $store->get(7)->lastError = null;
        $this->assertSame($direct, $svc->forServer(7));
        $this->assertSame([], $provider->calls);
    }

    /** Found live: after a move to a node without a link the page said "Route active, link online". */
    public function testANodeWithoutALinkIsNotReportedAsActive(): void
    {
        $provider = new FakeProvider();
        $panel = new FakePanel();
        $store = new InMemoryStateStore();
        $store->putNode(new NodeState(1, 'link-1', 'e2e', [Mode::Direct, Mode::Both, Mode::GatewayOnly]));
        $panel->addServer(7, 1, $panel->addAllocation(1, '10.0.0.1', 25565));
        (new ModeService($provider->client(), $panel, $store))->apply(7, Mode::Both);
        $svc = new StatusService($provider->client(), $store, $panel);
        $this->assertSame('Route active, link online, 0 Java and 0 Bedrock players.', StatusService::line($svc->forServer(7)));

        // The panel moved the server to node 2, which has no link; the route still points at node 1's.
        $panel->removeServer(7);
        $panel->addServer(7, 2, $panel->addAllocation(2, '10.0.0.9', 25565));
        $st = $svc->forServer(7);

        $this->assertFalse($st['nodeConnected']);
        $this->assertFalse($st['route']);
        $this->assertFalse($st['linkOnline']);
        $this->assertSame('This node is not connected to the gateway.', StatusService::line($st));
    }

    public function testNoProviderCallWhileTheProviderAskedToWait(): void
    {
        $provider = new FakeProvider();
        $panel = new FakePanel();
        $store = new InMemoryStateStore();
        $store->putNode(new NodeState(1, 'link-1', 'e2e', [Mode::Direct, Mode::Both, Mode::GatewayOnly]));
        $panel->addServer(7, 1, $panel->addAllocation(1, '10.0.0.1', 25565));
        (new ModeService($provider->client(), $panel, $store))->apply(7, Mode::Both);
        $store->get(7)->retryAfterUntil = time() + 60;
        $provider->calls = [];

        try {
            (new StatusService($provider->client(), $store))->forServer(7);
            $this->fail('expected the busy sentence');
        } catch (ProviderException $e) {
            $this->assertSame(ModeService::PROVIDER_BUSY, $e->userMessage);
        }
        $this->assertSame([], $provider->calls);
    }
}
