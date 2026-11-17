<?php

declare(strict_types=1);

namespace Dylaris\GatewayPanel;

final class ReconcileReport
{
    public const SKIPPED_LOCK = 'Reconcile skipped: another run is in progress.';

    /** @var string[] one line per server the run changed */
    public array $fixed = [];
    /** @var array<int,string> error per server id, also stored in that server's lastError */
    public array $errors = [];
    /** Seconds until the provider accepts calls again; the run stopped (or did not start) because of a 429. */
    public ?int $stoppedForRetryAfter = null;
    public int $created = 0;
    public int $deleted = 0;
    public int $retargeted = 0;

    /**
     * What the console command prints, the same on both panels.
     *
     * @return list<string>
     */
    public function summary(): array
    {
        $out = ["Routes: {$this->created} created, {$this->deleted} deleted, {$this->retargeted} retargeted. Errors: " . count($this->errors) . '.'];
        foreach ($this->fixed as $line) {
            $out[] = $line;
        }
        foreach ($this->errors as $serverId => $error) {
            $out[] = "Server {$serverId}: {$error}";
        }
        if ($this->stoppedForRetryAfter !== null) {
            $out[] = "The provider asked to wait {$this->stoppedForRetryAfter} s; the run stopped.";
        }
        return $out;
    }
}
