<?php

namespace Dylaris\GatewayPtero;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Pterodactyl\Services\Extensions\ExtensionRepository;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettings;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;

/** The provider URL and the API key, kept in the panel's extension settings; the key as a ->secret() (encrypted with APP_KEY). */
final class GatewaySettings
{
    public const ID = 'dylaris-gateway';

    public const DEFAULT_URL = 'https://panel.dylaris.com';

    public const KEY_UNREADABLE = 'The stored API key can no longer be read. Enter it again.';

    public const BAD_URL = 'The provider URL must be an http or https URL.';

    public const URL_NEEDS_KEY = 'Enter the API key again when you change the provider URL.';

    public const HTTP_WARNING = 'This URL is not https: the API key would travel unencrypted.';

    public static function definition(ExtensionSettings $settings): ExtensionSettingsDefinition
    {
        return new ExtensionSettingsDefinition($settings, [
            ExtensionSettingDefinition::make('provider_url', 'provider_url', self::DEFAULT_URL, [
                'required', 'string', 'max:255',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (!self::validUrl(trim((string) $value))) {
                        $fail(self::BAD_URL);
                    }
                },
                self::urlNeedsKeyRule(),
            ])
                ->label('Provider URL')
                ->help('Use https: over http the API key would travel unencrypted.')
                ->normalizeUsing(fn (mixed $v) => is_string($v) ? rtrim(trim($v), '/') : $v),
            ExtensionSettingDefinition::make('api_key', 'api_key', null, ['string', 'max:255'])
                ->secret()
                ->label('API key')
                ->help('Use a key dedicated to this panel, never one a DYLARIS customer Core uses. Leave blank to keep the saved key; a new provider URL needs the key again.'),
        ]);
    }

    /**
     * The saved key only ever goes to the saved URL, so whoever changes the URL must type the key. The panel drops a
     * blank or masked secret before validating, so a missing api_key here means "keep the saved one".
     */
    private static function urlNeedsKeyRule(): ValidationRule
    {
        return new class implements DataAwareRule, ValidationRule {
            private array $data = [];

            public function setData(array $data): static
            {
                $this->data = $data;

                return $this;
            }

            public function validate(string $attribute, mixed $value, \Closure $fail): void
            {
                try {
                    $saved = GatewaySettings::apiKey() !== null;
                } catch (GatewayUnavailable) {
                    $saved = false; // unreadable: it cannot reach any URL
                }
                $typed = trim((string) ($this->data['api_key'] ?? ''));
                if ($saved && $typed === '' && rtrim(trim((string) $value), '/') !== GatewaySettings::providerUrl()) {
                    $fail(GatewaySettings::URL_NEEDS_KEY);
                }
            }
        };
    }

    public static function providerUrl(): string
    {
        $url = self::read('provider_url');

        return is_string($url) && $url !== '' ? rtrim($url, '/') : self::DEFAULT_URL;
    }

    /**
     * The key, or null if none is saved. A key encrypted under a different APP_KEY throws
     * GatewayUnavailable with KEY_UNREADABLE instead of a raw decrypt error.
     */
    public static function apiKey(): ?string
    {
        try {
            $key = self::read('api_key');
        } catch (DecryptException) {
            throw new GatewayUnavailable(self::KEY_UNREADABLE);
        }

        return is_string($key) && $key !== '' ? $key : null;
    }

    public static function validUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    /**
     * One setting, decoded on its own: ExtensionSettings::get() decodes every row at once, so an
     * undecryptable key would make the URL unreadable too. getByPrefix() strips the prefix, so the
     * exact key comes back as ''. It also reads the table each time instead of a per-process cache.
     */
    private static function read(string $key): mixed
    {
        return app(ExtensionRepository::class)->settings(self::ID)->getByPrefix($key)[''] ?? null;
    }
}
