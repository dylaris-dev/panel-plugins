<?php

namespace Dylaris\GatewayPelican;

use App\Contracts\Plugins\HasPluginSettings;
use App\Enums\TablerIcon;
use App\Models\Egg;
use Dylaris\GatewayPanel\Provider\Account;
use Dylaris\GatewayPanel\Provider\ProviderClient;
use Dylaris\GatewayPanel\Provider\ProviderException;
use Dylaris\GatewayPelican\Filament\Server\Pages\GatewayPage;
use Filament\Actions\Action;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Utilities\Get;

class DylarisGatewayPlugin implements HasPluginSettings, Plugin
{
    private const SCOPES = ['gateway.read', 'gateway.write'];

    public function getId(): string
    {
        return 'dylaris-gateway';
    }

    public function register(Panel $panel): void
    {
        // A page of its own in the server's navigation; the panel loads a plugin into every panel it has.
        if ($panel->getId() === 'server') {
            $panel->pages([GatewayPage::class]);
        }
    }

    public function boot(Panel $panel): void {}

    public function getSettingsFormData(): array
    {
        // The key never goes back to the browser; a blank field keeps the saved one.
        return ['api_key' => null, 'eggs' => self::allowedEggIds()];
    }

    public function getSettingsForm(): array
    {
        return [
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
                                $key = self::testKey((string) $get('api_key'));
                            } catch (GatewayUnavailable $e) {
                                Notification::make()->title($e->getMessage())->danger()->send();

                                return;
                            }
                            $problem = self::connectionProblem(new ProviderClient(GatewaySettings::providerUrl(), $key));
                            $problem === null
                                ? Notification::make()->title('Connected: managed gateway plan with read and write access.')->success()->send()
                                : Notification::make()->title('Connection test failed')->body($problem)->danger()->send();
                        }),
                ),
            CheckboxList::make('eggs')
                ->label('Eggs that may use the gateway')
                ->options(fn () => Egg::query()->orderBy('name')->pluck('name', 'id')->all())
                ->columns(2)
                ->bulkToggleable()
                ->helperText('The gateway carries Minecraft Java only (Bedrock players join through Geyser in front of a Java server). Servers of any other egg get no gateway. Until this is saved: the eggs with the minecraft tag or the eula or java_version feature, without Bedrock. Once saved, exactly the ticked eggs; tick an egg imported later here.'),
        ];
    }

    public function saveSettings(array $data): void
    {
        $s = GatewaySettings::current();
        $key = trim((string) ($data['api_key'] ?? ''));
        if ($key !== '') {
            // Eloquent decrypts the old value to compare it on save; an unreadable one must not block its replacement.
            $s->setRawAttributes(['api_key' => null] + $s->getAttributes(), true);
            $s->api_key = $key;
        }
        if (array_key_exists('eggs', $data)) {
            $chosen = array_values(array_unique(array_map('intval', (array) $data['eggs'])));
            sort($chosen);
            $s->eggs = $chosen;
        }
        $s->provider_url = GatewaySettings::providerUrl();
        $s->save();
    }

    /** @return list<int> what the admin allowed, or the detected Minecraft eggs while nothing is saved */
    public static function allowedEggIds(): array
    {
        $saved = GatewaySettings::current()->eggs;

        return $saved === null ? self::detectedEggIds() : array_map('intval', $saved);
    }

    /** @return list<int> sorted */
    public static function detectedEggIds(): array
    {
        $ids = Egg::query()->get()->filter(fn (Egg $e) => GatewaySettings::isMinecraft($e))->map(fn (Egg $e) => (int) $e->id)->values()->all();
        sort($ids);

        return $ids;
    }

    private static function keyHelp(): string
    {
        $where = 'Calls go only to ' . GatewaySettings::providerUrl() . '.';
        $s = GatewaySettings::current();
        if (!$s->hasKey()) {
            return "Use a key dedicated to this panel, never one a DYLARIS customer Core uses. {$where}";
        }
        try {
            $s->apiKey();
        } catch (GatewayUnavailable $e) {
            return $e->getMessage();
        }

        return "A key is saved. Leave blank to keep it. Use a key dedicated to this panel. {$where}";
    }

    /**
     * The key a connection test uses: the typed one, or the saved one when the field is blank.
     *
     * @throws GatewayUnavailable with the sentence for the admin
     */
    public static function testKey(string $typedKey): string
    {
        $key = trim($typedKey) !== '' ? trim($typedKey) : (string) GatewaySettings::current()->apiKey();
        if ($key === '') {
            throw new GatewayUnavailable('Enter an API key first.');
        }

        return $key;
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
}
