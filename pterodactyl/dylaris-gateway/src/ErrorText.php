<?php

namespace Dylaris\GatewayPtero;

use Dylaris\GatewayPanel\ModeRefused;
use Dylaris\GatewayPanel\Provider\ProviderException;

/** One sentence for the page that triggered a failure. Nothing is swallowed: unknown failures are reported to the log. */
final class ErrorText
{
    public static function for(\Throwable $e, bool $showDetail): string
    {
        return match (true) {
            $e instanceof ProviderException, $e instanceof ModeRefused => $e->userMessage,
            $e instanceof GatewayUnavailable => $e->getMessage(),
            default => self::unexpected($e, $showDetail),
        };
    }

    private static function unexpected(\Throwable $e, bool $showDetail): string
    {
        report($e);

        // A subuser gets no internals; an admin gets the message to act on.
        return $showDetail
            ? 'The change failed: ' . $e->getMessage()
            : 'The change failed. The panel log has the details; ask the panel admin.';
    }
}
