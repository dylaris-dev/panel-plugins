<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel;

/** When the domain list was last synced with the provider, and how the last attempt went. */
final readonly class DomainSync
{
    public function __construct(
        /** The last successful sync, Unix time; null = never. */
        public ?int $syncedAt = null,
        /** The last attempt, successful or not. */
        public ?int $triedAt = null,
        /** Why the last attempt failed; null after a success. */
        public ?string $error = null,
        /**
         * Whether an official domain the list has not seen yet arrives enabled. True, except right after the migration
         * from per-node lists when every node had saved one: those nodes offered only what they ticked.
         */
        public bool $newOfficialEnabled = true,
    ) {
    }
}
