<?php

namespace FyWolf\Arma3Manager\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use FyWolf\Arma3Manager\Support\DaemonDirs;
use FyWolf\Arma3Manager\Support\ModList;
use FyWolf\Arma3Manager\Support\ResolvedProfile;
use FyWolf\Arma3Manager\Support\ServerVariables;
use FyWolf\Arma3Manager\Support\WorkshopId;
use RuntimeException;
use Throwable;

/**
 * The load order, and the gap between it and what is on disk.
 *
 * ## The list is `@workshopID` entries
 *
 * This is the whole shape of the class and it has been wrong twice, so it is
 * worth quoting the egg field itself:
 *
 *   > A semicolon-separated list of additional mod folders to load. […] Any
 *   > mods in this list that are in "@workshopID" form will also be included in
 *   > Automatic Updates. NO capital letters, spaces, or folders starting with a
 *   > number! (ex. myMod;vn;@123456789;@987654321;etc;)
 *
 * So one field is both the load order *and* the download trigger, and a Workshop
 * item is written as **`@` followed by its id** — `@450814997;@463939057;`.
 *
 * Two earlier attempts got this wrong in opposite directions. The first wrote
 * `@Folder` names guessed from each mod's Steam title, which downloads nothing
 * (the egg matches on `@<digits>`, not on a name) and did not match the real
 * folder either, since that comes from the mod's own `mod.cpp`. The second
 * wrote bare ids, which the egg reads as a folder name — and a folder starting
 * with a number is the one thing the field explicitly forbids.
 *
 * ## The list is deliberately mixed
 *
 * The example is `myMod;vn;@123456789;etc;`. Alongside Workshop items it
 * legitimately carries CDLC short codes and hand-uploaded folder names, neither
 * of which is downloadable and neither of which has a Steam id. Anything
 * reading this list has to tolerate all three, which is why entries are matched
 * with `WorkshopId::fromModEntry()` rather than assumed to be ids.
 *
 * ## Which means "is it downloaded?" is now answerable
 *
 * SteamCMD puts an item in `<workshop root>/content/<app>/<id>`, a path
 * derivable from the id alone. So `downloadedIds()` lists that directory and
 * every entry it finds *is* an id — no guessing, no name matching. A mod in the
 * load order with no such directory has not been fetched, and that is the number
 * the Mods page leads with.
 *
 * **The root is resolved, never hardcoded** — see `workshopRoot()`. This file
 * used to assume `steamapps/workshop`, which does not exist on the stock Arma 3
 * image: mods land in `Steam/steamapps/workshop`, because the entrypoint runs
 * `+workshop_download_item` without `+force_install_dir` and SteamCMD defaults
 * to `$HOME/Steam`. Only the *game* goes to the server root, installed with an
 * explicit `+force_install_dir`.
 *
 * The cost of that one missing segment is the reason this note is here: the
 * listing 404s, `listDirectories()` catches it and returns an empty array, so
 * every mod read as "Waiting" forever, the page claimed the entire load order
 * was missing from disk, and nothing anywhere logged an error. The download was
 * working the whole time.
 *
 * ## Writing the manifest as well as the variable
 *
 * Both are written on every save. The variable is what a startup command reads;
 * the manifest file is what an install script reads. Eggs do one or the other
 * and there is no way to tell which from here, so writing both costs one file
 * write and removes an entire class of "the panel says it saved and nothing
 * happened".
 */
class ModService
{
    /**
     * Resolved workshop root per server id, for this request only.
     *
     * @var array<string, string|null>
     */
    private array $workshopRootMemo = [];

    public function __construct(private DaemonFileRepository $repository) {}

    /**
     * The `-mod=` load order recorded for this server.
     */
    public function loadOrder(Server $server, ResolvedProfile $profile): ModList
    {
        return ModList::parse(ServerVariables::read($server, $this->modVariables($profile)));
    }

    /**
     * The `-serverMod=` load order, or an empty list when the profile has no
     * server-only concept at all.
     */
    public function serverLoadOrder(Server $server, ResolvedProfile $profile): ModList
    {
        $candidates = $this->serverModVariables($profile);

        return $candidates === [] ? ModList::fromArray([]) : ModList::parse(ServerVariables::read($server, $candidates));
    }

