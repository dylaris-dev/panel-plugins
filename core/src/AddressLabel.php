<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel;

/**
 * The first part of a gateway address under a hoster domain. The provider checks it against the hoster
 * domain's validation: 1 to 63 characters,
 * `letters` a-z, `alphanumeric` a-z and 0-9, `dns` a DNS label.
 */
final class AddressLabel
{
    public const MAX = 63;

    /** The server name reduced to what $validation allows; '' when nothing is left. */
    public static function fromName(string $name, string $validation): string
    {
        $name = strtolower($name);
        if ($validation === 'dns') {
            $label = trim((string) preg_replace('/[^a-z0-9]+/', '-', $name), '-');

            return rtrim(substr($label, 0, self::MAX), '-');
        }
        $pattern = $validation === 'alphanumeric' ? '/[^a-z0-9]/' : '/[^a-z]/';

        return substr((string) preg_replace($pattern, '', $name), 0, self::MAX);
    }

    /** A short letters-only label, valid under every validation. */
    public static function random(int $length = 8): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= chr(random_int(ord('a'), ord('z')));
        }

        return $out;
    }

    /**
     * Labels to try for a server, best first: its name, then a few random ones in case the name is taken.
     *
     * @return \Generator<string>
     */
    public static function candidates(string $name, string $validation, int $randomTries = 3): \Generator
    {
        $fromName = self::fromName($name, $validation);
        if ($fromName !== '') {
            yield $fromName;
        }
        for ($i = 0; $i < $randomTries; $i++) {
            yield self::random();
        }
    }

    /** What a valid label looks like under $validation, as a sentence. */
    public static function rule(string $validation): string
    {
        return match ($validation) {
            'alphanumeric' => 'Use letters and digits only (a-z, 0-9), at most 63 characters.',
            'dns' => 'Use lowercase letters, digits and dashes, not at the start or end, at most 63 characters.',
            default => 'Use letters only (a-z), at most 63 characters.',
        };
    }

    /** The sentence for a check-domain refusal; the reasons are the provider's (taken, blocked, invalid, not_hosted, unverified). */
    public static function refusal(string $reason, string $validation): string
    {
        return match ($reason) {
            'invalid' => 'That address name is not allowed here. ' . self::rule($validation),
            'taken' => 'That address is already taken. Choose another name.',
            'blocked' => 'That address name is reserved. Choose another name.',
            'not_hosted' => 'The gateway does not offer that domain.',
            'unverified' => 'That domain is not verified for this account yet.',
            default => "That address is not available ({$reason}).",
        };
    }
}
