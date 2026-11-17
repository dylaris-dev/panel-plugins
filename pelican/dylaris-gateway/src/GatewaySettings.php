<?php

namespace Dylaris\GatewayPelican;

use App\Models\Egg;
use Dylaris\GatewayPanel\MinecraftEggs;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $provider_url the URL the key was saved for; kept for older rows, the plugin calls providerUrl()
 * @property string|null $api_key decrypted on read, encrypted with the app key on write; read it through apiKey()
 * @property list<int>|null $eggs the egg ids that may use the gateway; null = the Java Minecraft eggs
 */
final class GatewaySettings extends Model
{
    public const DEFAULT_URL = 'https://panel.dylaris.com';

    /** Overrides DEFAULT_URL, for DYLARIS' own tests against another provider. The key is sent to whichever URL is in effect. */
    public const URL_ENV = 'DYLARIS_GATEWAY_PROVIDER_URL';

    public const KEY_UNREADABLE = 'The stored API key can no longer be read. Enter it again.';

    protected $table = 'dylaris_gateway_settings';

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted', 'eggs' => 'array'];
    }

    /** The provider every call goes to: fixed, not a setting, so a saved key cannot be sent anywhere else. */
    public static function providerUrl(): string
    {
        $url = env(self::URL_ENV);

        return is_string($url) && trim($url) !== '' ? rtrim(trim($url), '/') : self::DEFAULT_URL;
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
