<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel;

/** A mode change or switch that is not possible; $userMessage is shown as is. Nothing was changed. */
final class ModeRefused extends \RuntimeException
{
    public function __construct(public readonly string $userMessage, ?\Throwable $previous = null)
    {
        parent::__construct($userMessage, 0, $previous);
    }
}
