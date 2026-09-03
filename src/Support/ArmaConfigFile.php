<?php

namespace FyWolf\Arma3Manager\Support;

/**
 * An Arma config file (`server.cfg`, `basic.cfg`) that survives a round trip.
 *
 * Parsed into an ordered list of tagged chunks rather than a flat map. That one
 * decision is the whole reason unknown keys are safe: the file is never rebuilt
 * from a map, so comments, blank lines, ordering, `class` blocks and keys this
 * plugin has never heard of all survive untouched. Changing a value rewrites
 * that one chunk and nothing else moves.
 *
 * ## Why not a real parser
 *
 * Arma's config format is a small subset of the engine's raw-config syntax and
 * a full parser would be both larger and *worse here*, because a full parser
 * implies a full serialiser — and a serialiser rewrites the whole file. On a
 * live server that turns "the customer changed hostname" into a diff touching
 * every line, and any construct the parser did not model is silently dropped on
 * the way back out. A chunk that is never understood is a chunk that cannot be
 * corrupted.
 *
 * ## What it does understand
 *
 * Top-level assignments only:
 *
 *     hostname = "My Server";
 *     maxPlayers = 64;
 *     motd[] = {"one", "two"};
 *
 * `class Foo { … };` blocks are captured whole and passed through verbatim.
 * They are edited through `setBlock()`, which replaces the entire block — the
 * mission rotation is the only thing that needs it, and it owns that block
 * completely.
 *
 * ## Three traps this format sets
 *
 * - **A statement can span lines.** `motd[] = {` … `};` is idiomatic and very
 *   common. A line-oriented parser splits it and writes back a broken file, and
 *   a broken server.cfg is a server that does not boot. Chunks are found by
 *   scanning for the terminating `;` at brace depth zero, not by splitting on
 *   newlines.
 * - **`//` inside a string is not a comment.** `hostname = "http://x";` is
 *   ordinary. Comment detection therefore has to run inside the same scanner
 *   that tracks string state, not as a pre-pass.
 * - **Quotes are escaped by doubling, not by backslash.** `"He said ""hi"""`
 *   is one string. A backslash means nothing here, so treating `\"` as an
 *   escape mis-terminates the string and swallows the rest of the file.
 */
class ArmaConfigFile
{
    public const CHUNK_PAIR = 'pair';

    public const CHUNK_CLASS = 'class';

    public const CHUNK_RAW = 'raw';

    /**
     * @param array<int, array<string, mixed>> $chunks
     */
    private function __construct(private array $chunks) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function chunks(): array
    {
        return $this->chunks;
    }

    public static function parse(string $contents): self
    {
        // Normalise line endings for scanning; the writer re-emits \n.
        $source = str_replace(["\r\n", "\r"], "\n", $contents);

        $chunks = [];
        $length = strlen($source);
        $cursor = 0;

        while ($cursor < $length) {
            $start = $cursor;
            $char = $source[$cursor];

            // Whitespace runs and comments are passed through byte-for-byte.
            if ($char === "\n" || $char === ' ' || $char === "\t") {
                $cursor++;

                continue;
            }

            if ($char === '/' && $cursor + 1 < $length && $source[$cursor + 1] === '/') {
                $end = strpos($source, "\n", $cursor);
                $cursor = $end === false ? $length : $end;
                $chunks[] = ['type' => self::CHUNK_RAW, 'raw' => substr($source, $start, $cursor - $start)];

                continue;
            }

            if ($char === '/' && $cursor + 1 < $length && $source[$cursor + 1] === '*') {
                $end = strpos($source, '*/', $cursor + 2);
                $cursor = $end === false ? $length : $end + 2;
                $chunks[] = ['type' => self::CHUNK_RAW, 'raw' => substr($source, $start, $cursor - $start)];

                continue;
            }

            $statement = self::readStatement($source, $cursor);

            if ($statement === null) {
                // Nothing parseable left. Keep the remainder rather than
                // truncating the file — an unreadable tail is still the
                // customer's data.
                $chunks[] = ['type' => self::CHUNK_RAW, 'raw' => substr($source, $start)];

                break;
            }

            $chunks[] = $statement;
        }

        return new self(self::mergeRawRuns($chunks, $source));
    }

