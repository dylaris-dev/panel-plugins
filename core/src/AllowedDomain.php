<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel;

/**
 * One domain an actor may pick for a gateway address on a node: an entry of the panel-wide domain list (DomainEntry),
 * as ModeService::domainChoices() hands it out.
 *
 * A hoster domain (one of the provider's, say eu.dylaris.com) takes a label in front of it. An own domain is the whole
 * address: the provider proves ownership per exact name (TXT on _dylaris-verify.<name>), so a label in front of a
 * verified own domain would be a different, unproven name.
 */
final readonly class AllowedDomain
{
    public function __construct(
        public string $domain,
        public bool $custom = false,
        public bool $adminOnly = false,
        /** The provider's naming rule for labels under a hoster domain: letters, alphanumeric or dns. '' for own domains. */
        public string $validation = '',
        /** The gateway region the domain belongs to; null = offered on every node. Shown next to the domain. */
        public ?string $region = null,
    ) {
    }

    /** @return array{domain: string, custom: bool, admin_only: bool, validation: string, region: ?string} */
    public function toArray(): array
    {
        return ['domain' => $this->domain, 'custom' => $this->custom, 'admin_only' => $this->adminOnly, 'validation' => $this->validation, 'region' => $this->region];
    }

    public static function fromArray(array $a): self
    {
        $region = $a['region'] ?? null;
        return new self((string) ($a['domain'] ?? ''), (bool) ($a['custom'] ?? false), (bool) ($a['admin_only'] ?? false), (string) ($a['validation'] ?? ''), $region === null ? null : (string) $region);
    }

    /** The address for $label under this domain; an own domain ignores the label. */
    public function address(string $label): string
    {
        return $this->custom ? $this->domain : "{$label}.{$this->domain}";
    }
}