    /**
     * Persist a load order, to the variable and to the manifest.
     *
     * @throws RuntimeException when the egg declares no suitable variable
     */
    public function saveLoadOrder(Server $server, ResolvedProfile $profile, ModList $mods, bool $serverOnly = false): void
    {
        $candidates = $serverOnly ? $this->serverModVariables($profile) : $this->modVariables($profile);

        if ($candidates === []) {
            throw new RuntimeException(trans('arma3-manager::strings.variable_missing', ['names' => 'mod list']));
        }

        // render(), not renderPlain(): the egg's documented example ends in a
        // separator — `myMod;vn;@123456789;@987654321;etc;` — so that is the
        // shape it is known to parse.
        if (! ServerVariables::write($server, $candidates, $mods->render())) {
            throw new RuntimeException(trans('arma3-manager::strings.variable_missing', [
                'names' => implode(' / ', $candidates),
            ]));
        }

        $this->writeManifest($server, $profile, $serverOnly);
    }

    /**
     * Write the manifest an install script can read instead of an env var.
     *
     * Best effort by design: a daemon that is unreachable must not fail a save
     * that has already succeeded in the database. The variable is the primary
     * record and the manifest is a convenience, so the failure is logged
     * through the exception handler and the save stands.
     */
    public function writeManifest(Server $server, ResolvedProfile $profile, bool $serverOnly = false): void
    {
        $path = trim((string) config('arma3-manager.steamcmd.manifest_path', 'arma3-manager.mods'), '/');

        if ($path === '') {
            return;
        }

        $mods = $this->loadOrder($server, $profile);
        $serverMods = $this->serverLoadOrder($server, $profile);

        $lines = [
            '# Written by Arma 3 Manager. Do not edit by hand — it is regenerated on every save.',
            '# Steam Workshop ids, one per line, in load order.',
            '# Fetch with: steamcmd +workshop_download_item ' . (int) config('arma3-manager.workshop.app_id', 107410) . ' <id>',
            '',
            '[mod]',
            ...$mods->all(),
            '',
            '[serverMod]',
            ...$serverMods->all(),
            '',
        ];

        try {
            $this->repository->setServer($server)->putContent($path, implode("\n", $lines));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * The mod folders that actually exist on disk.
     *
     * Looks in the profile's mods directory *and* at the server root, because
     * both layouts are in the wild: some eggs symlink `@Mod` into the root next
     * to the binary, others keep everything under `mods/`. Checking one and not
     * the other reports every mod as missing on half the eggs.
     */
    public function installedFolders(Server $server, ResolvedProfile $profile): ModList
    {
        $folders = [];

        foreach (array_unique(array_filter(['/', $profile->modsDir(), $profile->serverModsDir()])) as $directory) {
            foreach ($this->listDirectories($server, $directory) as $name) {
                // Arma mod folders conventionally begin with @. Filtering on it
                // keeps the server's own `keys`, `addons`, `steamapps` and
                // `battleye` directories out of a list that is supposed to be
                // mods.
                if (str_starts_with($name, '@')) {
                    $folders[] = $name;
                }
            }
        }

        return ModList::fromArray($folders);
    }

    /**
     * Workshop ids SteamCMD has already fetched for this server.
     *
     * The directory names under `steamapps/workshop/content/<app>` *are* the
     * ids, so this needs no name matching and cannot be wrong about which mod
     * is which — unlike comparing a load order against `@folder` names, which
     * only works if the folder happens to match the Steam title.
     *
     * @return array<int, string>
     */
    public function downloadedIds(Server $server, ResolvedProfile $profile): array
    {
        // Two independent signals, unioned, because they answer slightly
        // different questions and either one alone has a blind spot.
        //
        //  - `<root>/content/<app>/<id>` is what SteamCMD fetched.
        //  - `@<id>` in the server root is the hard-link copy the entrypoint
        //    makes *after* a successful download, and it is what Arma actually
        //    loads.
        //
        // The second is what makes a hand-uploaded mod count as present, and it
        // survives an operator clearing the SteamCMD cache to reclaim disk —
        // which leaves the mod loadable but deletes the content directory. On a
        // normal download both appear, so the union is the honest answer to
        // "will this mod load?".
        return array_values(array_unique(array_merge(
            $this->workshopIdsIn($server, 'content'),
            $this->linkedIds($server),
        )));
    }

    /**
     * Workshop ids that have an `@<id>` folder in the server root.
     *
     * The entrypoint hard-links a completed item out of the SteamCMD cache and
     * into `@<id>` (or `@<id>_optional`), and that folder — not the cache — is
     * what the `-mod=` line names. Matched with `fromModEntry()`, so `@myMod`
     * and `@vn` are ignored rather than mistaken for ids.
     *
     * @return array<int, string>
     */
    private function linkedIds(Server $server): array
    {
        $ids = [];

        foreach ($this->listDirectories($server, '/') as $name) {
            $id = WorkshopId::fromModEntry(preg_replace('/_optional$/', '', $name));

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Workshop ids SteamCMD is fetching right now.
     *
     * ## Where the three states come from
     *
     * SteamCMD stages an item in `steamapps/workshop/downloads/<app>/<id>` while
     * it is transferring, then **moves** it into `content/<app>/<id>` when the
     * item is complete. Those are two different directories on disk, so the
     * distinction is real rather than inferred:
     *
     *   in `downloads/` .. downloading now
     *   in `content/` ... finished
     *   in neither ..... queued, not started
     *
     * That is the whole progress display, and it costs two directory listings.
     * It is also the only honest granularity available: SteamCMD's own transfer
     * output never reaches the panel, so a percentage *within* one item cannot
     * be shown. A 10 GB mod therefore sits on "downloading" for a long time and
     * then completes, which is worth saying on screen rather than faking with a
     * bar that moves.
     *
     * @return array<int, string>
     */
    public function downloadingIds(Server $server, ResolvedProfile $profile): array
    {
        return $this->workshopIdsIn($server, 'downloads');
    }

    /**
     * Ids directly under `<workshop root>/<bucket>/<app>`.
     *
     * `$bucket` is `content` or `downloads`; the root itself is resolved once
     * per request by `workshopRoot()`, because it differs between images.
     *
     * @return array<int, string>
     */
    private function workshopIdsIn(Server $server, string $bucket): array
    {
        $root = $this->workshopRoot($server);

        if ($root === null) {
            return [];
        }

        $appId = (int) config('arma3-manager.workshop.app_id', 107410);

        return array_values(array_filter(
            $this->listDirectories($server, $root . '/' . $bucket . '/' . $appId),
            WorkshopId::isValid(...),
        ));
    }

    /**
     * The workshop root that resolved, for `diagnose` to print.
     *
     * Its own step in the chain, because it is now a place the chain can break:
     * a null here means every mod reads as "waiting" no matter how healthy the
     * variable, the parse and the disk are — which is precisely the failure
     * that hid behind a hardcoded path before.
     */
    public function resolvedWorkshopRoot(Server $server): ?string
    {
        return $this->workshopRoot($server);
    }

    /**
     * Which of the configured workshop roots this server actually uses.
     *
     * Probed rather than assumed, and memoised per request per server so a
     * ninety-mod page costs one extra listing rather than one per row.
     *
     * A root counts as the right one when `<root>/content/<app>` **or**
     * `<root>/downloads/<app>` lists. Probing anything shallower would match a
     * `Steam/` left behind by an unrelated tool; probing `content` alone would
     * miss the case that matters most — the very first download, where
     * `downloads/` exists and `content/` does not yet, so the one page a
     * customer is actually watching would show "waiting" for every mod while
     * SteamCMD was visibly working.
     *
     * Null means no candidate answered: nothing downloaded yet, or the server
     * is offline and the daemon is refusing listings. Both report as "no ids",
     * which reads as "waiting" — honest in both cases, and the reason this
     * returns null rather than guessing a root.
     */
    private function workshopRoot(Server $server): ?string
    {
        $key = (string) $server->id;

        if (array_key_exists($key, $this->workshopRootMemo)) {
            return $this->workshopRootMemo[$key];
        }

        $appId = (int) config('arma3-manager.workshop.app_id', 107410);

        /** @var array<int, string> $roots */
        $roots = (array) config('arma3-manager.steamcmd.workshop_roots', ['Steam/steamapps/workshop']);

        foreach ($roots as $root) {
            $root = trim((string) $root, '/');

            if ($root === '') {
                continue;
            }

            if ($this->listDirectories($server, $root . '/content/' . $appId) !== []
                || $this->listDirectories($server, $root . '/downloads/' . $appId) !== []) {
                return $this->workshopRootMemo[$key] = $root;
            }
        }

        return $this->workshopRootMemo[$key] = null;
    }

    /**
     * A count of each state across the whole load order.
     *
     * Read once per render and handed to the table, so a ninety-mod list is two
     * directory listings rather than two per row.
     *
     * @return array{total: int, downloaded: int, downloading: int, waiting: int}
     */
    public function downloadProgress(Server $server, ResolvedProfile $profile): array
    {
        $downloaded = $this->downloadedIds($server, $profile);
        $downloading = $this->downloadingIds($server, $profile);

        $total = 0;
        $done = 0;
        $active = 0;

        foreach ($this->loadOrder($server, $profile)->all() as $entry) {
            $id = WorkshopId::fromModEntry($entry);

            // Only Workshop entries have a download to be part of.
            if ($id === null) {
                continue;
            }

            $total++;

            if (in_array($id, $downloaded, true)) {
                $done++;
            } elseif (in_array($id, $downloading, true)) {
                $active++;
            }
        }

        return [
            'total' => $total,
            'downloaded' => $done,
            'downloading' => $active,
            'waiting' => max(0, $total - $done - $active),
        ];
    }

    /**
     * Workshop entries in the load order that have not been downloaded.
     *
     * Non-Workshop entries — CDLC codes, hand-uploaded folders — are skipped
     * rather than counted as missing. Nothing downloads them, so reporting them
     * as outstanding would mean the count never reaches zero.
     *
     * @return array<int, string>
     */
    public function missing(Server $server, ResolvedProfile $profile): array
    {
        $downloaded = $this->downloadedIds($server, $profile);
        $out = [];

        foreach ($this->loadOrder($server, $profile)->all() as $entry) {
            $id = WorkshopId::fromModEntry($entry);

            if ($id !== null && ! in_array($id, $downloaded, true)) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * Folders on disk that nothing in the load order refers to.
     *
     * @return array<int, string>
     */
    public function orphaned(Server $server, ResolvedProfile $profile): array
    {
        $wanted = $this->loadOrder($server, $profile);
        $serverWanted = $this->serverLoadOrder($server, $profile);

        return array_values(array_filter(
            $this->installedFolders($server, $profile)->all(),
            static fn (string $folder): bool => ! $wanted->has($folder) && ! $serverWanted->has($folder),
        ));
    }

    /**
     * Remove a mod folder from disk.
     */
    public function deleteFolder(Server $server, ResolvedProfile $profile, string $folder): void
    {
        $name = ModList::folder($folder);

        if (! DaemonDirs::isSafeRelativePath($name) || ! str_starts_with($name, '@')) {
            throw new RuntimeException('Refusing to delete "' . $folder . '" — that is not a mod folder.');
        }

        $this->repository->setServer($server)->deleteFiles(
            DaemonDirs::join($profile->modsDir()),
            [$name],
        );
    }

    /**
     * @return array<int, string>
     */
    public function listDirectories(Server $server, string $path): array
    {
        try {
            $entries = $this->repository->setServer($server)->getDirectory(DaemonDirs::join($path));
        } catch (Throwable) {
            return [];
        }

        if (! is_array($entries) || isset($entries['error'])) {
            return [];
        }

        $names = [];

        foreach ($entries as $entry) {
            if (is_array($entry) && ! empty($entry['directory']) && filled($entry['name'] ?? null)) {
                $names[] = (string) $entry['name'];
            }
        }

        return $names;
    }

    /**
     * @return array<int, string>
     */
    public function modVariables(ResolvedProfile $profile): array
    {
        return $profile->modListVariables !== []
            ? $profile->modListVariables
            : (array) config('arma3-manager.steamcmd.mod_list_variables', []);
    }

    /**
     * @return array<int, string>
     */
    public function serverModVariables(ResolvedProfile $profile): array
    {
        return $profile->serverModListVariables !== []
            ? $profile->serverModListVariables
            : (array) config('arma3-manager.steamcmd.servermod_list_variables', []);
    }

    /**
     * The variable this server's mod list is actually stored in, for an error
     * message that names it.
     */
    public function variableName(Server $server, ResolvedProfile $profile): ?string
    {
        return ServerVariables::name($server, $this->modVariables($profile));
    }
}
