<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel\Tests;

use Dylaris\GatewayPanel\Provider\ProviderClient;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/** A stateful provider behind a real ProviderClient: routes created, retargeted and deleted stick. */
final class FakeProvider
{
    /** @var list<string> calls as "METHOD path" (path after /api/external/gateway/) */
    public array $calls = [];
    /** @var list<mixed> decoded JSON bodies, index-aligned with $calls */
    public array $bodies = [];
    /** @var array<string, array<string,mixed>> route rows in wire format, keyed by domain */
    public array $routes = [];
    /** @var list<array<string,mixed>> link kit rows in wire format */
    public array $kits = [['link_id' => 'link-1', 'online' => true]];
    /** @var list<array<string,mixed>> player rows in wire format */
    public array $players = [];
    /** @var array<string, Response> one-shot answers for a call, instead of the normal one */
    public array $failOn = [];
    /** @var array<string, array<string,mixed>> own domain claims in wire format, keyed by domain */
    public array $customDomains = [];
    /** @var array<string,mixed>|null GET regions answer; null = the fixture */
    public ?array $regions = null;

    public function client(): ProviderClient
    {
        $handler = function (RequestInterface $r) {
            $path = substr($r->getUri()->getPath(), strlen('/api/external/gateway/'));
            $call = $r->getMethod() . ' ' . $path;
            $body = json_decode((string) $r->getBody(), true);
            $this->calls[] = $call;
            $this->bodies[] = $body;
            if (isset($this->failOn[$call])) {
                $res = $this->failOn[$call];
                unset($this->failOn[$call]);
                return Create::promiseFor($res);
            }
            return Create::promiseFor(new Response(200, [], json_encode($this->answer($r->getMethod(), $path, $body))));
        };
        return new ProviderClient('https://api.example.test', 'key', new Client(['handler' => HandlerStack::create($handler)]));
    }

    public static function rateLimited(?int $retryAfter): Response
    {
        $headers = $retryAfter !== null ? ['Retry-After' => (string) $retryAfter] : [];
        return new Response(429, $headers, file_get_contents(__DIR__ . '/fixtures/provider/error-rate-limited.json'));
    }

    private function answer(string $method, string $path, mixed $body): array
    {
        if ($method === 'POST' && $path === 'routes') {
            $domain = $body['customDomain'] ?? $body['subdomain'] . '.' . $body['hosterDomain'];
            $this->routes[$domain] = [
                'domain' => $domain,
                'link_id' => $body['linkId'],
                'target_ip' => $body['targetHost'],
                'target_port' => $body['targetPort'],
                'proxy_protocol' => $this->routes[$domain]['proxy_protocol'] ?? false,
            ];
            return ['success' => true];
        }
        if ($method === 'DELETE' && str_starts_with($path, 'routes/')) {
            unset($this->routes[rawurldecode(substr($path, strlen('routes/')))]);
            return ['success' => true];
        }
        if (str_starts_with($path, 'custom-domains')) {
            return $this->customDomainAnswer($method, $path, $body);
        }
        return match ("{$method} {$path}") {
            'GET routes' => ['items' => array_values($this->routes), 'success' => true],
            'GET link-kits' => ['items' => $this->kits, 'success' => true],
            'GET players' => ['items' => $this->players, 'success' => true],
            'GET regions' => $this->regions ?? json_decode(file_get_contents(__DIR__ . '/fixtures/provider/regions.json'), true),
            'GET check-domain' => ['available' => true, 'reason' => '', 'success' => true],
            default => ['success' => true],
        };
    }

    private function customDomainAnswer(string $method, string $path, mixed $body): array
    {
        if ($method === 'GET') {
            return ['items' => array_values($this->customDomains), 'success' => true];
        }
        if ($method === 'POST' && $path === 'custom-domains') {
            $d = $body['domain'];
            $this->customDomains[$d] ??= ['domain' => $d, 'state' => 'pending', 'txtName' => "_dylaris-verify.{$d}", 'txtValue' => "token-{$d}"];
            return ['domain' => $this->customDomains[$d], 'success' => true];
        }
        $parts = explode('/', $path);
        $d = rawurldecode($parts[1]);
        if ($method === 'DELETE') {
            unset($this->customDomains[$d]);
            return ['success' => true];
        }
        if (($parts[2] ?? '') === 'verify-txt') {
            $this->customDomains[$d]['state'] = 'verified';
            return ['success' => true, 'message' => "Ownership verified. You can add routes on {$d}."];
        }
        $this->customDomains[$d] += ['txtName' => "_dylaris-verify.{$d}", 'txtValue' => "token-{$d}"];
        return ['domain' => $this->customDomains[$d], 'success' => true];
    }
}
