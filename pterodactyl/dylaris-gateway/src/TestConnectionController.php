<?php

namespace Dylaris\GatewayPtero;

use Dylaris\GatewayPanel\Provider\Account;
use Dylaris\GatewayPanel\Provider\ProviderClient;
use Dylaris\GatewayPanel\Provider\ProviderException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;

/**
 * POST /api/admin/extensions/dylaris-gateway/test-connection (root admins, admin API throttle). Checks the typed
 * provider URL and key with the same rule and sentences as the Pelican plugin. A blank URL means the saved one; a blank
 * key means the saved one, but only for the saved URL.
 */
final class TestConnectionController
{
    private const SCOPES = ['gateway.read', 'gateway.write'];

    public const KEY_FOR_OTHER_URL = 'Enter the API key to test a different provider URL: the saved key is only sent to the saved URL.';

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider_url' => ['nullable', 'string', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:255'],
        ]);
        $url = rtrim(trim((string) ($data['provider_url'] ?? '')), '/') ?: GatewaySettings::providerUrl();
        $warning = str_starts_with(strtolower($url), 'http://') ? GatewaySettings::HTTP_WARNING : null;
        $key = trim((string) ($data['api_key'] ?? ''));
        // The admin form shows a saved secret as its mask; sent back, it means "the saved key".
        if ($key === '' || $key === ExtensionSettingDefinition::MASK) {
            // The saved key goes only to the saved URL, never to one typed into the form.
            if ($url !== GatewaySettings::providerUrl()) {
                return self::result(self::KEY_FOR_OTHER_URL, $warning);
            }
            try {
                $key = (string) GatewaySettings::apiKey();
            } catch (GatewayUnavailable $e) {
                return self::result($e->getMessage(), $warning);
            }
        }
        if ($key === '' || !GatewaySettings::validUrl($url)) {
            return self::result('Enter a provider URL and an API key first.', $warning);
        }
        $problem = self::connectionProblem(new ProviderClient($url, $key));

        return self::result($problem, $warning, $problem === null);
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

    private static function result(?string $problem, ?string $warning, bool $ok = false): JsonResponse
    {
        return new JsonResponse(['data' => [
            'ok' => $ok,
            'message' => $problem ?? 'Connected: managed gateway plan with read and write access.',
            'warning' => $warning,
        ]]);
    }
}
