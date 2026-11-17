<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel\Provider;

/** Any failed provider call. status 0 means the provider could not be reached. */
final class ProviderException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        public readonly string $userMessage,
        public readonly ?int $retryAfter = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct("provider {$status} {$errorCode}: {$userMessage}", 0, $previous);
    }
}
