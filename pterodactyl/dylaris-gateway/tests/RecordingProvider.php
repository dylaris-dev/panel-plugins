<?php

namespace Dylaris\GatewayPteroTests;

use Dylaris\GatewayPanel\Provider\ProviderClient;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Foundation\Application;
use Psr\Http\Message\RequestInterface;

/** The provider API as a Guzzle handler that answers every call with an empty list and records it as "METHOD path". */
final class RecordingProvider
{
    /** @var list<string> */
    public array $calls = [];

    public static function bind(Application $app): self
    {
        $fake = new self();
        $app->bind(ProviderClient::class, fn () => new ProviderClient('https://provider.example', 'dgk_test_api_key', new Client(['handler' => HandlerStack::create($fake)])));

        return $fake;
    }

    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $this->calls[] = $request->getMethod() . ' ' . substr($request->getUri()->getPath(), strlen('/api/external/gateway/'));

        return Create::promiseFor(new Response(200, [], json_encode(['success' => true, 'items' => []])));
    }
}
