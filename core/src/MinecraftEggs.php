<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel;

/**
 * The default answer to "may this egg use the gateway": the edge routes by the Minecraft Java handshake (Bedrock rides
 * on Geyser in front of a Java server), so only Java Minecraft servers work behind it.
 *
 * Both panels expose an egg's features and tags. Every Java Minecraft egg shipped with Pterodactyl 2.0 and Pelican
 * declares the `eula` or `java_version` feature and the `minecraft` tag; a Bedrock Dedicated Server egg is tagged
 * minecraft too, but speaks RakNet only, so a bedrock tag or name without a Java feature keeps it out.
 */
final class MinecraftEggs
{
    /**
     * @param list<string> $tags
     * @param list<string> $features
     */
    public static function isJavaMinecraft(string $name, array $tags, array $features): bool
    {
        $tags = array_map('strtolower', $tags);
        $java = array_intersect(['eula', 'java_version'], array_map('strtolower', $features)) !== [];
        if ($java) {
            return true;
        }
        $bedrock = in_array('bedrock', $tags, true) || str_contains(strtolower($name), 'bedrock');

        return in_array('minecraft', $tags, true) && !$bedrock;
    }
}