    /**
     * Read one top-level statement starting at `$cursor`, advancing it past the
     * statement's terminator.
     *
     * @return array<string, mixed>|null
     */
    private static function readStatement(string $source, int &$cursor): ?array
    {
        $length = strlen($source);
        $start = $cursor;

        // `class Name { … };`
        if (preg_match('/\Gclass\s+([A-Za-z_][A-Za-z0-9_]*)/', $source, $matches, 0, $cursor) === 1) {
            $name = $matches[1];
            $brace = strpos($source, '{', $cursor);

            if ($brace === false) {
                return null;
            }

            $end = self::matchBrace($source, $brace);

            if ($end === null) {
                return null;
            }

            $cursor = self::consumeTerminator($source, $end + 1);

            return [
                'type' => self::CHUNK_CLASS,
                'raw' => substr($source, $start, $cursor - $start),
                'name' => $name,
            ];
        }

        // `key = value;` or `key[] = {…};`
        if (preg_match('/\G([A-Za-z_][A-Za-z0-9_]*)\s*(\[\s*\])?\s*=\s*/', $source, $matches, 0, $cursor) !== 1) {
            return null;
        }

        $key = $matches[1];
        $isArray = isset($matches[2]);
        $valueStart = $cursor + strlen($matches[0]);
        $valueEnd = self::findStatementEnd($source, $valueStart);

        if ($valueEnd === null) {
            return null;
        }

        $rawValue = trim(substr($source, $valueStart, $valueEnd - $valueStart));
        $cursor = self::consumeTerminator($source, $valueEnd);

        [$value, $quoted] = self::decodeValue($rawValue, $isArray);

        return [
            'type' => self::CHUNK_PAIR,
            'raw' => substr($source, $start, $cursor - $start),
            'key' => $key,
            'is_array' => $isArray,
            'quoted' => $quoted,
            'value' => $value,
        ];
    }

