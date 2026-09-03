<?php

namespace FyWolf\Arma3Manager\Support;

use App\Exceptions\Repository\FileExistsException;
use App\Repositories\Daemon\DaemonFileRepository;

/**
 * Directory helpers Wings does not provide.
 */
class DaemonDirs
{
    /**
     * Create a directory and every missing parent.
     *
     * Wings has no `mkdir -p`: `createDirectory` takes a name plus the path of
     * its parent and creates exactly one level. A mod set whose manifest lists
     * `mods/@ace/addons` therefore needs each segment created in turn,
     * and pulling into a directory that does not exist fails.
     *
     * Creating a directory that already exists returns HTTP 400, which the
     * repository converts into FileExistsException — the panel's own file
     * manager swallows it to emulate idempotency and so do we.
     */
    public static function ensure(DaemonFileRepository $repository, ?string $path): void
    {
        $path = trim((string) $path, '/');

        if ($path === '' || $path === '.') {
            return;
        }

        $parent = '/';

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            try {
                $repository->createDirectory($segment, $parent);
            } catch (FileExistsException) {
                // Already there. Keep walking.
            }

            $parent = rtrim($parent, '/') . '/' . $segment;
        }
    }

    /**
     * Join path segments into a daemon path, collapsing separators.
     *
     * Paths are relative to the server root, and a leading slash is how the
     * daemon expresses "the root" — so `join('/', 'mpmissions')` is `/mpmissions`.
     */
    public static function join(?string ...$segments): string
    {
        $parts = [];

        foreach ($segments as $segment) {
            $segment = trim((string) $segment, '/');

            if ($segment !== '' && $segment !== '.') {
                $parts[] = $segment;
            }
        }

        return '/' . implode('/', $parts);
    }

    /**
     * Reject anything that could escape the server root.
     *
     * Every path this plugin builds ultimately comes from either a Steam API
     * response, a launcher preset somebody uploaded, or a mod folder name
     * chosen by a workshop publisher. None of the three is trustworthy.
     */
    public static function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return false;
        }

        if (str_contains($path, "\0")) {
            return false;
        }

        foreach (preg_split('#[\\\\/]+#', $path) ?: [] as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }
}
