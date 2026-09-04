<?php

namespace FyWolf\Arma3Manager\Support;

/**
 * `.arma3-manager/status.json`, as written by the arma3-manager egg.
 *
 * ## Why this exists
 *
 * Everything the Mods page could say before this came from two directory
 * listings, and there are three questions those cannot answer:
 *
 *  - **How far through is this mod?** A listing is a name, not a size.
 *  - **What is it called?** Only Steam knows, and Steam is not always reachable.
 *  - **Did it fail?** This is the one that matters. SteamCMD reports a failed
 *    download to the *console*, which scrolls away and which nothing here
 *    parses. On disk a mod that gave up after three attempts and a mod that has
 *    not been reached yet are identical — both are simply absent — so the page
 *    said "Waiting" forever while the customer waited for something that had
 *    already stopped trying.
 *
 * The egg writes those facts down. This class reads them.
 *
 * ## It is always optional
 *
 * A server on the stock Arma 3 egg has no such file, and everything still works
 * — `ModService` falls back to probing directories, which is exactly what it did
 * before. So every accessor here answers "I don't know" rather than throwing,
 * and callers treat null as "use the disk instead".
 *
 * @see https://github.com/FyWolf/arma3-manager-egg
 */
final class DownloadStatus
{
    /**
     * How long a `mods` phase may go unwritten before it is not believed.
     *
     * The egg rewrites the file every few seconds *while downloading*, so a
     * `mods` phase that has not moved in minutes means the container died
     * mid-download — killed, out of disk, or the node rebooted. Left alone the
     * file would claim a mod is downloading forever, which is worse than the
     * problem it was written to solve: a stuck spinner is indistinguishable from
     * a slow mod, and this page's entire job is telling those apart.
     *
     * Only the live phase can go stale. `running`, `synced` and the failure
     * phases are terminal — nothing is expected to rewrite them, and an hour-old
     * `running` is perfectly accurate.
     */
    public const STALE_AFTER_SECONDS = 180;

    /**
     * @param  array<string, array<string, mixed>>  $mods  keyed by workshop id
     */
    private function __construct(
        public readonly string $phase,
        public readonly bool $syncOnly,
        public readonly int $updatedAt,
        public readonly array $mods,
    ) {}

    /**
     * Parse the file, or null if it is absent, malformed or not ours.
     *
     * Deliberately forgiving: this is read on a five-second poll on a page a
     * customer is watching, and a half-written or truncated file must degrade to
     * the directory listings rather than break the page. The `version` gate is
     * what stops a future format being misread as this one.
     */
    public static function fromJson(?string $json): ?self
    {
        if (! is_string($json) || trim($json) === '') {
            return null;
        }

        $data = json_decode($json, true);

        if (! is_array($data) || ($data['version'] ?? null) !== 1) {
            return null;
        }

        $mods = [];

        foreach ((array) ($data['mods'] ?? []) as $mod) {
            if (! is_array($mod)) {
                continue;
            }

            $id = (string) ($mod['id'] ?? '');

            // The egg writes ids and nothing else, but this file sits in the
            // customer's own file manager and can be edited. An id that is not
            // an id has no matching load-order entry anyway.
            if (! WorkshopId::isValid($id)) {
                continue;
            }

            $mods[$id] = $mod;
        }

        return new self(
            phase: (string) ($data['phase'] ?? 'unknown'),
            syncOnly: (bool) ($data['sync_only'] ?? false),
            updatedAt: (int) ($data['updated_at'] ?? 0),
            mods: $mods,
        );
    }

    /**
     * Whether a live download claim can still be believed. See STALE_AFTER_SECONDS.
     */
    public function isStale(): bool
    {
        if ($this->phase !== 'mods') {
            return false;
        }

        return $this->updatedAt <= 0
            || (time() - $this->updatedAt) > self::STALE_AFTER_SECONDS;
    }

    /**
     * `waiting`, `downloading`, `done`, `failed`, or null when not tracked.
     */
    public function state(string $id): ?string
    {
        $state = $this->mods[$id]['state'] ?? null;

        return is_string($state) && $state !== '' ? $state : null;
    }

    /**
     * @return array<int, string>
     */
    public function idsInState(string $state): array
    {
        $ids = [];

        foreach ($this->mods as $id => $mod) {
            if (($mod['state'] ?? null) === $state) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * 0-100, or null when the egg had no size to measure against.
     *
     * Null is the honest answer rather than 0: it means "no bar", where 0 would
     * read as "started and got nowhere".
     */
    public function percent(string $id): ?int
    {
        $percent = $this->mods[$id]['percent'] ?? null;

        return is_numeric($percent) ? max(0, min(100, (int) $percent)) : null;
    }

    public function bytes(string $id): ?int
    {
        $bytes = $this->mods[$id]['bytes'] ?? null;

        return is_numeric($bytes) ? (int) $bytes : null;
    }

    public function expectedBytes(string $id): ?int
    {
        $bytes = $this->mods[$id]['expected_bytes'] ?? null;

        return is_numeric($bytes) && (int) $bytes > 0 ? (int) $bytes : null;
    }

    /**
     * The mod's name as the egg resolved it, for when Steam is unreachable here.
     */
    public function name(string $id): ?string
    {
        $name = $this->mods[$id]['name'] ?? null;

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    /**
     * Why a mod failed — the thing no directory listing can ever report.
     */
    public function error(string $id): ?string
    {
        $error = $this->mods[$id]['error'] ?? null;

        return is_string($error) && trim($error) !== '' ? trim($error) : null;
    }

    /**
     * True once the egg has finished a sync without starting the server.
     */
    public function isSynced(): bool
    {
        return in_array($this->phase, ['synced', 'synced_with_errors'], true);
    }
}
