<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel;

/**
 * One row of the panel-wide domain list. An official domain is one of the provider's hoster domains (a name goes in
 * front of it); an own domain is the account's own, the whole address, usable once the provider verified it.
 */
final readonly class DomainEntry
{
    public const OFFICIAL = 'official';
    public const OWN = 'own';

    public function __construct(
        public string $domain,
        public string $source,
        /** true = users may pick it; false = admins only */
        public bool $enabled,
        /** The provider's naming rule for labels under an official domain; '' for own domains. */
        public string $validation = '',
        /** Derived from the provider's region wildcards; null = offered on every node. */
        public ?string $region = null,
        /** An own domain's state at the provider (pending, verified, blocked, ...); '' for official domains. */
        public string $ownState = '',
        /** The TXT record that proves an own domain: name and value. */
        public string $txtName = '',
        public string $txtValue = '',
        /** When the provider last listed it, Unix time. */
        public ?int $lastSeenAt = null,
        /** The provider no longer lists it: kept so the admin sees it, but nobody can pick it. */
        public bool $gone = false,
    ) {
    }

    /** A copy with some fields changed, by name: $e->with(enabled: false). */
    public function with(mixed ...$changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    public function own(): bool
    {
        return $this->source === self::OWN;
    }

    /** May anybody use it for a new address: still listed, and an own domain only once verified. */
    public function usable(): bool
    {
        return !$this->gone && (!$this->own() || $this->ownState === 'verified');
    }

    /** What the admin list says about it, the same on both panels. */
    public function status(): string
    {
        return match (true) {
            $this->gone && $this->own() => 'No longer at DYLARIS',
            $this->gone => 'No longer offered',
            !$this->own() => 'Official',
            $this->ownState === 'verified' => 'Own domain, verified',
            $this->ownState === 'pending', $this->ownState === '' => 'Own domain, not verified yet',
            default => "Own domain, {$this->ownState}",
        };
    }

    public function choice(): AllowedDomain
    {
        return new AllowedDomain($this->domain, $this->own(), !$this->enabled, $this->validation, $this->region);
    }
}
