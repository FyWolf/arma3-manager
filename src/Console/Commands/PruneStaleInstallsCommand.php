<?php

namespace FyWolf\Arma3Manager\Console\Commands;

use FyWolf\Arma3Manager\Services\ModSetService;
use Illuminate\Console\Command;

/**
 * Fail mod set installs that stopped reporting.
 *
 * Scheduled hourly by the service provider. Without it, one `queue:restart`
 * during a deploy permanently locks a server out of further installs: the
 * abandoned row stays non-terminal and the one-install-per-server guard refuses
 * everything afterwards.
 */
class PruneStaleInstallsCommand extends Command
{
    protected $signature = 'arma3-manager:prune-installs';

    protected $description = 'Abandon Arma 3 mod set installs that stopped reporting.';

    public function handle(ModSetService $sets): int
    {
        $reaped = $sets->pruneStale();

        $this->info($reaped === 0
            ? 'No stale installs.'
            : "Abandoned {$reaped} stale install(s).");

        return self::SUCCESS;
    }
}
