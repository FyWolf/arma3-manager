<?php

namespace FyWolf\Arma3Manager\Support;

/**
 * A Steam Workshop published-file id, and the many ways people paste one.
 *
 * Ids are 64-bit and are handled as **strings throughout**. On a 32-bit PHP
 * build `(int)` would silently truncate one, and even on 64-bit a JSON decode
 * of a large id can arrive as a float and stringify as `4.5081e+8`. Neither
 * failure is visible: the resulting id simply resolves to nothing, and the mod
 * quietly never installs.
 */
class WorkshopId
{
    /**
     * Extract an id from a raw id, a workshop URL, or a launcher link.
     *
     * Accepts everything a customer might paste:
     *
     *   450814997
     *   https://steamcommunity.com/sharedfiles/filedetails/?id=450814997
     *   https://steamcommunity.com/workshop/filedetails/?id=450814997&searchtext=cba
     *   steam://url/CommunityFilePage/450814997
     */
    public static function extract(?string $input): ?string
    {
        $value = trim((string) $input);

        if ($value === '') {
            return null;
        }

        if (self::isValid($value)) {
            return ltrim($value, '0') === '' ? null : $value;
        }

        if (preg_match('/[?&]id=(\d{4,20})/i', $value, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('#CommunityFilePage/(\d{4,20})#i', $value, $matches) === 1) {
            return $matches[1];
        }

        // A bare id with surrounding noise — e.g. a line copied out of a
        // spreadsheet. Only trusted when it is the only number present, since
        // guessing which of several numbers is the id is worse than refusing.
        if (preg_match_all('/\d{4,20}/', $value, $matches) === 1) {
            return $matches[0][0];
        }

        return null;
    }

    /**
     * Extract every id from a block of text, in order and without duplicates.
     *
     * The paste box on the Workshop page takes a list — one per line, or a
     * whole collection URL list copied out of a Discord message.
     *
     * @return array<int, string>
     */
    public static function extractAll(?string $input): array
    {
        $value = (string) $input;

        if (trim($value) === '') {
            return [];
        }

        $found = [];

        foreach (preg_split('/[\s,;]+/', $value) ?: [] as $token) {
            $id = self::extract($token);

            if ($id !== null && ! in_array($id, $found, true)) {
                $found[] = $id;
            }
        }

        return $found;
    }

    /**
     * Whether a string is a plausible published-file id.
     *
     * Digits only, and long enough not to be a page number. Steam does not
     * publish a format guarantee, so this stays a shape check rather than a
     * range check — the API is the authority on whether an id exists.
     */
    public static function isValid(?string $value): bool
    {
        $value = trim((string) $value);

        return $value !== '' && preg_match('/^\d{4,20}$/', $value) === 1;
    }

    /**
     * The public page for an id, for a "view on Steam" link.
     */
    public static function url(string $id): string
    {
        return 'https://steamcommunity.com/sharedfiles/filedetails/?id=' . $id;
    }

    /**
     * The folder name SteamCMD will leave an item in.
     *
     * Deliberately **not** derived from the item's title. SteamCMD downloads
     * into `steamapps/workshop/content/<app>/<id>`, and the `@Name` folder the
     * server loads is created afterwards by the egg's install script from the
     * mod's own `mod.cpp`. Guessing a folder name from the workshop title gets
     * it wrong often — titles carry version numbers, emoji and spaces that the
     * folder does not — so this returns the id-based path, which is the only
     * one the panel can know for certain.
     */
    public static function contentPath(string $id, int $appId): string
    {
        return 'steamapps/workshop/content/' . $appId . '/' . $id;
    }
}
