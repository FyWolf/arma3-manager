<?php

namespace FyWolf\Arma3Manager\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use FyWolf\Arma3Manager\Support\ArmaConfigFile;
use FyWolf\Arma3Manager\Support\DaemonDirs;
use FyWolf\Arma3Manager\Support\MissionRotation;
use FyWolf\Arma3Manager\Support\ResolvedProfile;
use RuntimeException;
use Throwable;

/**
 * The mpmissions directory, and the rotation in server.cfg that points into it.
 *
 * These are one feature and not two, which is why they are one service. A
 * mission uploaded and never added to the rotation does nothing; a rotation
 * entry whose .pbo was deleted holds the server in the lobby forever with no
 * error message. The page shows both together for exactly that reason, and
 * `orphanedRotationEntries()` is what makes the second failure visible.
 */
class MissionService
{
    public function __construct(
        private DaemonFileRepository $repository,
        private ConfigService $configs,
    ) {}

    /**
     * Every mission archive on the server.
     *
     * @return array<int, array{name: string, size: int, modified: ?string}>
     */
    public function list(Server $server, ResolvedProfile $profile): array
    {
        $directory = $profile->missionsDir();

        if ($directory === null) {
            return [];
        }

        try {
            $entries = $this->repository->setServer($server)->getDirectory(DaemonDirs::join($directory));
        } catch (Throwable) {
            return [];
        }

        if (! is_array($entries) || isset($entries['error'])) {
            return [];
        }

        $extensions = array_map('strtolower', (array) config('arma3-manager.missions.extensions', ['pbo']));
        $missions = [];

        foreach ($entries as $entry) {
            if (! is_array($entry) || ! empty($entry['directory'])) {
                continue;
            }

            $name = (string) ($entry['name'] ?? '');

            if ($name === '' || ! in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $extensions, true)) {
                continue;
            }

            $missions[] = [
                'name' => $name,
                'size' => (int) ($entry['size'] ?? 0),
                'modified' => isset($entry['modified']) ? (string) $entry['modified'] : null,
            ];
        }

        usort($missions, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $missions;
    }

    public function delete(Server $server, ResolvedProfile $profile, string $name): void
    {
        $directory = $profile->missionsDir();

        if ($directory === null) {
            throw new RuntimeException('This server has no missions directory.');
        }

        $file = basename(trim($name));

        if (! DaemonDirs::isSafeRelativePath($file)) {
            throw new RuntimeException('Refusing to delete "' . $name . '".');
        }

        $this->repository->setServer($server)->deleteFiles(DaemonDirs::join($directory), [$file]);
    }

    /**
     * The mission rotation, read out of server.cfg.
     */
    public function rotation(Server $server, ResolvedProfile $profile): MissionRotation
    {
        $file = $this->serverConfigPath($profile);

        if ($file === null) {
            return MissionRotation::fromArray([]);
        }

        $config = $this->configs->read($server, $file);

        if ($config === null) {
            return MissionRotation::fromArray([]);
        }

        foreach ($config->chunks() as $chunk) {
            if (($chunk['type'] ?? null) === ArmaConfigFile::CHUNK_CLASS && ($chunk['name'] ?? null) === 'Missions') {
                return MissionRotation::parse((string) $chunk['raw']);
            }
        }

        return MissionRotation::fromArray([]);
    }

    /**
     * Write the rotation back, leaving every other line of server.cfg alone.
     */
    public function saveRotation(Server $server, ResolvedProfile $profile, MissionRotation $rotation): void
    {
        $file = $this->serverConfigPath($profile);

        if ($file === null) {
            throw new RuntimeException('This server has no server.cfg to write a rotation into.');
        }

        $config = $this->configs->read($server, $file) ?? $this->configs->scaffold($server, $file);

        $this->configs->write($server, $file, $config->setBlock('Missions', $rotation->render()));
    }

    /**
     * Rotation entries whose .pbo is not on the server.
     *
     * The failure this surfaces has no other symptom: Arma does not log a
     * missing mission as an error, it simply never starts it, and the server
     * sits in the lobby looking healthy.
     *
     * @return array<int, string>
     */
    public function orphanedRotationEntries(Server $server, ResolvedProfile $profile): array
    {
        $present = array_map(
            static fn (array $mission): string => strtolower(MissionRotation::template($mission['name'])),
            $this->list($server, $profile),
        );

        $orphans = [];

        foreach ($this->rotation($server, $profile)->all() as $entry) {
            if (! in_array(strtolower($entry['template']), $present, true)) {
                $orphans[] = $entry['template'];
            }
        }

        return $orphans;
    }

    /**
     * The first config file in the profile that is not basic.cfg.
     *
     * Named by search rather than hardcoded, because an egg may call it
     * anything — `server.cfg`, `main.cfg`, `configs/server.cfg` — and the
     * rotation belongs in whichever one the startup command passes to
     * `-config=`.
     */
    private function serverConfigPath(ResolvedProfile $profile): ?string
    {
        foreach ($profile->configFiles() as $file) {
            if (! str_contains(strtolower(basename($file)), 'basic')) {
                return $file;
            }
        }

        return null;
    }
}
