<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel\Tests;

use Dylaris\GatewayPanel\AllowedDomain;
use Dylaris\GatewayPanel\Allocation;
use Dylaris\GatewayPanel\DomainEntry;
use Dylaris\GatewayPanel\DomainSync;
use Dylaris\GatewayPanel\Mode;
use Dylaris\GatewayPanel\ModeRefused;
use Dylaris\GatewayPanel\ModeService;
use Dylaris\GatewayPanel\NodeState;
use Dylaris\GatewayPanel\Provider\ProviderClient;
use Dylaris\GatewayPanel\Provider\ProviderException;
use Dylaris\GatewayPanel\ServerState;
use Dylaris\GatewayPanel\Testing\FakePanel;
use Dylaris\GatewayPanel\Testing\InMemoryStateStore;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class ModeServiceTest extends TestCase
{
    private const ALL = [Mode::Direct, Mode::Both, Mode::GatewayOnly];
    private const RESTART = 'Restart the server to apply the change.';

    /** @var list<string> provider calls as "METHOD path" (path after /api/external/gateway/) */
    private array $calls = [];
    /** @var list<mixed> decoded JSON bodies, index-aligned with $calls */
    private array $bodies = [];
    private ?string $failOn = null;
    /** @var array<string, Response|\Throwable> one-shot answers for a call, instead of the normal one */
    private array $responses = [];
    private string $checkDomainBody = '{"available":true,"reason":"","success":true}';
    private string $routesBody = '{"items":[],"success":true}';
    private ?string $regionsBody = null;
    /** @var list<string> check-domain answers used before $checkDomainBody, one per call */
    private array $checkDomainQueue = [];

    private FakePanel $panel;
    private InMemoryStateStore $store;
    private ModeService $svc;
    private Allocation $public;
    private string $domain;

    protected function setUp(): void
    {
        $this->panel = new FakePanel();
        $this->store = new InMemoryStateStore();
        $this->store->putNode(new NodeState(1, 'link-1', 'e2e', self::ALL));
        $this->public = $this->panel->addAllocation(1, '10.0.0.1', 25565);
        $this->panel->addServer(7, 1, $this->public);
        $this->svc = new ModeService($this->provider(), $this->panel, $this->store);
        $this->domain = $this->panel->server(7)->shortId . '.play.e2e.test';
        // The domain list is synced once here, so each test sees only the calls of its own change.
        $this->svc->domainChoices(1, false);
        $this->calls = $this->bodies = [];
    }

    private function provider(): ProviderClient
    {
        $handler = function (RequestInterface $r) {
            $call = $r->getMethod() . ' ' . substr($r->getUri()->getPath(), strlen('/api/external/gateway/'));
            $this->calls[] = $call;
            $this->bodies[] = json_decode((string) $r->getBody(), true);
            if (isset($this->responses[$call])) {
                $res = $this->responses[$call];
                unset($this->responses[$call]);
                return $res instanceof \Throwable ? Create::rejectionFor($res) : Create::promiseFor($res);
            }
            if ($call === $this->failOn) {
                return Create::promiseFor(new Response(503, [], '{"code":"unavailable","error":"down"}'));
            }
            $body = match ($call) {
                'GET regions' => $this->regionsBody ?? file_get_contents(__DIR__ . '/fixtures/provider/regions.json'),
                'GET check-domain' => array_shift($this->checkDomainQueue) ?? $this->checkDomainBody,
                'GET routes' => $this->routesBody,
                default => '{"success":true}',
            };
            return Create::promiseFor(new Response(200, [], $body));
        };
        return new ProviderClient('https://api.example.test', 'key', new Client(['handler' => HandlerStack::create($handler)]));
    }

    /** Brings server 7 into $mode, then clears every call log so the test sees only its own calls. */
    private function startIn(Mode $mode): void
    {
        $this->svc->apply(7, $mode);
        $this->calls = $this->bodies = $this->panel->calls = [];
    }

    /** @return list<string> server 7's allocations as "ip:port", primary first */
    private function allocs(int $serverId = 7): array
    {
        $s = $this->panel->server($serverId);
        $rest = array_filter($s->allocations, fn (Allocation $a) => $a->id !== $s->primary->id);
        $out = array_map(fn (Allocation $a) => "{$a->ip}:{$a->port}", array_values($rest));
        sort($out);
        return ["{$s->primary->ip}:{$s->primary->port}", ...$out];
    }

    private function loopbacksOnNode(): int
    {
        $n = 0;
        for ($id = 1; $id <= 50; $id++) {
            $n += ($this->panel->allocation($id)?->ip === '127.0.0.1') ? 1 : 0;
        }
        return $n;
    }

    private function countLoopbacks(int $port): int
    {
        $n = 0;
        for ($id = 1; $id <= 50; $id++) {
            $a = $this->panel->allocation($id);
            $n += ($a?->ip === '127.0.0.1' && $a->port === $port) ? 1 : 0;
        }
        return $n;
    }

    /** @return list<string> panel calls that change something */
    private function panelWrites(): array
    {
        $writes = array_filter($this->panel->calls, fn (array $c) => !in_array($c[0], ['server', 'allServerIds', 'freePublic'], true));
        return array_values(array_map(fn (array $c) => $c[0], $writes));
    }

    private function refusal(callable $f): string
    {
        try {
            $f();
        } catch (ModeRefused $e) {
            return $e->userMessage;
        }
        $this->fail('expected ModeRefused');
    }

    public function testDirectToBoth(): void
    {
        $r = $this->svc->apply(7, Mode::Both);

        $this->assertTrue($r->restartNeeded);
        $this->assertSame(self::RESTART, $r->notice);
        $this->assertSame(['10.0.0.1:25565', '127.0.0.1:25565'], $this->allocs());
        $this->assertSame(['GET routes', 'GET check-domain', 'POST routes'], $this->calls);
        $this->assertSame([
            'linkId' => 'link-1',
            'subdomain' => $this->panel->server(7)->shortId,
            'hosterDomain' => 'play.e2e.test',
            'targetHost' => '172.18.0.1',
            'targetPort' => 25565,
        ], $this->bodies[2]);
        $s = $this->store->get(7);
        $this->assertSame(Mode::Both, $s->mode);
        $this->assertSame(25565, $s->port);
        $this->assertNull($s->publicPort);
        $this->assertTrue($s->restartNeeded);
    }

    public function testDirectToGatewayOnly(): void
    {
        $r = $this->svc->apply(7, Mode::GatewayOnly);

        $this->assertSame(self::RESTART, $r->notice);
        $this->assertSame(['127.0.0.1:25565'], $this->allocs());
        $this->assertNull($this->panel->allocation($this->public->id)->serverId);
        $this->assertSame(['GET routes', 'GET check-domain', 'POST routes'], $this->calls);
        $this->assertSame(['findOrCreateLoopback', 'assign', 'setPrimary', 'unassign'], $this->panelWrites());
        $s = $this->store->get(7);
        $this->assertSame(Mode::GatewayOnly, $s->mode);
        $this->assertSame(25565, $s->publicPort);
    }

    public function testBothToGatewayOnly(): void
    {
        $this->startIn(Mode::Both);
        $r = $this->svc->apply(7, Mode::GatewayOnly);

        $this->assertSame(self::RESTART, $r->notice);
        $this->assertSame(['127.0.0.1:25565'], $this->allocs());
        $this->assertSame([], $this->calls);
        $this->assertSame(['setPrimary', 'unassign'], $this->panelWrites());
        $this->assertSame(Mode::GatewayOnly, $this->store->get(7)->mode);
        $this->assertSame(25565, $this->store->get(7)->publicPort);
    }

    public function testGatewayOnlyToBoth(): void
    {
        $this->startIn(Mode::GatewayOnly);
        $this->svc->setRealIp(7, true);
        $this->calls = $this->bodies = [];

        $this->assertSame([ModeService::PROXY_OFF], $this->svc->preview(7, Mode::Both), 'said before the switch');
        $this->calls = [];

        $r = $this->svc->apply(7, Mode::Both);

        $this->assertSame(self::RESTART . ' ' . ModeService::PROXY_OFF, $r->notice, 'and after it');
        $this->assertSame(['10.0.0.1:25565', '127.0.0.1:25565'], $this->allocs());
        $this->assertSame(["PATCH routes/{$this->domain}/proxy-protocol"], $this->calls);
        $this->assertSame(['enabled' => false], $this->bodies[0]);
        $s = $this->store->get(7);
        $this->assertSame(Mode::Both, $s->mode);
        $this->assertFalse($s->proxyProtocol);
        $this->assertNull($s->publicPort);
    }

    public function testGatewayOnlyToBothReportsChangedPort(): void
    {
        $this->startIn(Mode::GatewayOnly);
        $this->panel->addServer(9, 1, $this->public); // someone else took the old public port
        $this->panel->addAllocation(1, '10.0.0.1', 25570);

        $this->assertSame(['Port 25565 is taken, so the direct port will be 25570.'], $this->svc->preview(7, Mode::Both));
        $this->assertSame([], $this->panelWrites(), 'the preview changes nothing');

        $r = $this->svc->apply(7, Mode::Both);

        $this->assertSame(self::RESTART . ' The direct port is now 25570.', $r->notice);
        // Wings runs the game on the primary port, so the loopback and the route follow it.
        $this->assertSame(['10.0.0.1:25570', '127.0.0.1:25570'], $this->allocs());
        $this->assertSame(0, $this->countLoopbacks(25565), 'old loopback released');
        $this->assertSame(['POST routes'], $this->calls);
        $this->assertSame(25570, $this->bodies[0]['targetPort']);
        $this->assertSame('172.18.0.1', $this->bodies[0]['targetHost']);
        $this->assertSame(25570, $this->store->get(7)->port);
    }

    public function testRetargetFailureOnChangedPortRollsBack(): void
    {
        $this->startIn(Mode::GatewayOnly);
        $this->panel->addServer(9, 1, $this->public);
        $q = $this->panel->addAllocation(1, '10.0.0.1', 25570);
        $before = clone $this->store->get(7);
        $this->responses['POST routes'] = new Response(503, [], '{"code":"unavailable","error":"down"}');

        try {
            $this->svc->apply(7, Mode::Both);
            $this->fail('expected ProviderException');
        } catch (ProviderException $e) {
            $this->assertSame(503, $e->status);
        }

        $this->assertSame(['127.0.0.1:25565'], $this->allocs());
        $this->assertNull($this->panel->allocation($q->id)->serverId);
        $this->assertSame(0, $this->countLoopbacks(25570), 'new loopback removed again');
        $this->assertEquals($before, $this->store->get(7));
        $this->assertSame(['POST routes', 'POST routes'], array_values(array_filter($this->calls, fn ($c) => $c === 'POST routes')));
        $this->assertSame(25565, end($this->bodies)['targetPort'], 'a 503 may have retargeted the route, so it is pointed back');
    }

    public function testGatewayOnlyWithoutFreePublicPortIsRefused(): void
    {
        $this->startIn(Mode::GatewayOnly);
        $this->panel->addServer(9, 1, $this->public);

        foreach ([Mode::Both, Mode::Direct] as $to) {
            $this->assertSame('No free public port on this node.', $this->refusal(fn () => $this->svc->apply(7, $to)));
        }
        $this->assertSame(['127.0.0.1:25565'], $this->allocs());
        $this->assertSame(Mode::GatewayOnly, $this->store->get(7)->mode);
        $this->assertSame([], $this->calls);
    }

    public function testBothToDirect(): void
    {
        $this->startIn(Mode::Both);
        $r = $this->svc->apply(7, Mode::Direct);

        $this->assertSame(self::RESTART, $r->notice);
        $this->assertSame(['10.0.0.1:25565'], $this->allocs());
        $this->assertSame(0, $this->loopbacksOnNode());
        $this->assertSame(["DELETE routes/{$this->domain}"], $this->calls);
        $s = $this->store->get(7);
        $this->assertSame(Mode::Direct, $s->mode);
        $this->assertSame($this->panel->server(7)->shortId, $s->label, 'label is kept for switching back');
        $this->assertNull($s->lastError);
    }

    public function testGatewayOnlyToDirect(): void
    {
        $this->startIn(Mode::GatewayOnly);
        $r = $this->svc->apply(7, Mode::Direct);

        $this->assertSame(self::RESTART, $r->notice);
        $this->assertSame(['10.0.0.1:25565'], $this->allocs());
        $this->assertSame(0, $this->loopbacksOnNode());
        $this->assertSame(["DELETE routes/{$this->domain}"], $this->calls);
        $this->assertSame(['assign', 'setPrimary', 'unassign', 'deleteIfUnassigned'], $this->panelWrites());
        $this->assertSame(Mode::Direct, $this->store->get(7)->mode);
        $this->assertNull($this->store->get(7)->publicPort);
    }

    public function testProviderFailureDuringCreateRouteRollsBack(): void
    {
        $this->failOn = 'POST routes';
        foreach ([Mode::Both, Mode::GatewayOnly] as $to) {
            try {
                $this->svc->apply(7, $to);
                $this->fail('expected ProviderException');
            } catch (ProviderException $e) {
                $this->assertSame(503, $e->status);
            }
            $this->assertSame(['10.0.0.1:25565'], $this->allocs());
            $this->assertSame(0, $this->loopbacksOnNode());
            $this->assertNull($this->store->get(7), 'no state, so the mode is still direct');
        }
        $this->assertContains("DELETE routes/{$this->domain}", $this->calls, 'a 503 may have created the route after all');
    }

    public function testProviderFailureDuringDeleteRouteRestoresPanel(): void
    {
        $this->startIn(Mode::GatewayOnly);
        $this->failOn = "DELETE routes/{$this->domain}";

        $this->expectException(ProviderException::class);
        try {
            $this->svc->apply(7, Mode::Direct);
        } finally {
            $this->assertSame(['127.0.0.1:25565'], $this->allocs());
            $this->assertNull($this->panel->allocation($this->public->id)->serverId);
            $this->assertSame(Mode::GatewayOnly, $this->store->get(7)->mode);
            $this->assertSame(25565, $this->store->get(7)->publicPort);
        }
    }

    public function testRollbackFailureIsRecordedAndOriginalRethrown(): void
    {
        $this->failOn = 'POST routes';
        $this->panel->failNext('unassign');
        try {
            $this->svc->apply(7, Mode::Both);
            $this->fail('expected ProviderException');
        } catch (ProviderException $e) {
            $this->assertSame(503, $e->status);
        }
        $s = $this->store->get(7);
        $this->assertNotNull($s, 'the rollback failure must be recorded');
        $this->assertSame(Mode::Direct, $s->mode);
        $this->assertStringContainsString('injected failure in unassign', (string) $s->lastError);
    }

    public function testCleanupFailureAfterRouteDeleteKeepsDirectAndIsReapplicable(): void
    {
        $this->startIn(Mode::Both);
        $this->panel->failNext('unassign');

        $r = $this->svc->apply(7, Mode::Direct);

        $this->assertTrue($r->restartNeeded);
        $s = $this->store->get(7);
        $this->assertSame(Mode::Direct, $s->mode);
        $this->assertStringContainsString('injected failure in unassign', (string) $s->lastError);
        $this->assertSame(['10.0.0.1:25565', '127.0.0.1:25565'], $this->allocs(), 'loopback left for reconcile');

        // The leftover loopback is the server's own: switching back reuses it instead of being refused.
        $this->svc->apply(7, Mode::Both);
        $this->assertSame(['10.0.0.1:25565', '127.0.0.1:25565'], $this->allocs());
        $this->assertSame(Mode::Both, $this->store->get(7)->mode);
        $this->assertNull($this->store->get(7)->lastError);
    }

    public function testModeNotAllowedOnNodeIsRefused(): void
    {
        $this->store->putNode(new NodeState(1, 'link-1', 'e2e', [Mode::Direct, Mode::Both]));

        $this->assertSame('This mode is not allowed on this node.', $this->refusal(fn () => $this->svc->apply(7, Mode::GatewayOnly)));
        $this->assertSame([], $this->calls);
        $this->assertNull($this->store->get(7));

        $this->svc->apply(7, Mode::GatewayOnly, null, true);
        $this->assertSame(Mode::GatewayOnly, $this->store->get(7)->mode);
    }

    public function testNodeWithoutLinkRefusesGatewayModes(): void
    {
        $this->store->putNode(new NodeState(1, null, null, self::ALL));
        foreach ([Mode::Both, Mode::GatewayOnly] as $to) {
            $this->assertSame(ModeService::NOT_CONNECTED, $this->refusal(fn () => $this->svc->apply(7, $to, null, true)));
        }
        $this->assertSame([], $this->calls);
        $this->assertSame(['10.0.0.1:25565'], $this->allocs());
    }

    public function testLoopbackPortHeldByOtherServerIsRefused(): void
    {
        // Same public port on a different IP: both servers want 127.0.0.1:25565.
        $this->panel->addServer(8, 1, $this->panel->addAllocation(1, '10.0.0.2', 25565));
        $this->svc->apply(8, Mode::Both);
        $this->calls = [];

        $msg = $this->refusal(fn () => $this->svc->apply(7, Mode::Both));

        $this->assertSame('Port 25565 on this node is already used by another server for the gateway.', $msg);
        $this->assertSame(['10.0.0.1:25565'], $this->allocs());
        $this->assertSame(['10.0.0.2:25565', '127.0.0.1:25565'], $this->allocs(8), 'not stolen');
        $this->assertNotContains('POST routes', $this->calls);
        $this->assertNull($this->store->get(7));
    }

    public function testRealIpRefusedOutsideGatewayOnly(): void
    {
        $sentence = 'Real player IP is only available in Gateway only mode.';
        $this->assertSame($sentence, $this->refusal(fn () => $this->svc->setRealIp(7, true)));
        $this->svc->apply(7, Mode::Both);
        $this->calls = [];
        $this->assertSame($sentence, $this->refusal(fn () => $this->svc->setRealIp(7, true)));
        $this->assertSame([], $this->calls);

        $this->svc->apply(7, Mode::GatewayOnly);
        $this->svc->setRealIp(7, true);
        $this->assertSame("PATCH routes/{$this->domain}/proxy-protocol", end($this->calls));
        $this->assertSame(['enabled' => true], end($this->bodies));
        $this->assertTrue($this->store->get(7)->proxyProtocol);
    }

    public function testNarrowedModesKeepExistingServer(): void
    {
        $this->startIn(Mode::GatewayOnly);
        $this->store->putNode(new NodeState(1, 'link-1', 'e2e', [Mode::Direct]));
        $before = clone $this->store->get(7);

        $r = $this->svc->apply(7, Mode::GatewayOnly);

        $this->assertFalse($r->restartNeeded);
        $this->assertSame('', $r->notice);
        $this->assertSame([], $this->calls);
        $this->assertSame([], $this->panelWrites());
        $this->assertEquals($before, $this->store->get(7));
    }

    public function testLabelDefaultsToShortId(): void
    {
        $shortId = $this->panel->server(7)->shortId;
        $this->svc->apply(7, Mode::Both);
        $this->assertSame($shortId, $this->store->get(7)->label);
        $this->assertSame('play.e2e.test', $this->store->get(7)->hosterDomain);

        // Switching back reuses the stored address and does not check it again.
        $this->svc->apply(7, Mode::Direct);
        $this->calls = [];
        $this->svc->apply(7, Mode::Both, 'other');
        $this->assertSame($shortId, $this->store->get(7)->label);
        $this->assertSame(['GET routes', 'POST routes'], $this->calls);

        // A server's first address takes the given label.
        $this->panel->addServer(8, 1, $this->panel->addAllocation(1, '10.0.0.1', 25566));
        $this->svc->apply(8, Mode::Both, 'lobby');
        $this->assertSame('lobby', $this->store->get(8)->label);
    }

    public function testReEnableRefusedWhenAddressIsAlreadyOnTheGateway(): void
    {
        $this->startIn(Mode::Both);
        $this->svc->apply(7, Mode::Direct);
        $this->calls = $this->panel->calls = [];
        // Someone else's route now holds the remembered address.
        $this->routesBody = json_encode(['items' => [['domain' => $this->domain, 'link_id' => 'link-9', 'target_ip' => '172.18.0.1', 'target_port' => 25565]]]);

        foreach ([Mode::Both, Mode::GatewayOnly] as $to) {
            $this->assertSame('That address is already in use on the gateway.', $this->refusal(fn () => $this->svc->apply(7, $to)));
        }

        $this->assertSame(['GET routes', 'GET routes'], $this->calls);
        $this->assertSame([], $this->panelWrites());
        $this->assertSame(['10.0.0.1:25565'], $this->allocs());
        $this->assertSame(Mode::Direct, $this->store->get(7)->mode);
    }

    private function lettersOnly(): void
    {
        $this->store->putDomains([new DomainEntry('eu.example.test', DomainEntry::OFFICIAL, true, 'letters')]);
    }

    /** Found live: the short id (hex) was the default, which a letters-only hoster domain always refuses. */
    public function testDefaultLabelFollowsTheValidationAndTheServerName(): void
    {
        $this->lettersOnly();
        $this->panel->addServer(8, 1, $this->panel->addAllocation(1, '10.0.0.1', 25566), 'Paper A 1.21');

        $this->assertSame('papera', $this->svc->suggestLabel(8));
        $this->svc->apply(8, Mode::Both);

        $this->assertSame('papera', $this->store->get(8)->label);
        $this->assertSame('eu.example.test', $this->store->get(8)->hosterDomain);
    }

    public function testATakenNameFallsBackToLetters(): void
    {
        $this->lettersOnly();
        $this->panel->addServer(8, 1, $this->panel->addAllocation(1, '10.0.0.1', 25566), 'Lobby');
        $this->checkDomainQueue = ['{"available":false,"reason":"taken","success":true}'];

        $this->svc->apply(8, Mode::Both);

        $label = $this->store->get(8)->label;
        $this->assertMatchesRegularExpression('/^[a-z]{8}$/', $label);
        $this->assertNotSame('lobby', $label);
        $this->assertSame(['GET routes', 'GET check-domain', 'GET check-domain', 'POST routes'], $this->calls);
    }

    public function testInvalidLabelRefusalNamesTheRule(): void
    {
        $this->lettersOnly();
        $this->checkDomainBody = '{"available":false,"reason":"invalid","success":true}';

        $msg = $this->refusal(fn () => $this->svc->apply(7, Mode::Both, 'lobby2'));

        $this->assertSame('That address name is not allowed here. Use letters only (a-z), at most 63 characters.', $msg);
    }

    public function testCheckDomainUnavailableRefuses(): void
    {
        $this->checkDomainBody = '{"available":false,"reason":"already taken","success":true}';

        $msg = $this->refusal(fn () => $this->svc->apply(7, Mode::Both, 'lobby'));

        $this->assertSame('That address is not available (already taken).', $msg);
        $this->assertSame(['GET routes', 'GET check-domain'], $this->calls);
        $this->assertSame(['10.0.0.1:25565'], $this->allocs());
        $this->assertNull($this->store->get(7));
    }

    public function testSetBedrockNeedsAnAddress(): void
    {
        $this->assertNotSame('', $this->refusal(fn () => $this->svc->setBedrock(7, true)));
        $this->startIn(Mode::Both);

        $this->svc->setBedrock(7, true);

        $this->assertSame(["PUT routes/{$this->domain}/bedrock"], $this->calls);
        $this->assertSame(['java' => true, 'bedrock' => true], $this->bodies[0]);
        $this->assertTrue($this->store->get(7)->bedrock);
    }

    public function testAnUnansweredCreateRouteIsUndone(): void
    {
        $timeout = new \GuzzleHttp\Exception\ConnectException('timeout', new \GuzzleHttp\Psr7\Request('POST', 'x'));
        foreach ([0 => $timeout, 502 => new Response(502, [], '{}')] as $status => $answer) {
            $this->calls = [];
            $this->responses['POST routes'] = $answer;
            try {
                $this->svc->apply(7, Mode::Both, 'survival');
                $this->fail('expected the switch to fail');
            } catch (ProviderException $e) {
                $this->assertSame($status, $e->status);
            }
            $this->assertSame('DELETE routes/survival.play.e2e.test', end($this->calls), "status {$status}: the route the provider may have created is deleted");
            $this->assertSame(['10.0.0.1:25565'], $this->allocs());
            $this->assertNull($this->store->get(7));
        }
    }

    public function testARouteAlreadyGoneCountsAsUndone(): void
    {
        $this->responses['POST routes'] = new Response(503, [], '{}');
        $this->responses['DELETE routes/survival.play.e2e.test'] = new Response(404, [], '{"code":"not_found","error":"Route not found."}');

        try {
            $this->svc->apply(7, Mode::Both, 'survival');
            $this->fail('expected the switch to fail');
        } catch (ProviderException) {
        }
        $this->assertNull($this->store->get(7), 'no "could not be fully undone" state is stored');
    }

    public function testARefusedCreateRouteDeletesNothing(): void
    {
        $this->responses['POST routes'] = new Response(409, [], '{"code":"taken","error":"That address is already in use."}');

        try {
            $this->svc->apply(7, Mode::Both, 'survival');
            $this->fail('expected the switch to fail');
        } catch (ProviderException) {
        }
        $this->assertNotContains('DELETE routes/survival.play.e2e.test', $this->calls, 'a 4xx created nothing, and the address may be someone else\'s');
    }

    public function testAPortOf1024OrBelowIsRefusedWithASentence(): void
    {
        foreach ([1024, 80] as $port) {
            $panel = new FakePanel();
            $panel->addServer(7, 1, $panel->addAllocation(1, '10.0.0.1', $port));
            $svc = new ModeService($this->provider(), $panel, $this->store);

            $this->assertSame(ModeService::portTooLow($port), $this->refusal(fn () => $svc->apply(7, Mode::Both)));
            $this->assertSame([], array_values(array_filter($panel->calls, fn ($c) => $c[0] !== 'server')), "port {$port}: nothing changed in the panel");
            $this->assertNull($this->store->get(7));
        }
        $this->assertSame('Port 80 cannot use the gateway: the panel allows no 127.0.0.1 allocation on port 1024 or below. Give the server a primary port above 1024 first.', ModeService::portTooLow(80));
    }

    public function testLeavingGatewayOnlyPrefersTheRememberedIpAndPort(): void
    {
        $this->panel->addAllocation(1, '10.0.0.1', 25570); // same port, another IP, older allocation
        $ip2 = $this->panel->addAllocation(1, '10.0.0.2', 25570);
        $this->panel->addServer(9, 1, $ip2);
        $this->svc->apply(9, Mode::GatewayOnly);
        $this->assertSame('10.0.0.2', $this->store->get(9)->publicIp);

        $this->assertSame([], $this->svc->preview(9, Mode::Direct));
        $this->svc->apply(9, Mode::Direct);

        $this->assertSame(['10.0.0.2:25570'], $this->allocs(9));
        $this->assertNull($this->store->get(9)->publicIp);
    }

    public function testATakenAddressOnAnotherIpIsSaid(): void
    {
        $this->startIn(Mode::GatewayOnly);
        $this->panel->addServer(9, 1, $this->public); // someone else took 10.0.0.1:25565
        $this->panel->addAllocation(1, '10.0.0.2', 25565);

        $this->assertSame(['10.0.0.1:25565 is taken, so the direct address will be 10.0.0.2:25565.'], $this->svc->preview(7, Mode::Both));
        $r = $this->svc->apply(7, Mode::Both);

        $this->assertSame(self::RESTART . ' The direct address is now 10.0.0.2:25565.', $r->notice);
    }

    public function testFreePublicSkipsEveryLoopbackAddress(): void
    {
        $this->panel->addAllocation(1, '127.0.0.2', 2000);
        $this->panel->addAllocation(1, '::1', 2001);
        $this->assertNull($this->panel->freePublic(1, null));
        $this->assertTrue(Allocation::isLoopbackIp('127.1.2.3'));
        $this->assertTrue(Allocation::isLoopbackIp('::1'));
        $this->assertFalse(Allocation::isLoopbackIp('10.0.0.1'));
    }

    public function testPageCallsWaitForRetryAfter(): void
    {
        $this->store->put(new ServerState(99, Mode::Direct, '', '', 1, 25565, retryAfterUntil: time() + 60));

        $this->assertSame(ModeService::PROVIDER_BUSY, $this->refusal(fn () => $this->svc->suggestLabel(7)));
        $this->assertSame([], $this->calls, 'no provider call while the provider asked to wait');
    }

    public function testNotOfferedSentence(): void
    {
        $this->assertSame('Both is no longer offered on this node. The server keeps it until you switch to another mode.', ModeService::notOffered('Both'));
    }

    /** The panel-wide list as the 0.1 per-node lists read: own domains verified, admins only = switched off. */
    private function offer(AllowedDomain ...$domains): void
    {
        $this->store->putDomains(array_map(fn (AllowedDomain $d) => new DomainEntry(
            $d->domain,
            $d->custom ? DomainEntry::OWN : DomainEntry::OFFICIAL,
            !$d->adminOnly,
            $d->validation,
            ownState: $d->custom ? 'verified' : '',
        ), $domains));
    }

    public function testAnEmptyListIsSyncedWithTheProviderWhenItIsNeeded(): void
    {
        $this->store->putDomains([]);
        $this->store->putDomainSync(new DomainSync());

        $choices = $this->svc->domainChoices(1, false);

        $this->assertEquals([new AllowedDomain('play.e2e.test', false, false, 'alphanumeric', 'e2e')], $choices);
        $this->assertSame(['GET regions', 'GET custom-domains'], $this->calls);
        $this->svc->domainChoices(1, false);
        $this->assertSame(['GET regions', 'GET custom-domains'], $this->calls, 'a fresh list asks the provider nothing');
    }

    public function testSavedDomainsLeaveTheAdminsOnlyOnesOutForEveryoneElse(): void
    {
        $this->offer(new AllowedDomain('eu.example.test', false, false, 'letters'), new AllowedDomain('vip.example.com', true, true));

        $this->assertSame(['eu.example.test'], array_map(fn ($d) => $d->domain, $this->svc->domainChoices(1, false)));
        $this->assertSame(['eu.example.test', 'vip.example.com'], array_map(fn ($d) => $d->domain, $this->svc->domainChoices(1, true)));
        $this->assertSame([], $this->calls, 'a saved list asks the provider nothing');

        $this->assertSame('That domain is not offered for this server.', $this->refusal(fn () => $this->svc->apply(7, Mode::Both, null, false, 'vip.example.com')));
        $this->svc->apply(7, Mode::Both, null, true, 'vip.example.com');
        $this->assertSame('vip.example.com', $this->store->get(7)->domain());
    }

    public function testADomainOfAnotherRegionIsNotOfferedOnTheNode(): void
    {
        $this->store->putDomains([
            new DomainEntry('play.e2e.test', DomainEntry::OFFICIAL, true, 'alphanumeric', 'e2e'),
            new DomainEntry('us.example.test', DomainEntry::OFFICIAL, true, 'letters', 'us-east'),
        ]);

        $this->assertSame(['play.e2e.test'], array_map(fn ($d) => $d->domain, $this->svc->domainChoices(1, true)));
        $this->assertSame('That domain is not offered for this server.', $this->refusal(fn () => $this->svc->apply(7, Mode::Both, 'lobby', true, 'us.example.test')));
    }

    public function testAFirstAddressTakesTheChosenHosterDomainAndLabel(): void
    {
        $this->offer(new AllowedDomain('eu.example.test', false, false, 'letters'), new AllowedDomain('us.example.test', false, false, 'letters'));

        $this->svc->apply(7, Mode::Both, 'lobby', false, 'us.example.test');

        $this->assertSame('lobby.us.example.test', $this->store->get(7)->domain());
        $this->assertSame(['GET routes', 'GET check-domain', 'POST routes'], $this->calls);
        $this->assertSame(['subdomain' => 'lobby', 'hosterDomain' => 'us.example.test'], array_intersect_key($this->bodies[2], ['subdomain' => 1, 'hosterDomain' => 1]));
    }

    public function testAnOwnDomainIsTheWholeAddress(): void
    {
        $this->offer(new AllowedDomain('play.example.com', true));

        $this->assertSame('', $this->svc->suggestLabel(7), 'an own domain takes no name');
        $this->svc->apply(7, Mode::Both, 'ignored', false, 'play.example.com');

        $s = $this->store->get(7);
        $this->assertSame(['', 'play.example.com', 'play.example.com'], [$s->label, $s->hosterDomain, $s->domain()]);
        $this->assertSame(['GET routes', 'GET check-domain', 'POST routes'], $this->calls);
        $this->assertSame(['linkId' => 'link-1', 'customDomain' => 'play.example.com', 'targetHost' => '172.18.0.1', 'targetPort' => 25565], $this->bodies[2]);
    }

    public function testAnUnverifiedOwnDomainIsRefusedByTheCheck(): void
    {
        $this->offer(new AllowedDomain('play.example.com', true));
        $this->checkDomainBody = '{"available":false,"reason":"unverified","success":true}';

        $this->assertSame('That domain is not verified for this account yet.', $this->refusal(fn () => $this->svc->apply(7, Mode::Both, null, false, 'play.example.com')));
        $this->assertNull($this->store->get(7));
    }

    public function testAnAddressAnotherGatewayServerHoldsIsRefused(): void
    {
        $this->svc->apply(7, Mode::Both, 'lobby');
        $this->panel->addServer(8, 1, $this->panel->addAllocation(1, '10.0.0.1', 25566));
        $this->calls = [];

        $this->assertSame('That address is already used by another server.', $this->refusal(fn () => $this->svc->apply(8, Mode::Both, 'lobby')));
        $this->assertSame([], $this->calls, 'refused before the provider is asked');
    }

    public function testChangingTheAddressBehindTheGatewayCreatesTheNewRouteBeforeDeletingTheOld(): void
    {
        $this->svc->apply(7, Mode::GatewayOnly, 'old');
        $this->svc->setRealIp(7, true);
        $this->svc->setBedrock(7, true);
        $this->calls = $this->bodies = $this->panel->calls = [];

        $r = $this->svc->changeAddress(7, 'New', 'play.e2e.test');

        $this->assertSame([
            'GET routes', 'GET check-domain', 'POST routes',
            'PATCH routes/new.play.e2e.test/proxy-protocol', 'PUT routes/new.play.e2e.test/bedrock',
            'DELETE routes/old.play.e2e.test',
        ], $this->calls);
        $this->assertSame('new', $this->bodies[2]['subdomain']);
        $this->assertSame(['enabled' => true], $this->bodies[3]);
        $this->assertSame([], $this->panelWrites(), 'allocations stay as they are');
        $this->assertFalse($r->restartNeeded, 'the route target is unchanged, so no restart');
        $this->assertSame('The gateway address is now new.play.e2e.test. The old address old.play.e2e.test no longer works: tell your players. Download the Floodgate key again and replace plugins/floodgate/key.pem: the new address has its own key.', $r->notice);
        $s = $this->store->get(7);
        $this->assertSame(['new.play.e2e.test', Mode::GatewayOnly, true, true], [$s->domain(), $s->mode, $s->proxyProtocol, $s->bedrock]);
    }

    public function testAFailedNewRouteLeavesTheOldAddressWorking(): void
    {
        $this->svc->apply(7, Mode::Both, 'old');
        $before = $this->store->get(7);
        $this->calls = [];
        $this->responses['POST routes'] = new Response(503, [], '{}');

        try {
            $this->svc->changeAddress(7, 'new', 'play.e2e.test');
            $this->fail('expected the change to fail');
        } catch (ProviderException) {
        }

        $this->assertSame('DELETE routes/new.play.e2e.test', end($this->calls), 'the route the provider may have made is removed');
        $this->assertNotContains('DELETE routes/old.play.e2e.test', $this->calls);
        $this->assertEquals($before, $this->store->get(7));
    }

    public function testAFailedDeleteOfTheOldRouteKeepsTheNewAddress(): void
    {
        $this->svc->apply(7, Mode::Both, 'old');
        $this->responses['DELETE routes/old.play.e2e.test'] = new Response(503, [], '{"code":"unavailable","error":"down"}');

        $r = $this->svc->changeAddress(7, 'new', 'play.e2e.test');

        $this->assertSame('The gateway address is now new.play.e2e.test.', $r->notice);
        $s = $this->store->get(7);
        $this->assertSame('new.play.e2e.test', $s->domain());
        $this->assertSame('The address changed to new.play.e2e.test, but the old address old.play.e2e.test could not be removed: down It may still work; remove it in the DYLARIS dashboard.', $s->lastError);
    }

    public function testChangingTheAddressInDirectOnlyRemembersIt(): void
    {
        $r = $this->svc->changeAddress(7, 'later', 'play.e2e.test');

        $this->assertSame('The address later.play.e2e.test is saved. It is used when the gateway is switched on.', $r->notice);
        $this->assertSame(['GET routes', 'GET check-domain'], $this->calls);
        $this->assertSame(Mode::Direct, $this->store->get(7)->mode);
        $this->calls = [];

        $this->svc->apply(7, Mode::Both);
        $this->assertSame('later.play.e2e.test', $this->store->get(7)->domain());
        $this->assertSame(['GET routes', 'POST routes'], $this->calls);
    }

    public function testChangingToANameAlreadyOnTheGatewayIsRefused(): void
    {
        $this->svc->apply(7, Mode::Both, 'old');
        $this->routesBody = json_encode(['items' => [['domain' => 'new.play.e2e.test', 'link_id' => 'link-9']]]);

        $this->assertSame('That address is already in use on the gateway.', $this->refusal(fn () => $this->svc->changeAddress(7, 'new', 'play.e2e.test')));
        $this->assertSame('old.play.e2e.test', $this->store->get(7)->domain());
        $this->assertSame('The server already has this address.', $this->svc->changeAddress(7, 'old', 'play.e2e.test')->notice);
        $this->assertSame('Enter an address name.', $this->refusal(fn () => $this->svc->changeAddress(7, '', 'play.e2e.test')));
    }

    public function testAServerWhoseEggIsNotAllowedGetsNoGateway(): void
    {
        $this->panel->setGatewayAllowed(7, false);

        $this->assertSame(ModeService::MINECRAFT_ONLY, $this->refusal(fn () => $this->svc->apply(7, Mode::Both, null, true)));
        $this->assertSame(ModeService::MINECRAFT_ONLY, $this->refusal(fn () => $this->svc->changeAddress(7, 'x', 'play.e2e.test', true)));
        $this->assertSame([], $this->calls);
    }

    public function testAServerAlreadyBehindTheGatewayWhenItsEggLeavesTheListOnlyGoesBackToDirectByAnAdmin(): void
    {
        $this->startIn(Mode::Both);
        $this->panel->setGatewayAllowed(7, false);

        $this->assertSame(ModeService::NO_LONGER_ALLOWED, $this->refusal(fn () => $this->svc->apply(7, Mode::Direct)));
        $this->assertSame(ModeService::NO_LONGER_ALLOWED, $this->refusal(fn () => $this->svc->apply(7, Mode::GatewayOnly, null, true)));
        $this->assertTrue($this->svc->apply(7, Mode::Direct, null, true)->restartNeeded);
        $this->assertSame(Mode::Direct, $this->store->get(7)->mode);
    }

    /** Review 0.2.0: check-domain calls a name the account holds available, and posting it would move that route here. */
    public function testAFirstAddressNeverTakesANameThatIsAlreadyRouted(): void
    {
        $shortId = $this->panel->server(7)->shortId;
        // The name the plugin would pick (the server name) is routed already, to some other server of the account.
        $this->routesBody = json_encode(['items' => [['domain' => "{$shortId}.play.e2e.test", 'link_id' => 'link-9']]]);

        $this->svc->apply(7, Mode::Both);

        $label = $this->store->get(7)->label;
        $this->assertNotSame($shortId, $label, 'the routed name is skipped');
        $this->assertSame(1, count(array_keys($this->calls, 'GET routes', true)), 'one list read for every candidate');
        $this->assertNotContains("DELETE routes/{$shortId}.play.e2e.test", $this->calls);
        $this->assertSame("{$label}.play.e2e.test", $this->bodies[array_search('POST routes', $this->calls, true)]['subdomain'] . '.play.e2e.test');

        // A typed name that is routed is refused before anything changes.
        $this->panel->addServer(8, 1, $this->panel->addAllocation(1, '10.0.0.1', 25566));
        $this->calls = [];
        $this->assertSame(ModeService::IN_USE, $this->refusal(fn () => $this->svc->apply(8, Mode::Both, $shortId)));
        $this->assertNotContains('POST routes', $this->calls);
        $this->assertNull($this->store->get(8));
    }

    public function testAnOwnDomainThatIsAlreadyRoutedIsRefused(): void
    {
        $this->offer(new AllowedDomain('play.example.com', true));
        $this->routesBody = json_encode(['items' => [['domain' => 'play.example.com', 'link_id' => 'link-9']]]);

        $this->assertSame(ModeService::IN_USE, $this->refusal(fn () => $this->svc->apply(7, Mode::Both, null, false, 'play.example.com')));
        $this->assertSame(['GET routes'], $this->calls, 'refused before check-domain and before any route');
        $this->assertSame(ModeService::IN_USE, $this->refusal(fn () => $this->svc->changeAddress(7, null, 'play.example.com')));
    }

    /** Review 0.2.0: a remembered address only comes back while the node still offers its domain to this actor. */
    public function testSwitchingBackFromDirectChecksTheRememberedDomainAgainstTheNodeList(): void
    {
        $this->offer(new AllowedDomain('eu.example.test', false, false, 'letters'), new AllowedDomain('vip.example.test', false, false, 'letters'));
        $this->svc->apply(7, Mode::Both, 'lobby', false, 'vip.example.test');
        $this->svc->apply(7, Mode::Direct);

        // The admin makes the domain admins only: the owner cannot bring the address back, an admin can.
        $this->offer(new AllowedDomain('eu.example.test', false, false, 'letters'), new AllowedDomain('vip.example.test', false, true, 'letters'));
        $this->assertSame(ModeService::notOfferedAnyMore('lobby.vip.example.test'), $this->refusal(fn () => $this->svc->apply(7, Mode::Both)));
        $this->assertSame(Mode::Direct, $this->store->get(7)->mode);
        $this->assertTrue($this->svc->apply(7, Mode::Both, null, true)->restartNeeded);

        // A server behind the gateway keeps its address when the domain leaves the list.
        $this->offer(new AllowedDomain('eu.example.test', false, false, 'letters'));
        $this->assertTrue($this->svc->apply(7, Mode::GatewayOnly, null, true)->restartNeeded);
        $this->assertSame('lobby.vip.example.test', $this->store->get(7)->domain());

        // Gone from the list entirely: refused for everyone once the server is direct again; a new address fixes it.
        $this->svc->apply(7, Mode::Direct, null, true);
        $this->assertSame(ModeService::notOfferedAnyMore('lobby.vip.example.test'), $this->refusal(fn () => $this->svc->apply(7, Mode::Both, null, true)));
        $this->svc->changeAddress(7, 'lobby', 'eu.example.test');
        $this->svc->apply(7, Mode::Both);
        $this->assertSame('lobby.eu.example.test', $this->store->get(7)->domain());
    }
}
