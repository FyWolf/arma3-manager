<?php

namespace FyWolf\Arma3Manager\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use FyWolf\Arma3Manager\Support\DaemonDirs;
use FyWolf\Arma3Manager\Support\ModList;
use FyWolf\Arma3Manager\Support\ResolvedProfile;
use FyWolf\Arma3Manager\Support\ServerVariables;
use RuntimeException;
use Throwable;

/**
 * The load order, and the gap between it and what is on disk.
 *
 * ## Two sources of truth, and neither is authoritative alone
 *
 * The **load order** lives in a server variable, because that is what the
 * egg's startup command interpolates into `-mod=`. It is what the server will
 * try to load.
 *
 * The **installed folders** live on disk, and are what SteamCMD has actually
 * fetched. They are what the server can load.
 *
 * The interesting state is the difference. A mod in the load order and not on
 * disk is a server that will not start, or will start and kick every client for
 * a missing addon; a folder on disk and not in the load order is wasted disk and
 * nothing worse. So `missing()` is the number the Mods page leads with, and the
 * reason this class reads both rather than trusting either.
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
            '# One entry per line, in load order.',
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
     * Entries in the load order with no matching folder on disk.
     *
     * @return array<int, string>
     */
    public function missing(Server $server, ResolvedProfile $profile): array
    {
        return $this->loadOrder($server, $profile)->missingFrom($this->installedFolders($server, $profile));
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
    private function listDirectories(Server $server, string $path): array
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
