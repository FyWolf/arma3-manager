<?php

namespace FyWolf\Arma3Manager\Support;

/**
 * An Arma 3 `-mod=` load order.
 *
 * The format is a semicolon-separated list of directories, and **the order is
 * load-bearing in the literal sense**: Arma merges addons in the order given,
 * so a mod that patches another must come after it. That is why this is an
 * ordered list with explicit move operations rather than a set — sorting it for
 * display, or storing it in anything that does not preserve insertion order,
 * silently changes what the server runs.
 *
 * ## Why entries are compared case-insensitively but stored as written
 *
 * Arma's own launcher writes `@CBA_A3`; SteamCMD writes the folder exactly as
 * the publisher named it, which is frequently `@cba_a3`. On a Linux server
 * those are two different directories and loading both is a duplicate-addon
 * error at boot. On Windows they are the same directory and loading both is
 * merely wasteful. Deduplicating case-insensitively is correct on both, while
 * preserving the original spelling keeps the path valid on Linux.
 */
class ModList
{
    /**
     * @param array<int, string> $entries
     */
    private function __construct(private array $entries) {}

    /**
     * Parse a `-mod=` value, with or without the flag itself.
     *
     * Accepts `;` and `,` as separators. Commas are not Arma's syntax, but
     * several eggs document their MODS variable with them and a customer who
     * follows the egg's own description should not end up with one mod whose
     * name contains a comma.
     */
    public static function parse(?string $raw): self
    {
        $value = trim((string) $raw);

        if ($value === '') {
            return new self([]);
        }

        // Tolerate the whole flag being pasted in, quoted or not.
        $value = preg_replace('/^-+(?:server)?mod\s*=\s*/i', '', $value) ?? $value;
        $value = trim($value, " \t\n\r\0\x0B\"'");

        $entries = [];

        foreach (preg_split('/[;,]+/', $value) ?: [] as $entry) {
            $entry = trim($entry, " \t\n\r\0\x0B\"'");

            if ($entry !== '') {
                $entries[] = $entry;
            }
        }

        return (new self($entries))->deduplicate();
    }

    /**
     * @param array<int, string> $entries
     */
    public static function fromArray(array $entries): self
    {
        return (new self(array_values(array_map(
            static fn ($entry): string => trim((string) $entry, " \t\n\r\0\x0B\"'"),
            $entries,
        ))))->deduplicate();
    }

    /**
     * @return array<int, string>
     */
    public function all(): array
    {
        return $this->entries;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    public function has(string $entry): bool
    {
        return $this->indexOf($entry) !== null;
    }

    public function indexOf(string $entry): ?int
    {
        $needle = self::key($entry);

        foreach ($this->entries as $index => $candidate) {
            if (self::key($candidate) === $needle) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Append unless already present. Never reorders an existing entry —
     * "add this mod" must not silently move a mod the customer deliberately
     * placed.
     */
    public function add(string $entry): self
    {
        $entry = trim($entry, " \t\n\r\0\x0B\"'");

        if ($entry === '' || $this->has($entry)) {
            return $this;
        }

        $this->entries[] = $entry;

        return $this;
    }

    public function remove(string $entry): self
    {
        $index = $this->indexOf($entry);

        if ($index !== null) {
            unset($this->entries[$index]);
            $this->entries = array_values($this->entries);
        }

        return $this;
    }

    /**
     * Move an entry to an absolute position, clamped into range.
     *
     * Clamped rather than rejected: the caller is a drag handle or an up/down
     * button, and refusing a move off the end of the list would make the first
     * row's "up" button an error rather than a no-op.
     */
    public function move(string $entry, int $to): self
    {
        $from = $this->indexOf($entry);

        if ($from === null) {
            return $this;
        }

        $to = max(0, min($to, count($this->entries) - 1));

        if ($from === $to) {
            return $this;
        }

        $value = $this->entries[$from];
        unset($this->entries[$from]);
        $this->entries = array_values($this->entries);
        array_splice($this->entries, $to, 0, [$value]);

        return $this;
    }

    /**
     * Reorder to match the given list exactly, keeping anything not mentioned.
     *
     * The "keeping anything not mentioned" half is the point: the page sends
     * the rows it rendered, and a mod added by a concurrent sync between render
     * and save is not in that list. Dropping it would silently uninstall a mod
     * because somebody reordered two others.
     *
     * @param array<int, string> $order
     */
    public function reorder(array $order): self
    {
        $wanted = [];

        foreach ($order as $entry) {
            $index = $this->indexOf((string) $entry);

            if ($index !== null && ! in_array($index, $wanted, true)) {
                $wanted[] = $index;
            }
        }

        $rest = array_diff(array_keys($this->entries), $wanted);

        $reordered = [];

        foreach ([...$wanted, ...$rest] as $index) {
            $reordered[] = $this->entries[$index];
        }

        $this->entries = $reordered;

        return $this;
    }

    /**
     * Drop later duplicates, keeping the first spelling and the first position.
     */
    public function deduplicate(): self
    {
        $seen = [];
        $out = [];

        foreach ($this->entries as $entry) {
            $key = self::key($entry);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $entry;
        }

        $this->entries = $out;

        return $this;
    }

    /**
     * The `-mod=` value, without the flag.
     *
     * A trailing semicolon is emitted because Arma's own launcher writes one
     * and several community start scripts split on it in a way that needs it.
     * An empty list renders as an empty string, not as `;`.
     */
    public function render(): string
    {
        return $this->entries === [] ? '' : implode(';', $this->entries) . ';';
    }

    public function renderFlag(string $flag = 'mod'): string
    {
        return $this->entries === [] ? '' : '-' . $flag . '=' . $this->render();
    }

    /**
     * Entries present here and missing from `$other`.
     *
     * Used to answer "what has SteamCMD not fetched yet" by comparing the load
     * order against the directory listing.
     *
     * @return array<int, string>
     */
    public function missingFrom(self $other): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (string $entry): bool => ! $other->has($entry),
        ));
    }

    /**
     * The directory name an entry resolves to.
     *
     * A load order may carry a path (`mods/@ace`) while the directory listing
     * only knows the leaf, so both sides are compared on the leaf.
     */
    public static function folder(string $entry): string
    {
        $trimmed = rtrim(str_replace('\\', '/', trim($entry)), '/');
        $position = strrpos($trimmed, '/');

        return $position === false ? $trimmed : substr($trimmed, $position + 1);
    }

    private static function key(string $entry): string
    {
        return strtolower(self::folder($entry));
    }
}
