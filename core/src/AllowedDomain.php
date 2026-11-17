<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel;

/**
 * One domain a node offers for gateway addresses, set by the admin on the node's Gateway tab.
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
    ) {
    }

    /** @return array{domain: string, custom: bool, admin_only: bool, validation: string} */
    public function toArray(): array
    {
        return ['domain' => $this->domain, 'custom' => $this->custom, 'admin_only' => $this->adminOnly, 'validation' => $this->validation];
    }

    public static function fromArray(array $a): self
    {
        return new self((string) ($a['domain'] ?? ''), (bool) ($a['custom'] ?? false), (bool) ($a['admin_only'] ?? false), (string) ($a['validation'] ?? ''));
    }

    /** The address for $label under this domain; an own domain ignores the label. */
    public function address(string $label): string
    {
        return $this->custom ? $this->domain : "{$label}.{$this->domain}";
    }
}
