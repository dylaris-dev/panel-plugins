<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel\Provider;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/** Client for the DYLARIS Managed Gateway provider API (/api/external/gateway/*). */
final class ProviderClient
{
    private const PREFIX = '/api/external/gateway/';

    private readonly ClientInterface $http;

    public function __construct(private readonly string $baseUrl, private readonly string $apiKey, ?ClientInterface $http = null)
    {
        $this->http = $http ?? new Client();
    }

    public function account(): Account
    {
        return Account::fromArray($this->request('GET', 'account')['account'] ?? []);
    }

    public function regions(): Regions
    {
        return Regions::fromArray($this->request('GET', 'regions'));
    }

    /** @return LinkKit[] */
    public function listLinkKits(): array
    {
        return array_map(LinkKit::fromArray(...), $this->request('GET', 'link-kits')['items'] ?? []);
    }

    public function mintLinkKit(string $name, string $region): MintedKit
    {
        $r = $this->request('POST', 'link-kits', ['name' => $name, 'region' => $region]);
        return new MintedKit((string) ($r['link_id'] ?? ''), (string) ($r['link_key'] ?? ''));
    }

    public function revokeLinkKit(string $linkId): void
    {
        $this->request('DELETE', 'link-kits/' . rawurlencode($linkId));
    }

    /** @return Route[] */
    public function listRoutes(): array
    {
        return array_map(Route::fromArray(...), $this->request('GET', 'routes')['items'] ?? []);
    }

    /**
     * Creates the route, or retargets it when the domain already exists. An empty $subdomain means $hosterDomain is an
     * own domain, the whole address (ServerState::domain()).
     */
    public function createRoute(string $linkId, string $subdomain, string $hosterDomain, string $targetHost, int $targetPort): void
    {
        $name = $subdomain === '' ? ['customDomain' => $hosterDomain] : ['subdomain' => $subdomain, 'hosterDomain' => $hosterDomain];
        $this->request('POST', 'routes', ['linkId' => $linkId] + $name + [
            'targetHost' => $targetHost,
            'targetPort' => $targetPort,
        ]);
    }

    /** @return CustomDomain[] the account's own domains, verified or not */
    public function customDomains(): array
    {
        return array_map(CustomDomain::fromArray(...), $this->request('GET', 'custom-domains')['items'] ?? []);
    }

    public function deleteRoute(string $domain): void
    {
        $this->request('DELETE', 'routes/' . rawurlencode($domain));
    }

    public function setProxyProtocol(string $domain, bool $on): void
    {
        $this->request('PATCH', 'routes/' . rawurlencode($domain) . '/proxy-protocol', ['enabled' => $on]);
    }

    public function setEditions(string $domain, bool $java, bool $bedrock): void
    {
        $this->request('PUT', 'routes/' . rawurlencode($domain) . '/bedrock', ['java' => $java, 'bedrock' => $bedrock]);
    }

    public function floodgateKey(string $domain): string
    {
        return (string) ($this->request('GET', 'routes/' . rawurlencode($domain) . '/floodgate-key')['key'] ?? '');
    }

    public function checkDomain(string $domain): DomainCheck
    {
        $r = $this->request('GET', 'check-domain', null, ['domain' => $domain]);
        return new DomainCheck((bool) ($r['available'] ?? false), (string) ($r['reason'] ?? ''));
    }

    /** @return array<string,array{java:int,bedrock:int}> keyed by route domain */
    public function players(): array
    {
        $out = [];
        foreach ($this->request('GET', 'players')['items'] ?? [] as $i) {
            $out[(string) ($i['domain'] ?? '')] = ['java' => (int) ($i['java'] ?? 0), 'bedrock' => (int) ($i['bedrock'] ?? 0)];
        }
        return $out;
    }

    /** @return array<string,mixed> decoded JSON body */
    private function request(string $method, string $path, ?array $json = null, array $query = []): array
    {
        $opts = [
            'headers' => ['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json'],
            'connect_timeout' => 10,
            'timeout' => 20,
            'http_errors' => false,
        ];
        if ($json !== null) {
            $opts['json'] = $json;
        }
        if ($query !== []) {
            $opts['query'] = $query;
        }
        // Path is built by hand: Guzzle would re-encode the already escaped segments otherwise.
        $url = rtrim($this->baseUrl, '/') . self::PREFIX . $path;

        try {
            $res = $this->http->request($method, $url, $opts);
        } catch (GuzzleException $e) {
            throw new ProviderException(0, 'provider_unreachable', 'The gateway provider could not be reached.', null, $e);
        }

        $body = json_decode((string) $res->getBody(), true);
        $body = is_array($body) ? $body : [];
        $status = $res->getStatusCode();
        if ($status >= 400) {
            $retry = $res->getHeaderLine('Retry-After');
            throw new ProviderException(
                $status,
                (string) ($body['code'] ?? 'provider_error'),
                (string) ($body['error'] ?? $body['message'] ?? "The provider answered with HTTP $status."),
                ctype_digit($retry) ? (int) $retry : null,
            );
        }
        return $body;
    }
}
