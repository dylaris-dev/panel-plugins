<?php

namespace Dylaris\GatewayPelican\Tests;

use Dylaris\GatewayPanel\Provider\ProviderClient;
use Dylaris\GatewayPelican\DylarisGatewayPlugin;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Foundation\Application;
use Psr\Http\Message\RequestInterface;

/** The provider API as a Guzzle handler: canned answers per "METHOD path", every call recorded. */
final class FakeProvider
{
    public const URL = 'https://provider.example';

    /** @var list<array{key: string, body: ?array}> */
    public array $calls = [];

    /** @var array<string, array{int, array, array}> */
    private array $answers = [];

    public static function bind(Application $app): self
    {
        $fake = new self();
        $fake->on('GET regions', 200, [
            'hosterDomains' => [['domain' => 'play.example.net', 'validation' => 'alphanumeric']],
            'items' => [['region' => 'eu', 'wildcard' => '*.play.example.net', 'online' => true], ['region' => 'us', 'wildcard' => '*.us.example.net', 'online' => true]],
        ]);
        $fake->on('GET link-kits', 200, ['items' => []]);
        $fake->on('GET routes', 200, ['items' => []]);
        $fake->on('GET players', 200, ['items' => []]);
        $fake->on('GET check-domain', 200, ['available' => true, 'reason' => '']);
        $fake->on('POST routes', 200, ['success' => true]);
        (new DylarisGatewayPlugin())->saveSettings(['provider_url' => self::URL, 'api_key' => 'dgk_test_api_key']);
        $app->bind(ProviderClient::class, fn () => new ProviderClient(self::URL, 'dgk_test_api_key', new Client(['handler' => HandlerStack::create($fake)])));

        return $fake;
    }

    public function on(string $key, int $status, array $body, array $headers = []): self
    {
        $this->answers[$key] = [$status, $body, $headers];

        return $this;
    }

    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $path = substr($request->getUri()->getPath(), strlen('/api/external/gateway/'));
        $key = $request->getMethod() . ' ' . rawurldecode($path);
        $body = json_decode((string) $request->getBody(), true);
        $this->calls[] = ['key' => $key, 'body' => is_array($body) ? $body : null];
        [$status, $answer, $headers] = $this->answers[$key] ?? [404, ['error' => "not faked: $key", 'code' => 'not_found'], []];

        return Create::promiseFor(new Response($status, $headers, json_encode($answer)));
    }

    /** @return list<?array> the request bodies of every call to $key */
    public function bodies(string $key): array
    {
        return array_values(array_map(fn ($c) => $c['body'], array_filter($this->calls, fn ($c) => $c['key'] === $key)));
    }
}
