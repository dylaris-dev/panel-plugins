<?php

namespace Dylaris\GatewayPelican\Console;

use Dylaris\GatewayPanel\DomainList;
use Dylaris\GatewayPanel\Provider\ProviderException;
use Dylaris\GatewayPelican\GatewaySettings;
use Dylaris\GatewayPelican\GatewayUnavailable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Scheduled hourly: brings the provider's official domains and the own-domain states into the panel's list. */
class SyncDomainsCommand extends Command
{
    protected $signature = 'dylaris-gateway:sync-domains';

    protected $description = 'Sync the gateway domain list with DYLARIS.';

    public function handle(): int
    {
        try {
            $entries = app(DomainList::class)->sync();
        } catch (GatewayUnavailable $e) {
            // Not set up yet is a normal state and stays quiet; a stored key that fails must leave a trace.
            if (GatewaySettings::current()->hasKey()) {
                Log::warning('DYLARIS gateway domain sync skipped: ' . $e->getMessage());
            }
            $this->info('DYLARIS gateway domain sync skipped: ' . $e->getMessage());

            return self::SUCCESS;
        } catch (ProviderException $e) {
            Log::warning('DYLARIS gateway domain sync failed, the stored list stays: ' . $e->userMessage);
            $this->error('Domain sync failed, the stored list stays: ' . $e->userMessage);

            return self::FAILURE;
        }
        $this->line(DomainList::summary($entries));

        return self::SUCCESS;
    }
}
