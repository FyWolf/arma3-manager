<?php

namespace FyWolf\Arma3Manager\Support;

/**
 * `appworkshop_<app>.acf` — SteamCMD's record of which Workshop items it has.
 *
 * ## Why the panel touches this at all
 *
 * Deleting a mod's files is not enough to make SteamCMD fetch them again. The
 * ACF is the *tracking*, and SteamCMD trusts it: an item listed there with a
 * current manifest id is "installed", so `workshop_download_item` reports
 * success and transfers nothing. Delete the files and leave the ACF and the mod
 * simply stays missing, download after download, which is exactly the state
 * people describe as SteamCMD having lost track of a mod's version.
 *
 * So a reinstall has to remove the item from here as well as from disk. That is
 * the whole reason this class exists.
 *
 * ## Why one item rather than the whole file
 *
 * Deleting `appworkshop_107410.acf` outright is the folk remedy and it does
 * work, but it discards the record for **every** mod on the server. The next
 * start then has no idea what it already has, and a customer asking to reinstall
 * one broken 200 MB mod gets their entire 40 GB set re-fetched. On a host that
 * is a bandwidth bill and hours of downtime, caused by a button labelled
 * "reinstall this mod".
 *
 * ## The format
 *
 * Valve's KeyValues text: quoted keys, quoted values, and nested blocks in
 * braces. Items appear twice, once in each of two blocks:
 *
 *     "WorkshopItemsInstalled"
 *     {
 *         "450814997"
 *         {
 *             "manifest"    "1234567890"
 *             "timeupdated" "1699999999"
 *         }
 *     }
 *     "WorkshopItemDetails"
 *     {
 *         "450814997"
 *         {
 *             ...
 *         }
 *     }
 *
 * Both have to go, or SteamCMD reads the leftover half and still believes it.
 *
 * ## It refuses rather than guesses
 *
 * This rewrites a live file that every mod on the server depends on, and a
 * corrupted ACF is not a mod that fails to update — it is SteamCMD unable to
 * read its own state. So the result is validated before it is returned and
 * anything unexpected returns null, which callers treat as "do not write".
 * Refusing costs the customer a manual file-manager delete; a bad write costs
 * them the whole server's mod state.
 */
final class SteamAcf
{
    /**
     * Remove one Workshop item's blocks, or null if that cannot be done safely.
     *
     * Returns the content unchanged when the id is simply not present, so a
     * caller can compare and skip the write.
     */
    public static function withoutItem(string $acf, string $id): ?string
    {
        // A non-numeric id could otherwise be crafted to match a structural key
        // like "WorkshopItemsInstalled" and delete the entire block.
        if (! WorkshopId::isValid($id)) {
            return null;
        }

        if (trim($acf) === '' || ! self::isBalanced($acf)) {
            return null;
        }

        $result = $acf;

        // A loop, because the id legitimately appears in both blocks — and the
        // offsets move as each is cut, so each pass re-scans from the start.
        for ($guard = 0; $guard < 8; $guard++) {
            $span = self::findBlock($result, $id);

            if ($span === null) {
                break;
            }

            [$start, $end] = $span;

            $result = substr($result, 0, $start) . substr($result, $end);
        }

        // If it is still there after eight passes something is wrong with this
        // parser, not with the file. Do not write.
        if (self::findBlock($result, $id) !== null) {
            return null;
        }

        return self::isBalanced($result) ? $result : null;
    }

    /**
     * Whether the file lists this item at all.
     */
    public static function hasItem(string $acf, string $id): bool
    {
        return WorkshopId::isValid($id) && self::findBlock($acf, $id) !== null;
    }

    /**
     * Byte offsets [start, end) of one `"<id>" { … }` block, including the
     * whitespace before the key and the newline after the closing brace.
     *
     * Only matches the id used as a **key introducing a block**. An id that
     * happens to appear as a value — `"manifest" "450814997"` — is left alone,
     * which is the difference between removing an entry and shredding the file
     * around it.
     *
     * @return array{0: int, 1: int}|null
     */
    private static function findBlock(string $acf, string $id): ?array
    {
        $needle = '"' . $id . '"';
        $offset = 0;

        while (($position = strpos($acf, $needle, $offset)) !== false) {
            $offset = $position + strlen($needle);

            if (self::isInsideString($acf, $position)) {
                continue;
            }

            // The next non-whitespace character decides it: `{` means this is a
            // block key, anything else means it is a value or a plain pair.
            $after = $position + strlen($needle);
            $cursor = $after;

            while ($cursor < strlen($acf) && ctype_space($acf[$cursor])) {
                $cursor++;
            }

            if ($cursor >= strlen($acf) || $acf[$cursor] !== '{') {
                continue;
            }

            $close = self::matchBrace($acf, $cursor);

            if ($close === null) {
                return null;
            }

            // Take the indentation on the key's own line with it, so removing a
            // block does not leave a ragged blank line behind.
            $start = $position;

            while ($start > 0 && ($acf[$start - 1] === ' ' || $acf[$start - 1] === "\t")) {
                $start--;
            }

            $end = $close + 1;

            if ($end < strlen($acf) && $acf[$end] === "\r") {
                $end++;
            }

            if ($end < strlen($acf) && $acf[$end] === "\n") {
                $end++;
            }

            return [$start, $end];
        }

        return null;
    }

    /**
     * Offset of the `}` closing the `{` at $open, or null if unmatched.
     */
    private static function matchBrace(string $acf, int $open): ?int
    {
        $depth = 0;
        $length = strlen($acf);

        for ($i = $open; $i < $length; $i++) {
            if (self::isInsideString($acf, $i)) {
                continue;
            }

            if ($acf[$i] === '{') {
                $depth++;
            } elseif ($acf[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Whether the byte at $position sits inside a quoted string.
     *
     * Scanned from the top each time rather than tracked, which is quadratic and
     * entirely fine: these files are a few hundred KB at the very most and this
     * runs once, on a button press.
     *
     * Braces inside quoted values are the reason this exists at all — a mod
     * title with a `{` in it would otherwise throw off every offset after it.
     */
    private static function isInsideString(string $acf, int $position): bool
    {
        $inString = false;

        for ($i = 0; $i < $position; $i++) {
            $char = $acf[$i];

            if ($char === '\\' && $inString) {
                $i++;

                continue;
            }

            if ($char === '"') {
                $inString = ! $inString;
            }
        }

        return $inString;
    }

    /**
     * Whether every brace outside a quoted string is matched.
     *
     * Checked before the edit so a file that was already broken is not blamed on
     * this, and after it so a bad cut is never written back.
     */
    private static function isBalanced(string $acf): bool
    {
        $depth = 0;
        $inString = false;
        $length = strlen($acf);

        for ($i = 0; $i < $length; $i++) {
            $char = $acf[$i];

            if ($inString && $char === '\\') {
                $i++;

                continue;
            }

            if ($char === '"') {
                $inString = ! $inString;

                continue;
            }

            if ($inString) {
                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth < 0) {
                    return false;
                }
            }
        }

        return $depth === 0 && ! $inString;
    }
}
