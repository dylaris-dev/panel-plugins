<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel\Tests\Provider;

use Dylaris\GatewayPanel\Provider\ProviderClient;
use Dylaris\GatewayPanel\Provider\ProviderException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class ProviderClientTest extends TestCase
{
    private array $history = [];

    private function client(Response|\Throwable ...$queue): ProviderClient
    {
        $stack = HandlerStack::create(new MockHandler(array_values($queue)));
        $stack->push(Middleware::history($this->history));
        return new ProviderClient('https://api.example.test', 'key-123', new Client(['handler' => $stack]));
    }

    private function fixture(string $name, int $status = 200, array $headers = []): Response
    {
        $body = file_get_contents(__DIR__ . "/../fixtures/provider/$name.json");
        return new Response($status, $headers + ['Content-Type' => 'application/json'], $body);
    }

    private function ok(): Response
    {
        return new Response(200, [], '{"success":true}');
    }

    public function testAccountParsesManagedPlan(): void
    {
        $a = $this->client($this->fixture('account'))->account();
        $this->assertSame('managed', $a->product);
        $this->assertTrue($a->managed);
        $this->assertFalse($a->suspended);
        $this->assertSame(['gateway.read', 'gateway.write'], $a->scopes);
        $this->assertSame(2, $a->maxLinks);
        $this->assertSame(50, $a->maxRoutes);
        $this->assertSame('/api/external/gateway/account', $this->history[0]['request']->getUri()->getPath());
    }

    public function testRegionsParsesHosterDomainsAndItems(): void
    {
        $r = $this->client($this->fixture('regions'))->regions();
        $this->assertSame('play.e2e.test', $r->hosterDomains[0]->domain);
        $this->assertSame('alphanumeric', $r->hosterDomains[0]->validation);
        $this->assertSame('e2e', $r->items[0]->region);
        $this->assertSame('*.play.e2e.test', $r->items[0]->wildcard);
        $this->assertTrue($r->items[0]->online);
    }

    public function testListLinkKitsReadsItemsAndOnline(): void
    {
        $kits = $this->client($this->fixture('link-kits'))->listLinkKits();
        $this->assertCount(1, $kits);
        $this->assertSame('link-0000000000000000000000000000000a', $kits[0]->linkId);
        $this->assertSame(['client-addr', 'voice'], $kits[0]->caps);
        $this->assertTrue($kits[0]->online);
    }

    public function testMintLinkKitSendsNameAndRegion(): void
    {
        $m = $this->client($this->fixture('link-kits-mint'))->mintLinkKit('n1', 'e2e');
        $this->assertSame('link-0000000000000000000000000000000b', $m->linkId);
        $this->assertNotSame('', $m->linkKey);
        $req = $this->history[0]['request'];
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame(['name' => 'n1', 'region' => 'e2e'], json_decode((string) $req->getBody(), true));
    }

    public function testListRoutesParsesRoutes(): void
    {
        $routes = $this->client($this->fixture('routes'))->listRoutes();
        $this->assertSame('survival.play.e2e.test', $routes[0]->domain);
        $this->assertSame('mc-00000000-0000-4000-8000-000000000003', $routes[0]->targetIp);
        $this->assertSame(25565, $routes[0]->targetPort);
        $this->assertTrue($routes[0]->proxyProtocol);
        $this->assertTrue($routes[0]->bedrock['java']);
        $this->assertTrue($routes[6]->bedrock['bedrock']);
    }

    public function testCreateRouteSendsTargetHost(): void
    {
        $this->client($this->ok())
            ->createRoute('link-1', 'survival', 'play.e2e.test', '172.18.0.1', 25570);
        $req = $this->history[0]['request'];
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/api/external/gateway/routes', $req->getUri()->getPath());
        $this->assertSame('Bearer key-123', $req->getHeaderLine('Authorization'));
        $this->assertSame(
            '{"linkId":"link-1","subdomain":"survival","hosterDomain":"play.e2e.test","targetHost":"172.18.0.1","targetPort":25570}',
            (string) $req->getBody(),
        );
    }

    public function testDeleteRouteEscapesDomain(): void
    {
        $this->client($this->ok())->deleteRoute('a/b c.test');
        $req = $this->history[0]['request'];
        $this->assertSame('DELETE', $req->getMethod());
        $this->assertSame('/api/external/gateway/routes/a%2Fb%20c.test', $req->getUri()->getPath());
    }

    public function testRevokeLinkKitSendsDelete(): void
    {
        $this->client($this->ok())->revokeLinkKit('link-1');
        $this->assertSame('DELETE', $this->history[0]['request']->getMethod());
        $this->assertSame('/api/external/gateway/link-kits/link-1', $this->history[0]['request']->getUri()->getPath());
    }

    public function testRouteSettingsRequests(): void
    {
        $c = $this->client($this->ok(), $this->ok(), new Response(200, [], '{"success":true,"key":"fk"}'));
        $c->setProxyProtocol('x.test', true);
        $c->setEditions('x.test', true, false);
        $this->assertSame('fk', $c->floodgateKey('x.test'));
        [$pp, $ed, $fk] = array_column($this->history, 'request');
        $this->assertSame('PATCH', $pp->getMethod());
        $this->assertSame('/api/external/gateway/routes/x.test/proxy-protocol', $pp->getUri()->getPath());
        $this->assertSame('{"enabled":true}', (string) $pp->getBody());
        $this->assertSame('PUT', $ed->getMethod());
        $this->assertSame('{"java":true,"bedrock":false}', (string) $ed->getBody());
        $this->assertSame('/api/external/gateway/routes/x.test/floodgate-key', $fk->getUri()->getPath());
    }

    public function testCheckDomainAndPlayers(): void
    {
        $c = $this->client(
            $this->fixture('check-domain'),
            new Response(200, [], '{"success":true,"items":[{"domain":"a.test","java":3,"bedrock":1}]}'),
        );
        $d = $c->checkDomain('fixture.play.e2e.test');
        $this->assertTrue($d->available);
        $this->assertSame('', $d->reason);
        $this->assertSame('domain=fixture.play.e2e.test', $this->history[0]['request']->getUri()->getQuery());
        $this->assertSame(['a.test' => ['java' => 3, 'bedrock' => 1]], $c->players());
    }

    public function testErrorBodyBecomesProviderException(): void
    {
        $c = $this->client($this->fixture('error-kit-retiring', 409));
        try {
            $c->deleteRoute('x.test');
            $this->fail('expected exception');
        } catch (ProviderException $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame('kit_retiring', $e->errorCode);
            $this->assertStringContainsString('being replaced', $e->userMessage);
            $this->assertNull($e->retryAfter);
        }
    }

    public function testErrorBodyMessageKeyIsAccepted(): void
    {
        $c = $this->client(new Response(400, [], '{"success":false,"code":"bad","message":"nope"}'));
        try {
            $c->account();
            $this->fail('expected exception');
        } catch (ProviderException $e) {
            $this->assertSame('nope', $e->userMessage);
        }
    }

    public function testNonJsonErrorStillThrows(): void
    {
        $c = $this->client(new Response(502, [], '<html>bad gateway</html>'));
        try {
            $c->account();
            $this->fail('expected exception');
        } catch (ProviderException $e) {
            $this->assertSame(502, $e->status);
            $this->assertSame('provider_error', $e->errorCode);
        }
    }

    public function testRateLimitCarriesRetryAfter(): void
    {
        $c = $this->client($this->fixture('error-rate-limited', 429, ['Retry-After' => '60']));
        try {
            $c->account();
            $this->fail('expected exception');
        } catch (ProviderException $e) {
            $this->assertSame(429, $e->status);
            $this->assertSame('rate_limited', $e->errorCode);
            $this->assertSame(60, $e->retryAfter);
        }
    }

    public function testConnectErrorIsProviderUnreachable(): void
    {
        $c = $this->client(new ConnectException('refused', new Request('GET', 'https://api.example.test')));
        try {
            $c->account();
            $this->fail('expected exception');
        } catch (ProviderException $e) {
            $this->assertSame(0, $e->status);
            $this->assertSame('provider_unreachable', $e->errorCode);
        }
    }

    public function testUnknownKeysAreIgnored(): void
    {
        $body = '{"success":true,"future":1,"account":{"product":"managed","managed":true,"suspended":false,"scopes":[],"limits":{"maxLinks":null,"maxRoutes":null,"x":1},"new":"y"}}';
        $a = $this->client(new Response(200, [], $body))->account();
        $this->assertNull($a->maxLinks);
        $this->assertNull($a->maxRoutes);
    }

    public function testCreateRouteOnAnOwnDomainSendsCustomDomain(): void
    {
        $this->client($this->ok())->createRoute('link-1', '', 'play.example.com', '172.18.0.1', 25570);

        $this->assertSame(
            '{"linkId":"link-1","customDomain":"play.example.com","targetHost":"172.18.0.1","targetPort":25570}',
            (string) $this->history[0]['request']->getBody(),
        );
    }

    /** Shape as documented for GET /custom-domains (Core renames its "domains" list to items). */
    public function testCustomDomainsParsesTheStates(): void
    {
        $body = '{"success":true,"items":[{"domain":"Play.Example.com","state":"verified","attempts":0},{"domain":"new.example.com","state":"pending","txtName":"_dylaris-verify.new.example.com","txtValue":"t"}]}';
        $domains = $this->client(new Response(200, [], $body))->customDomains();

        $this->assertSame('/api/external/gateway/custom-domains', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame(['play.example.com', 'new.example.com'], array_map(fn ($d) => $d->domain, $domains));
        $this->assertSame([true, false], array_map(fn ($d) => $d->verified(), $domains));
    }
}
