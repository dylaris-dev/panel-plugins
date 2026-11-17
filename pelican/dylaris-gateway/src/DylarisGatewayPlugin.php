<?php

namespace Dylaris\GatewayPelican;

use App\Contracts\Plugins\HasPluginSettings;
use App\Enums\TablerIcon;
use Dylaris\GatewayPanel\Provider\Account;
use Dylaris\GatewayPanel\Provider\ProviderClient;
use Dylaris\GatewayPanel\Provider\ProviderException;
use Filament\Actions\Action;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Utilities\Get;

class DylarisGatewayPlugin implements HasPluginSettings, Plugin
{
    private const SCOPES = ['gateway.read', 'gateway.write'];

    public const URL_NEEDS_KEY = 'Enter the API key again when you change the provider URL.';

    public const KEY_FOR_OTHER_URL = 'Enter the API key to test a different provider URL: the saved key is only sent to the saved URL.';

    public function getId(): string
    {
        return 'dylaris-gateway';
    }

    public function register(Panel $panel): void {}

    public function boot(Panel $panel): void {}

    public function getSettingsFormData(): array
    {
        // The key never goes back to the browser; a blank field keeps the saved one.
        return ['provider_url' => GatewaySettings::current()->provider_url, 'api_key' => null];
    }

    public function getSettingsForm(): array
    {
        return [
            TextInput::make('provider_url')
                ->label('Provider URL')
                ->url()
                ->required()
                // Checked here because Pelican swallows an exception from saveSettings: a late refusal would be silent.
                ->rules([fn (Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get) {
                    if (!self::validUrl(trim((string) $value))) {
                        $fail('The provider URL must be an http or https URL.');
                    } elseif (self::urlNeedsKey((string) $value, (string) $get('api_key'))) {
                        $fail(self::URL_NEEDS_KEY);
                    }
                }])
                ->live(onBlur: true)
                ->hint(fn (?string $state) => str_starts_with(strtolower(trim((string) $state)), 'http://')
                    ? 'This URL is not https: the API key would travel unencrypted.'
                    : null)
                ->hintColor('warning')
                ->default(GatewaySettings::DEFAULT_URL),
            TextInput::make('api_key')
                ->label('API key')
                ->password()
                ->autocomplete('new-password')
                ->helperText(fn () => self::keyHelp())
                ->hintAction(
                    Action::make('test_connection')
                        ->label('Test connection')
                        ->icon(TablerIcon::PlugConnected)
                        ->authorize(fn () => user()?->can('update plugin'))
                        ->action(function (Get $get) {
                            try {
                                [$url, $key] = self::testCredentials((string) $get('provider_url'), (string) $get('api_key'));
                            } catch (GatewayUnavailable $e) {
                                Notification::make()->title($e->getMessage())->danger()->send();

                                return;
                            }
                            $problem = self::connectionProblem(new ProviderClient($url, $key));
                            $problem === null
                                ? Notification::make()->title('Connected: managed gateway plan with read and write access.')->success()->send()
                                : Notification::make()->title('Connection test failed')->body($problem)->danger()->send();
                        }),
                ),
        ];
    }

    public function saveSettings(array $data): void
    {
        $url = rtrim(trim((string) ($data['provider_url'] ?? '')), '/');
        if (!self::validUrl($url)) {
            throw new \InvalidArgumentException('The provider URL must be an http or https URL.');
        }
        $s = GatewaySettings::current();
        $key = trim((string) ($data['api_key'] ?? ''));
        if (self::urlNeedsKey($url, $key)) {
            throw new \InvalidArgumentException(self::URL_NEEDS_KEY);
        }
        if ($key !== '') {
            // Eloquent decrypts the old value to compare it on save; an unreadable one must not block its replacement.
            $s->setRawAttributes(['api_key' => null] + $s->getAttributes(), true);
            $s->api_key = $key;
        }
        $s->provider_url = $url;
        $s->save();
    }

    /** The saved key only ever goes to the saved URL, so whoever changes the URL must know the key. */
    private static function urlNeedsKey(string $url, string $typedKey): bool
    {
        $saved = GatewaySettings::current();

        return trim($typedKey) === '' && $saved->hasKey() && rtrim(trim($url), '/') !== rtrim((string) $saved->provider_url, '/');
    }

    private static function keyHelp(): string
    {
        $s = GatewaySettings::current();
        if (!$s->hasKey()) {
            return 'Use a key dedicated to this panel, never one a DYLARIS customer Core uses.';
        }
        try {
            $s->apiKey();
        } catch (GatewayUnavailable $e) {
            return $e->getMessage();
        }

        return 'A key is saved. Leave blank to keep it; a new provider URL needs the key again. Use a key dedicated to this panel.';
    }

    /**
     * The URL and key a connection test uses: the typed ones, a blank URL meaning the saved one. A blank key means the
     * saved one, but only for the saved URL, so the saved key never goes to a URL typed into the form.
     *
     * @return array{string, string}
     *
     * @throws GatewayUnavailable with the sentence for the admin
     */
    public static function testCredentials(string $typedUrl, string $typedKey): array
    {
        $saved = GatewaySettings::current();
        $url = rtrim(trim($typedUrl), '/') ?: (string) $saved->provider_url;
        $key = trim($typedKey);
        if ($key === '') {
            if ($url !== rtrim((string) $saved->provider_url, '/')) {
                throw new GatewayUnavailable(self::KEY_FOR_OTHER_URL);
            }
            $key = (string) $saved->apiKey();
        }
        if ($key === '' || !self::validUrl($url)) {
            throw new GatewayUnavailable('Enter a provider URL and an API key first.');
        }

        return [$url, $key];
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

    private static function validUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
