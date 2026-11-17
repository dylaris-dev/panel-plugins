<?php

declare(strict_types=1);

// Several small value objects in one file (loaded via composer "files"). Each reads only the
// keys it knows; the provider contract is add-only, so unknown keys must never break parsing.

namespace Dylaris\GatewayPanel\Provider;

final readonly class Account
{
    /** @param string[] $scopes */
    public function __construct(
        public string $product,
        public bool $managed,
        public bool $suspended,
        public array $scopes,
        public ?int $maxLinks,
        public ?int $maxRoutes,
    ) {
    }

    public static function fromArray(array $a): self
    {
        $limits = $a['limits'] ?? [];
        return new self(
            (string) ($a['product'] ?? ''),
            (bool) ($a['managed'] ?? false),
            (bool) ($a['suspended'] ?? false),
            array_values(array_map('strval', $a['scopes'] ?? [])),
            isset($limits['maxLinks']) ? (int) $limits['maxLinks'] : null,
            isset($limits['maxRoutes']) ? (int) $limits['maxRoutes'] : null,
        );
    }
}

final readonly class HosterDomain
{
    public function __construct(public string $domain, public string $validation)
    {
    }
}

final readonly class Region
{
    public function __construct(public string $region, public string $wildcard, public bool $online)
    {
    }
}

final readonly class Regions
{
    /**
     * @param HosterDomain[] $hosterDomains
     * @param Region[] $items
     */
    public function __construct(public array $hosterDomains, public array $items)
    {
    }

    public static function fromArray(array $a): self
    {
        return new self(
            array_map(
                static fn(array $d) => new HosterDomain((string) ($d['domain'] ?? ''), (string) ($d['validation'] ?? '')),
                $a['hosterDomains'] ?? [],
            ),
            array_map(
                static fn(array $r) => new Region((string) ($r['region'] ?? ''), (string) ($r['wildcard'] ?? ''), (bool) ($r['online'] ?? false)),
                $a['items'] ?? [],
            ),
        );
    }
}

final readonly class LinkKit
{
    /** @param string[] $caps */
    public function __construct(
        public string $linkId,
        public string $name,
        public string $region,
        public array $caps,
        public bool $online,
    ) {
    }

    public static function fromArray(array $a): self
    {
        return new self(
            (string) ($a['link_id'] ?? ''),
            (string) ($a['name'] ?? ''),
            (string) ($a['region'] ?? ''),
            array_values(array_map('strval', $a['caps'] ?? [])),
            (bool) ($a['online'] ?? false),
        );
    }
}

final readonly class MintedKit
{
    public function __construct(public string $linkId, public string $linkKey)
    {
    }
}

final readonly class Route
{
    /** @param array<string,mixed> $bedrock edition state as sent by the provider (java, bedrock, has_key, ...) */
    public function __construct(
        public string $domain,
        public string $targetIp,
        public int $targetPort,
        public string $linkId,
        public bool $proxyProtocol,
        public array $bedrock,
    ) {
    }

    public static function fromArray(array $a): self
    {
        return new self(
            (string) ($a['domain'] ?? ''),
            (string) ($a['target_ip'] ?? ''),
            (int) ($a['target_port'] ?? 0),
            (string) ($a['link_id'] ?? ''),
            (bool) ($a['proxy_protocol'] ?? false),
            (array) ($a['bedrock'] ?? []),
        );
    }
}

final readonly class DomainCheck
{
    public function __construct(public bool $available, public string $reason)
    {
    }
}

final readonly class CustomDomain
{
    public function __construct(public string $domain, public string $state, public string $txtName = '', public string $txtValue = '')
    {
    }

    public static function fromArray(array $a): self
    {
        return new self(strtolower((string) ($a['domain'] ?? '')), (string) ($a['state'] ?? ''), (string) ($a['txtName'] ?? ''), (string) ($a['txtValue'] ?? ''));
    }

    public function verified(): bool
    {
        return $this->state === 'verified';
    }
}
