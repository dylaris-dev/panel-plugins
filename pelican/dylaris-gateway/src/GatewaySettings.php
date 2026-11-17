<?php

namespace Dylaris\GatewayPelican;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $provider_url
 * @property string|null $api_key decrypted on read, encrypted with the app key on write; read it through apiKey()
 */
final class GatewaySettings extends Model
{
    public const DEFAULT_URL = 'https://panel.dylaris.com';

    public const KEY_UNREADABLE = 'The stored API key can no longer be read. Enter it again.';

    protected $table = 'dylaris_gateway_settings';

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted'];
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
