<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel\Tests;

use Dylaris\GatewayPanel\AddressLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AddressLabelTest extends TestCase
{
    /** @return array<string, array{string, string, string}> */
    public static function names(): array
    {
        return [
            'letters drops digits and symbols' => ['Paper A 1.21!', 'letters', 'papera'],
            'alphanumeric keeps digits' => ['Paper A 1.21!', 'alphanumeric', 'papera121'],
            'dns joins words with one dash' => ['  Paper -- A 1.21! ', 'dns', 'paper-a-1-21'],
            'nothing left' => ['1234 !!', 'letters', ''],
            'unknown validation is as strict as letters' => ['Lobby 2', 'something-new', 'lobby'],
            'non-ASCII is dropped' => ['Über Welt', 'letters', 'berwelt'],
        ];
    }

    #[DataProvider('names')]
    public function testFromName(string $name, string $validation, string $want): void
    {
        $this->assertSame($want, AddressLabel::fromName($name, $validation));
    }

    public function testLengthIsCappedAndDnsDoesNotEndInADash(): void
    {
        $this->assertSame(63, strlen(AddressLabel::fromName(str_repeat('ab', 50), 'letters')));
        $dns = AddressLabel::fromName(str_repeat('a', 62) . ' b', 'dns');
        $this->assertSame(str_repeat('a', 62), $dns);
    }

    public function testRandomIsShortAndLettersOnly(): void
    {
        $this->assertMatchesRegularExpression('/^[a-z]{8}$/', AddressLabel::random());
    }

    public function testCandidatesStartWithTheNameThenRandom(): void
    {
        $c = iterator_to_array(AddressLabel::candidates('Lobby', 'letters'), false);
        $this->assertSame('lobby', $c[0]);
        $this->assertCount(4, $c);
        $this->assertCount(3, iterator_to_array(AddressLabel::candidates('123', 'letters'), false), 'no name candidate');
    }

    public function testRefusalNamesTheRule(): void
    {
        $this->assertSame('That address name is not allowed here. Use letters only (a-z), at most 63 characters.', AddressLabel::refusal('invalid', 'letters'));
        $this->assertSame('That address name is not allowed here. Use letters and digits only (a-z, 0-9), at most 63 characters.', AddressLabel::refusal('invalid', 'alphanumeric'));
        $this->assertStringContainsString('dashes', AddressLabel::refusal('invalid', 'dns'));
        $this->assertSame('That address is already taken. Choose another name.', AddressLabel::refusal('taken', 'letters'));
        $this->assertSame('That address name is reserved. Choose another name.', AddressLabel::refusal('blocked', 'letters'));
        $this->assertSame('The gateway does not offer that domain.', AddressLabel::refusal('not_hosted', 'letters'));
        $this->assertSame('That domain is not verified for this account yet.', AddressLabel::refusal('unverified', 'letters'));
        $this->assertSame('That address is not available (odd).', AddressLabel::refusal('odd', 'letters'));
    }
}
