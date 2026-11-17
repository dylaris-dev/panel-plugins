<?php

namespace Dylaris\GatewayPtero;

use Dylaris\GatewayPanel\MinecraftEggs;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Models\Egg;
use Pterodactyl\Services\Extensions\ExtensionRepository;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettings;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;

/**
 * The API key (a ->secret(), encrypted with APP_KEY) and the eggs that may use the gateway, kept in the panel's
 * extension settings. The provider URL is not a setting: every call goes to providerUrl().
 */
final class GatewaySettings
{
    public const ID = 'dylaris-gateway';

    public const DEFAULT_URL = 'https://panel.dylaris.com';

    public const BAD_OVERRIDE = 'DYLARIS_GATEWAY_PROVIDER_URL must be an https:// URL. The gateway makes no calls until it is fixed or removed.';

    public const KEY_UNREADABLE = 'The stored API key can no longer be read. Enter it again.';

    public const EGGS_HELP = 'The gateway carries Minecraft Java only (Bedrock players join through Geyser in front of a Java server). Servers of any other egg get no gateway. Until this is saved: the eggs with the minecraft tag or the eula or java_version feature, without Bedrock. Once saved, exactly the ticked eggs; tick an egg imported later here.';

    public static function definition(ExtensionSettings $settings): ExtensionSettingsDefinition
    {
        $fields = [
            ExtensionSettingDefinition::make('api_key', 'api_key', null, ['string', 'max:255'])
                ->secret()
                ->label('API key')
                ->help('Use a key dedicated to this panel, never one a DYLARIS customer Core uses. Leave blank to keep the saved key. ' . self::providerSentence()),
        ];
        // One query per boot; a fresh install migrates before the eggs table is filled, and a multiselect needs a choice.
        $eggs = self::eggs();
        $options = $eggs->map(fn (Egg $e) => ['value' => (int) $e->id, 'label' => (string) $e->name])->values()->all();
        $detected = self::detectedEggIds($eggs);
        if ($options !== []) {
            $fields[] = ExtensionSettingDefinition::make('eggs', 'eggs', $detected, ['nullable', 'array'])
                ->multiselect($options)
                ->label('Eggs that may use the gateway')
                ->help(self::EGGS_HELP);
        }

        return new ExtensionSettingsDefinition($settings, $fields);
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

    /** May servers of this egg use the gateway: the admin's list once saved, the Java Minecraft eggs until then. */
    public static function eggAllowed(?Egg $egg): bool
    {
        if ($egg === null) {
            return false;
        }
        $saved = self::read('eggs');

        return is_array($saved) ? in_array((int) $egg->id, array_map('intval', $saved), true) : self::isMinecraft($egg);
    }

    public static function isMinecraft(Egg $egg): bool
    {
        return MinecraftEggs::isJavaMinecraft((string) $egg->name, $egg->tagSlugs(), array_values((array) ($egg->inherit_features ?? $egg->features ?? [])));
    }

    /**
     * @param Collection<int, Egg>|null $eggs
     *
     * @return list<int> sorted
     */
    public static function detectedEggIds(?Collection $eggs = null): array
    {
        $ids = ($eggs ?? self::eggs())->filter(fn (Egg $e) => self::isMinecraft($e))->map(fn (Egg $e) => (int) $e->id)->values()->all();
        sort($ids);

        return $ids;
    }

    /** @return Collection<int, Egg> every egg, with what isMinecraft() reads, by name */
    private static function eggs(): Collection
    {
        try {
            return Egg::query()->with(['tags', 'configFrom'])->orderBy('name')->get()->toBase();
        } catch (\Throwable) {
            return collect();
        }
    }

    /**
     * One setting, decoded on its own: ExtensionSettings::get() decodes every row at once, so an
     * undecryptable key would make the other settings unreadable too. getByPrefix() strips the prefix, so the
     * exact key comes back as ''. It also reads the table each time instead of a per-process cache.
     */
    private static function read(string $key): mixed
    {
        return app(ExtensionRepository::class)->settings(self::ID)->getByPrefix($key)[''] ?? null;
    }
}
