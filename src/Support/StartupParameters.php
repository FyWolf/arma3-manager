<?php

namespace FyWolf\Arma3Manager\Support;

/**
 * The extra Arma command-line flags a customer may set.
 *
 * Arma's command line is not getopt. Flags are `-name`, `-name=value` or
 * `-name="value with spaces"`, there is no `--`, and an unknown flag is ignored
 * in silence rather than refused — which is why a typo here has no symptom at
 * all beyond the setting not taking effect.
 *
 * ## Managed flags are parsed and never re-emitted
 *
 * `-port`, `-config`, `-mod` and friends belong to the panel and to the other
 * pages in this plugin. They are recognised on the way in so they can be *shown*
 * as read-only, and dropped on the way out so a customer cannot set a second
 * `-mod=` that silently replaces the load order the Mods page manages.
 *
 * That is the whole reason this is a class rather than a text field: a free-text
 * startup box lets a customer append `-mod=@whatever` and quietly override every
 * other page.
 */
class StartupParameters
{
    /**
     * @param array<string, string|true> $flags Value, or true for a bare flag.
     */
    private function __construct(private array $flags) {}

    public static function parse(?string $raw): self
    {
        $value = trim((string) $raw);

        if ($value === '') {
            return new self([]);
        }

        $flags = [];

        // Matches -name, -name=value and -name="quoted value". The quoted
        // alternative comes first: without it the unquoted branch stops at the
        // first space and truncates every path containing one.
        $pattern = '/-([A-Za-z][A-Za-z0-9_]*)(?:=(?:"([^"]*)"|(\S*)))?/';

        if (preg_match_all($pattern, $value, $matches, PREG_SET_ORDER) === false) {
            return new self([]);
        }

        foreach ($matches as $match) {
            $name = $match[1];

            if (! isset($match[2]) && ! isset($match[3])) {
                $flags[$name] = true;

                continue;
            }

            $flagValue = $match[2] !== '' ? $match[2] : ($match[3] ?? '');

            // `-flag=` with nothing after it is a bare flag as far as Arma is
            // concerned, and re-emitting the trailing `=` is how a value that
            // was cleared turns into an empty string the engine then parses.
            $flags[$name] = $flagValue === '' ? true : $flagValue;
        }

        return new self($flags);
    }

    /**
     * @return array<string, string|true>
     */
    public function all(): array
    {
        return $this->flags;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->flags);
    }

    public function get(string $name): string|true|null
    {
        return $this->flags[$name] ?? null;
    }

    public function set(string $name, string|bool $value): self
    {
        if ($value === false || $value === '') {
            unset($this->flags[$name]);

            return $this;
        }

        $this->flags[$name] = $value === true ? true : (string) $value;

        return $this;
    }

    public function forget(string $name): self
    {
        unset($this->flags[$name]);

        return $this;
    }

    /**
     * Flags the panel owns, which this class shows and never writes.
     *
     * @return array<int, string>
     */
    public static function managed(): array
    {
        return array_map('strval', (array) config('arma3-manager.parameters.managed', []));
    }

    public function isManaged(string $name): bool
    {
        foreach (self::managed() as $managed) {
            if (strcasecmp($managed, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Everything the customer is allowed to change.
     *
     * @return array<string, string|true>
     */
    public function customisable(): array
    {
        return array_filter(
            $this->flags,
            fn (string $name): bool => ! $this->isManaged($name),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Drop every managed flag, so a save cannot introduce one.
     */
    public function withoutManaged(): self
    {
        return new self($this->customisable());
    }

    /**
     * Render back to a command-line fragment.
     *
     * Values are quoted when they contain anything that would end the argument.
     * Over-quoting is harmless to Arma and under-quoting truncates a path, so
     * the bias is deliberate.
     */
    public function render(): string
    {
        $parts = [];

        foreach ($this->flags as $name => $value) {
            if ($value === true) {
                $parts[] = '-' . $name;

                continue;
            }

            $parts[] = preg_match('/[\s"]/', (string) $value) === 1
                ? '-' . $name . '="' . str_replace('"', '', (string) $value) . '"'
                : '-' . $name . '=' . $value;
        }

        return implode(' ', $parts);
    }
}
