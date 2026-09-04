<?php

namespace FyWolf\Arma3Manager\Jobs;

use App\Facades\Activity;
use FyWolf\Arma3Manager\Enums\InstallState;
use FyWolf\Arma3Manager\Integrations\Workshop\SteamWorkshopClient;
use FyWolf\Arma3Manager\Models\ModSetInstall;
use FyWolf\Arma3Manager\Services\ModService;
use FyWolf\Arma3Manager\Support\CapabilityResolver;
use FyWolf\Arma3Manager\Support\ModList;
use FyWolf\Arma3Manager\Support\WorkshopId;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Resolve a mod set's dependencies and write the resulting load order.
 *
 * ## What this job does not do
 *
 * It does not download anything. Arma 3 Workshop items cannot be fetched by an
 * anonymous SteamCMD login, and rather than hold a Steam credential in the
 * panel the download is left to the server's own container, which already has
 * the customer's Steam account on its egg.
 *
 * So the job ends at `AwaitingDownload` and that is a **success**, not a
 * timeout. The load order is written, the manifest is written, and the customer
 * is told to start the server so the egg fetches what is
 * now listed. Treating the handover as a failure would have marked every
 * successful install failed.
 *
 * ## Why the id, not the model
 *
 * `SerializesModels` would re-query the install on unserialise and throw
 * ModelNotFoundException if it had been reaped in the meantime — killing the
 * job with a stack trace rather than letting it notice and stop quietly.
 */
class InstallModSetJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Long, because dependency resolution is a series of round trips to Steam
     * and Steam is slow under load. Nothing here holds a lock, so a slow run
     * costs a worker and nothing else.
     */
    public int $timeout = 600;

    /**
     * One attempt. A retry would re-resolve from a load order this job has
     * already half-rewritten, and "half" is the state with no safe rerun.
     */
    public int $tries = 1;

    public function __construct(public int $installId) {}

    public function handle(
        SteamWorkshopClient $workshop,
        ModService $mods,
        CapabilityResolver $resolver,
    ): void {
        $install = ModSetInstall::query()->with(['server', 'modSet'])->find($this->installId);

        if (! $install || $install->state->isTerminal()) {
            // Reaped, cancelled, or already finished. Nothing to do, and
            // nothing worth logging as an error.
            return;
        }

        $server = $install->server;
        $set = $install->modSet;

        if (! $server || ! $set) {
            $this->fail($install, 'The server or the mod set no longer exists.');

            return;
        }

        $profile = $resolver->for($server);

        if (! $profile) {
            $this->fail($install, 'This server\'s egg is no longer mapped to an Arma 3 profile.');

            return;
        }

        try {
            $install->advance(InstallState::Resolving, ['started_at' => $install->started_at ?? now()]);

            $wanted = $set->workshopIds();

            if ($wanted === []) {
                $this->fail($install, 'That mod set lists no Workshop items.');

                return;
            }

            // Dependencies first, in load order. This is the whole value of the
            // set: a customer cannot be expected to know CBA_A3 has to precede
            // ACE, and a set that gets it wrong does not boot.
            $resolved = $workshop->resolveDependencies($wanted);

            if ($resolved === []) {
                $this->fail($install, 'Steam returned nothing for any item in that set. It may be unreachable, or the set may reference removed items.');

                return;
            }

            $install->advance(InstallState::Writing, [
                'resolved' => count($resolved),
                'total' => count($resolved),
            ]);

            $items = $workshop->items($resolved);

            $banned = array_values(array_filter(
                $items,
                static fn ($item): bool => ! $item->isInstallable(),
            ));

            if ($banned !== []) {
                $this->fail($install, count($banned) . ' item(s) in that set have been removed from the Workshop and cannot be installed.');

                return;
            }

            // Ids, in resolved order — dependencies first. The load order is
            // what the egg's install script downloads from, and the only value
            // SteamCMD can act on is an id; a folder name there downloads
            // nothing, which is the fault this replaced.
            $order = $mods->loadOrder($server, $profile);

            foreach ($resolved as $id) {
                $order->add(WorkshopId::modEntry($id));
            }

            $mods->saveLoadOrder($server, $profile, $order);

            $serverMods = $set->server_mods ?? [];

            if ($serverMods !== [] && $mods->serverModVariables($profile) !== []) {
                $serverOrder = $mods->serverLoadOrder($server, $profile);

                foreach ($serverMods as $mod) {
                    $id = is_array($mod) ? WorkshopId::extract((string) ($mod['id'] ?? '')) : null;

                    if ($id !== null) {
                        $serverOrder->add(WorkshopId::modEntry($id));
                    }
                }

                $mods->saveLoadOrder($server, $profile, $serverOrder, serverOnly: true);
            }

            $install->advance(InstallState::AwaitingDownload, ['applied_mods' => $order->all()]);

            // The handover, and the end of what the panel can do by itself.
            $install->advance(InstallState::Completed, ['finished_at' => now()]);

            Activity::event('server:arma3.modset-install')
                ->subject($server)
                ->property([
                    'set' => $install->mod_set_name,
                    'items' => count($resolved),
                ])
                ->log();
        } catch (Throwable $exception) {
            report($exception);
            $this->fail($install, $exception->getMessage());
        }
    }

    private function fail(ModSetInstall $install, string $reason): void
    {
        $install->advance(InstallState::Failed, [
            // Truncated to the column width rather than left to the database to
            // reject: a failed install that then fails to record why is the
            // worst of both.
            'error' => mb_substr($reason, 0, 250),
            'finished_at' => now(),
        ]);

        if ($install->server) {
            Activity::event('server:arma3.modset-failed')
                ->subject($install->server)
                ->property(['set' => $install->mod_set_name, 'error' => mb_substr($reason, 0, 250)])
                ->log();
        }
    }

    /**
     * Queue-level failure — a timeout, or the worker being killed.
     *
     * Without this the row stays non-terminal until the reaper notices it an
     * hour later, and the server refuses every install in between.
     */
    public function failed(?Throwable $exception): void
    {
        $install = ModSetInstall::find($this->installId);

        if ($install && ! $install->state->isTerminal()) {
            $this->fail($install, $exception?->getMessage() ?? 'The install worker stopped unexpectedly.');
        }
    }

    /**
     * @return array<int, string>
     */
    public static function normaliseIds(array $ids): array
    {
        return array_values(array_filter(array_map(
            static fn ($id): ?string => WorkshopId::extract((string) $id),
            $ids,
        )));
    }

    public static function emptyOrder(): ModList
    {
        return ModList::fromArray([]);
    }
}
