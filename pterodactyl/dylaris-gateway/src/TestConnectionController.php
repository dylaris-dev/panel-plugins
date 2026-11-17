<?php

namespace Dylaris\GatewayPtero;

use Dylaris\GatewayPanel\Provider\Account;
use Dylaris\GatewayPanel\Provider\ProviderClient;
use Dylaris\GatewayPanel\Provider\ProviderException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;

/**
 * POST /api/admin/extensions/dylaris-gateway/test-connection (root admins, admin API throttle). Checks the typed key, or
 * the saved one when blank, against the fixed provider URL, with the same sentences as the Pelican plugin.
 */
final class TestConnectionController
{
    private const SCOPES = ['gateway.read', 'gateway.write'];

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['api_key' => ['nullable', 'string', 'max:255']]);
        $key = trim((string) ($data['api_key'] ?? ''));
        // The admin form shows a saved secret as its mask; sent back, it means "the saved key".
        if ($key === '' || $key === ExtensionSettingDefinition::MASK) {
            try {
                $key = (string) GatewaySettings::apiKey();
            } catch (GatewayUnavailable $e) {
                return self::result($e->getMessage());
            }
        }
        if ($key === '') {
            return self::result('Enter an API key first.');
        }
        $problem = self::connectionProblem(new ProviderClient(GatewaySettings::providerUrl(), $key));

        return self::result($problem, $problem === null);
    }

    /** What is wrong with this key for the managed gateway, as a sentence for the admin, or null. */
    public static function connectionProblem(ProviderClient $client): ?string
    {
        try {
            return self::accountProblem($client->account());
        } catch (ProviderException $e) {
            return $e->userMessage;
        }
    }

    public static function accountProblem(Account $a): ?string
    {
        if ($a->product !== 'managed' || !$a->managed) {
            return 'This key has no managed gateway plan.';
        }
        if ($a->suspended) {
            return 'This account is suspended.';
        }
        $missing = array_values(array_diff(self::SCOPES, $a->scopes));

        return match (count($missing)) {
            0 => null,
            1 => "This key lacks the {$missing[0]} permission.",
            default => 'This key lacks the ' . implode(' and ', $missing) . ' permissions.',
        };
    }

    private static function result(?string $problem, bool $ok = false): JsonResponse
    {
        return new JsonResponse(['data' => [
            'ok' => $ok,
            'message' => $problem ?? 'Connected: managed gateway plan with read and write access.',
            'provider_url' => GatewaySettings::providerUrl(),
        ]]);
    }
}
