<?php

namespace FyWolf\Arma3Manager\Services;

use App\Models\Server;
use App\Models\User;
use FyWolf\Arma3Manager\Enums\InstallState;
use FyWolf\Arma3Manager\Jobs\InstallModSetJob;
use FyWolf\Arma3Manager\Models\ModSet;
use FyWolf\Arma3Manager\Models\ModSetInstall;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Installing a curated mod set onto a server.
 *
 * ## One install at a time, enforced in a transaction
 *
 * Two installs racing rewrite the same load order from two different starting
 * points and the loser's mods vanish — silently, because both jobs report
 * success. The guard is a `SELECT … FOR UPDATE` over the server's active rows
 * rather than a check-then-insert, because check-then-insert is exactly the
 * race it is trying to prevent.
 *
 * ## Which sets a server may install
 *
 * Public ones, plus anything the billing service has granted it. Asked as one
 * query (`ModSet::installableBy`) rather than two merged in PHP, since a set
 * that is both public *and* granted appears twice in the merged version — and
 * renders as two identical install buttons.
 */
class ModSetService
{
    /**
     * @return Collection<int, ModSet>
     */
    public function installable(Server $server): Collection
    {
        return ModSet::query()
            ->installableBy($server)
            ->orderBy('sort')
            ->orderBy('name')
            ->get();
    }

    public function activeInstall(Server $server): ?ModSetInstall
    {
        return ModSetInstall::query()
            ->where('server_id', $server->id)
            ->active()
            ->latest('id')
            ->first();
    }

    /**
     * Queue an install, refusing if one is already running.
     *
     * @throws RuntimeException
     */
    public function start(Server $server, ModSet $set, ?User $actor = null): ModSetInstall
    {
        $install = DB::transaction(function () use ($server, $set, $actor): ModSetInstall {
            $running = ModSetInstall::query()
                ->where('server_id', $server->id)
                ->active()
                ->lockForUpdate()
                ->first();

            if ($running) {
                throw new RuntimeException(
                    'An install of "' . $running->mod_set_name . '" is already running on this server.',
                );
            }

            return ModSetInstall::create([
                'server_id' => $server->id,
                'mod_set_id' => $set->id,
                // Frozen, so the row still reads sensibly if the set is later
                // renamed or removed from the catalogue.
                'mod_set_name' => $set->name,
                'state' => InstallState::Queued,
                'user_id' => $actor?->id,
                'heartbeat_at' => now(),
            ]);
        });

        InstallModSetJob::dispatch($install->id)
            ->onQueue((string) config('arma3-manager.modsets.queue', 'default'));

        return $install;
    }

    /**
     * Give up on installs that stopped reporting.
     *
     * Without this, one `queue:restart` during a deploy locks a server out of
     * every future install: the abandoned row stays non-terminal and the guard
     * above refuses everything after it.
     *
     * @return int How many were reaped.
     */
    public function pruneStale(): int
    {
        $cutoff = now()->subMinutes(max(1, (int) config('arma3-manager.modsets.stale_after_minutes', 60)));

        $stale = ModSetInstall::query()
            ->active()
            ->where(function ($query) use ($cutoff) {
                $query->where('heartbeat_at', '<', $cutoff)
                    // A row that never got a heartbeat at all is judged on when
                    // it was created, or it would never be eligible.
                    ->orWhere(fn ($inner) => $inner->whereNull('heartbeat_at')->where('created_at', '<', $cutoff));
            })
            ->get();

        foreach ($stale as $install) {
            $install->advance(InstallState::Failed, [
                'error' => 'The install stopped reporting and was abandoned. Nothing was rolled back — check the mod list.',
                'finished_at' => now(),
            ]);
        }

        return $stale->count();
    }
}