    /**
     * Index of the `}` matching the `{` at `$open`, honouring strings and
     * comments so a brace inside either does not count.
     */
    private static function matchBrace(string $source, int $open): ?int
    {
        $length = strlen($source);
        $depth = 0;

        for ($i = $open; $i < $length; $i++) {
            $char = $source[$i];

            if ($char === '"') {
                $i = self::skipString($source, $i);

                continue;
            }

            if ($char === '/' && $i + 1 < $length && $source[$i + 1] === '/') {
                $end = strpos($source, "\n", $i);
                $i = $end === false ? $length : $end;

                continue;
            }

            if ($char === '/' && $i + 1 < $length && $source[$i + 1] === '*') {
                $end = strpos($source, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;

                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Index of the `;` ending a value that begins at `$from`, at brace depth
     * zero and outside any string.
     *
     * A value with no terminator at all is accepted and ends at the newline —
     * hand-written configs miss the semicolon often enough that refusing to
     * parse the file would be worse than tolerating it.
     */
    private static function findStatementEnd(string $source, int $from): ?int
    {
        $length = strlen($source);
        $depth = 0;

        for ($i = $from; $i < $length; $i++) {
            $char = $source[$i];

            if ($char === '"') {
                $i = self::skipString($source, $i);

                continue;
            }

            if ($char === '{') {
                $depth++;

                continue;
            }

            if ($char === '}') {
                $depth--;

                continue;
            }

            if ($char === ';' && $depth <= 0) {
                return $i;
            }

            if ($char === "\n" && $depth <= 0) {
                return $i;
            }
        }

        return $length;
    }

    /**
     * Index of the closing quote of the string opening at `$open`.
     *
     * Arma escapes a quote by doubling it, so `""` inside a string is a literal
     * quote and does *not* end it. Backslash has no meaning.
     */
    private static function skipString(string $source, int $open): int
    {
        $length = strlen($source);

        for ($i = $open + 1; $i < $length; $i++) {
            if ($source[$i] !== '"') {
                continue;
            }

            if ($i + 1 < $length && $source[$i + 1] === '"') {
                $i++;

                continue;
            }

            return $i;
        }

        return $length;
    }

    /**
     * Swallow the `;` and any trailing spaces plus one newline, so a rewritten
     * chunk keeps the line it lived on.
     */
    private static function consumeTerminator(string $source, int $from): int
    {
        $length = strlen($source);
        $i = $from;

        if ($i < $length && $source[$i] === ';') {
            $i++;
        }

        while ($i < $length && ($source[$i] === ' ' || $source[$i] === "\t")) {
            $i++;
        }

        if ($i < $length && $source[$i] === "\n") {
            $i++;
        }

        return $i;
    }

    /**
     * @return array{0: string|array<int, string>, 1: bool}
     */
    private static function decodeValue(string $raw, bool $isArray): array
    {
        if ($isArray || (str_starts_with($raw, '{') && str_ends_with($raw, '}'))) {
            return [self::decodeArray($raw), true];
        }

        if (strlen($raw) >= 2 && str_starts_with($raw, '"') && str_ends_with($raw, '"')) {
            return [str_replace('""', '"', substr($raw, 1, -1)), true];
        }

        return [$raw, false];
    }

    /**
     * @return array<int, string>
     */
    private static function decodeArray(string $raw): array
    {
        $inner = trim($raw);

        if (str_starts_with($inner, '{')) {
            $inner = substr($inner, 1);
        }

        if (str_ends_with($inner, '}')) {
            $inner = substr($inner, 0, -1);
        }

        $items = [];
        $buffer = '';
        $length = strlen($inner);
        $depth = 0;

        for ($i = 0; $i < $length; $i++) {
            $char = $inner[$i];

            if ($char === '"') {
                $end = self::skipString($inner, $i);
                $buffer .= substr($inner, $i, $end - $i + 1);
                $i = $end;

                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
            }

            if ($char === ',' && $depth === 0) {
                $items[] = $buffer;
                $buffer = '';

                continue;
            }

            $buffer .= $char;
        }

        if (trim($buffer) !== '') {
            $items[] = $buffer;
        }

        return array_values(array_map(static function (string $item): string {
            $item = trim($item);

            if (strlen($item) >= 2 && str_starts_with($item, '"') && str_ends_with($item, '"')) {
                return str_replace('""', '"', substr($item, 1, -1));
            }

            return $item;
        }, $items));
    }

    /**
     * Collapse consecutive raw chunks so the rendered file keeps the exact
     * whitespace between statements rather than normalising it away.
     *
     * @param array<int, array<string, mixed>> $chunks
     *
     * @return array<int, array<string, mixed>>
     */
    private static function mergeRawRuns(array $chunks, string $source): array
    {
        // The scanner skips whitespace without emitting it, so it has to be put
        // back: rebuild by walking the source and attributing every gap to the
        // raw chunk that precedes the next statement.
        $out = [];
        $offset = 0;

        foreach ($chunks as $chunk) {
            $raw = (string) $chunk['raw'];
            $position = strpos($source, $raw, $offset);

            if ($position === false) {
                $out[] = $chunk;

                continue;
            }

            if ($position > $offset) {
                $out[] = ['type' => self::CHUNK_RAW, 'raw' => substr($source, $offset, $position - $offset)];
            }

            $out[] = $chunk;
            $offset = $position + strlen($raw);
        }

        if ($offset < strlen($source)) {
            $out[] = ['type' => self::CHUNK_RAW, 'raw' => substr($source, $offset)];
        }

        return $out;
    }

    public function has(string $key): bool
    {
        foreach ($this->chunks as $chunk) {
            if ($chunk['type'] === self::CHUNK_PAIR && $chunk['key'] === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string|array<int, string>|null
     */
    public function get(string $key, string|array|null $default = null): string|array|null
    {
        // Last occurrence wins: the engine reads the file top to bottom and a
        // later assignment overrides an earlier one.
        $found = $default;

        foreach ($this->chunks as $chunk) {
            if ($chunk['type'] === self::CHUNK_PAIR && $chunk['key'] === $key) {
                $found = $chunk['value'];
            }
        }

        return $found;
    }

    /**
     * Every scalar key/value pair, in file order.
     *
     * Arrays are excluded on purpose — `all()` feeds the typed form and the
     * "other settings" passthrough, both of which are text inputs. Arrays are
     * read with `get()` by the fields that know they want one.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $out = [];

        foreach ($this->chunks as $chunk) {
            if ($chunk['type'] === self::CHUNK_PAIR && ! $chunk['is_array'] && is_string($chunk['value'])) {
                $out[$chunk['key']] = $chunk['value'];
            }
        }

        return $out;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function arrays(): array
    {
        $out = [];

        foreach ($this->chunks as $chunk) {
            if ($chunk['type'] === self::CHUNK_PAIR && is_array($chunk['value'])) {
                $out[$chunk['key']] = $chunk['value'];
            }
        }

        return $out;
    }

    /**
     * @return array<int, string>
     */
    public function classNames(): array
    {
        $out = [];

        foreach ($this->chunks as $chunk) {
            if ($chunk['type'] === self::CHUNK_CLASS) {
                $out[] = (string) $chunk['name'];
            }
        }

        return $out;
    }

    /**
     * Set a value, rewriting the existing statement in place if the key is
     * present and appending only if it is genuinely new.
     *
     * `$quoted` decides the rendering for a key that is *not* already in the
     * file. Null means "decide from the value": numbers bare, everything else
     * quoted. An existing key keeps the shape it already had, because that is
     * the shape the engine has been reading successfully.
     *
     * @param string|array<int, string> $value
     */
    public function set(string $key, string|array $value, ?bool $quoted = null): self
    {
        $seen = false;

        foreach ($this->chunks as $index => $chunk) {
            if ($chunk['type'] !== self::CHUNK_PAIR || $chunk['key'] !== $key) {
                continue;
            }

            if ($seen) {
                // A later duplicate would override the edit we just made, so it
                // has to go — but only after the first has been updated.
                unset($this->chunks[$index]);

                continue;
            }

            $isArray = is_array($value) ? true : (bool) $chunk['is_array'];

            $this->chunks[$index]['value'] = $value;
            $this->chunks[$index]['is_array'] = $isArray;
            $this->chunks[$index]['raw'] = self::renderPair($key, $value, $isArray, $quoted ?? (bool) $chunk['quoted']);
            $seen = true;
        }

        if (! $seen) {
            $isArray = is_array($value);

            $this->chunks[] = [
                'type' => self::CHUNK_PAIR,
                'raw' => self::renderPair($key, $value, $isArray, $quoted ?? ! self::looksNumeric($value)),
                'key' => $key,
                'is_array' => $isArray,
                'quoted' => $quoted ?? ! self::looksNumeric($value),
                'value' => $value,
            ];
        }

        $this->chunks = array_values($this->chunks);

        return $this;
    }

    /**
     * Replace a whole `class` block, or append one if it is absent.
     *
     * `$body` is the text between the braces and is written verbatim. The
     * mission rotation is the only caller: it owns `class Missions` entirely,
     * and rewriting the block wholesale is both simpler and safer than editing
     * inside it.
     */
    public function setBlock(string $name, string $body): self
    {
        $rendered = "class {$name}\n{\n" . rtrim($body, "\n") . "\n};\n";
        $seen = false;

        foreach ($this->chunks as $index => $chunk) {
            if ($chunk['type'] !== self::CHUNK_CLASS || $chunk['name'] !== $name) {
                continue;
            }

            if ($seen) {
                unset($this->chunks[$index]);

                continue;
            }

            $this->chunks[$index]['raw'] = $rendered;
            $seen = true;
        }

        if (! $seen) {
            $this->chunks[] = ['type' => self::CHUNK_CLASS, 'raw' => "\n" . $rendered, 'name' => $name];
        }

        $this->chunks = array_values($this->chunks);

        return $this;
    }

    public function forget(string $key): self
    {
        foreach ($this->chunks as $index => $chunk) {
            if ($chunk['type'] === self::CHUNK_PAIR && $chunk['key'] === $key) {
                unset($this->chunks[$index]);
            }
        }

        $this->chunks = array_values($this->chunks);

        return $this;
    }

    /**
     * @param array<string, string|array<int, string>> $values
     */
    public function merge(array $values): self
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }

        return $this;
    }

    public function render(): string
    {
        $out = '';

        foreach ($this->chunks as $chunk) {
            $out .= $chunk['raw'];
        }

        return rtrim($out, "\n") . "\n";
    }

    /**
     * Keys whose value differs from the supplied set.
     *
     * Used to log *which* settings changed without ever logging their values —
     * `password` and `passwordAdmin` live in this file.
     *
     * @param array<string, string|array<int, string>> $candidate
     *
     * @return array<int, string>
     */
    public function changedKeys(array $candidate): array
    {
        $changed = [];

        foreach ($candidate as $key => $value) {
            $current = $this->get($key);

            if ($current === null || self::normalise($current) !== self::normalise($value)) {
                $changed[] = $key;
            }
        }

        return $changed;
    }

    /**
     * @param string|array<int, string> $value
     */
    private static function normalise(string|array $value): string
    {
        return is_array($value) ? implode("\0", $value) : $value;
    }

    /**
     * @param string|array<int, string> $value
     */
    private static function looksNumeric(string|array $value): bool
    {
        return is_string($value) && $value !== '' && is_numeric($value);
    }

    /**
     * @param string|array<int, string> $value
     */
    private static function renderPair(string $key, string|array $value, bool $isArray, bool $quoted): string
    {
        if ($isArray || is_array($value)) {
            $items = array_map(
                static fn (string $item): string => self::looksNumeric($item) ? $item : '"' . str_replace('"', '""', $item) . '"',
                (array) $value,
            );

            return $key . '[] = {' . implode(', ', $items) . "};\n";
        }

        $rendered = $quoted
            ? '"' . str_replace('"', '""', (string) $value) . '"'
            : (string) $value;

        return $key . ' = ' . $rendered . ";\n";
    }
}
