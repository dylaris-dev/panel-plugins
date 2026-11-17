<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel\Tests;

use Dylaris\GatewayPanel\MinecraftEggs;
use PHPUnit\Framework\TestCase;

final class MinecraftEggsTest extends TestCase
{
    /** Names, tags and features as the eggs shipped with Pterodactyl 2.0.0-beta.2 and pelican-eggs report them. */
    public static function eggs(): array
    {
        return [
            'Paper' => ['Paper', ['minecraft'], ['eula', 'java_version', 'pid_limit'], true],
            'Bungeecord' => ['Bungeecord', ['minecraft'], ['eula', 'java_version', 'pid_limit'], true],
            'Velocity without eula' => ['Velocity', ['minecraft', 'proxy'], ['java_version'], true],
            'a custom Java egg with only the tag' => ['My Modpack', ['Minecraft'], [], true],
            'a custom Java egg with only the feature' => ['My Modpack', [], ['eula'], true],
            'Bedrock Dedicated Server' => ['Bedrock Dedicated Server', ['minecraft', 'bedrock'], [], false],
            'a Bedrock egg tagged minecraft only' => ['Bedrock', ['minecraft'], [], false],
            'Rust' => ['Rust', ['rust'], ['steam_disk_space'], false],
            'Ark' => ['Ark: Survival Evolved', ['srcds'], ['steam_disk_space'], false],
            'Mumble' => ['Mumble Server', ['voice'], [], false],
        ];
    }

    /** @dataProvider eggs */
    #[\PHPUnit\Framework\Attributes\DataProvider('eggs')]
    public function testOnlyJavaMinecraftEggsAreAllowedByDefault(string $name, array $tags, array $features, bool $want): void
    {
        $this->assertSame($want, MinecraftEggs::isJavaMinecraft($name, $tags, $features));
    }
}
