<?php

namespace Dylaris\GatewayPelican;

use App\Models\Egg;
use Dylaris\GatewayPanel\MinecraftEggs;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * @property string $provider_url the URL the key was saved for; kept for older rows, the plugin calls providerUrl()
 * @property string|null $api_key decrypted on read, encrypted with the app key on write; read it through apiKey()
 * @property list<int>|null $eggs the egg ids that may use the gateway; null = the Java Minecraft eggs
 */
final class GatewaySettings extends Model
{
    public const DEFAULT_URL = 'https://panel.dylaris.com';

    public const BAD_OVERRIDE = 'DYLARIS_GATEWAY_PROVIDER_URL must be an https:// URL. The gateway makes no calls until it is fixed or removed.';

    public const KEY_UNREADABLE = 'The stored API key can no longer be read. Enter it again.';

    protected $table = 'dylaris_gateway_settings';

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted', 'eggs' => 'array'];
    }

    /**
     * The provider every call goes to: fixed, not a setting, so a saved key cannot be sent anywhere else. Only the
     * dylaris-gateway.provider_url config entry (DYLARIS_GATEWAY_PROVIDER_URL) overrides it, and only with https://;
     * anything else stops every call with BAD_OVERRIDE instead of quietly falling back to production.
     *
     * @throws GatewayUnavailable with BAD_OVERRIDE
     */
    public static function providerUrl(): string
    {
        $url = config('dylaris-gateway.provider_url');
        if (!is_string($url) || trim($url) === '') {
            return self::DEFAULT_URL;
        }
        $url = rtrim(trim($url), '/');
        if (!str_starts_with(strtolower($url), 'https://') || filter_var($url, FILTER_VALIDATE_URL) === false) {
            Log::error(self::BAD_OVERRIDE);
            throw new GatewayUnavailable(self::BAD_OVERRIDE);
        }

        return $url;
    }

    /** The sentence for help texts: where calls go, or why none go anywhere. */
    public static function providerSentence(): string
    {
        try {
            return 'Calls go only to ' . self::providerUrl() . '.';
        } catch (GatewayUnavailable $e) {
            return $e->getMessage();
        }
    }

    /** May servers of this egg use the gateway: the admin's list once saved, the Java Minecraft eggs until then. */
    public static function eggAllowed(?Egg $egg): bool
    {
        if ($egg === null) {
            return false;
        }
        $saved = self::current()->eggs;

        return $saved === null ? self::isMinecraft($egg) : in_array($egg->id, array_map('intval', $saved), true);
    }

    public static function isMinecraft(Egg $egg): bool
    {
        return MinecraftEggs::isJavaMinecraft((string) $egg->name, array_values((array) ($egg->tags ?? [])), array_values((array) ($egg->features ?? [])));
    }

    public static function current(): self
    {
        return self::query()->find(1) ?? (new self())->forceFill(['id' => 1, 'provider_url' => self::DEFAULT_URL]);
    }

    public function hasKey(): bool
    {
        return ($this->getAttributes()['api_key'] ?? '') !== '';
    }

    /**
     * The key, or null if none is saved. A key encrypted under a different APP_KEY throws
     * GatewayUnavailable with KEY_UNREADABLE instead of a raw decrypt error.
     */
    public function apiKey(): ?string
    {
        if (!$this->hasKey()) {
            return null;
        }
        try {
            return $this->api_key;
        } catch (DecryptException) {
            throw new GatewayUnavailable(self::KEY_UNREADABLE);
        }
    }
}
