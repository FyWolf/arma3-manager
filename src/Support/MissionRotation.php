<?php

namespace FyWolf\Arma3Manager\Support;

/**
 * The `class Missions` block in server.cfg.
 *
 * Arma's mission rotation is not a list, it is a sequence of nested classes:
 *
 *     class Missions
 *     {
 *         class Mission_1
 *         {
 *             template = "MyMission.Altis";
 *             difficulty = "regular";
 *         };
 *     };
 *
 * The class *names* are arbitrary and the engine ignores them; the order is
 * what decides the rotation. Which is exactly why this renders the whole block
 * from a list rather than editing inside it — reordering by rewriting class
 * names in place is how a rotation ends up with two `Mission_2` entries and one
 * of them silently never running.
 *
 * The template value is the mission file name **without** the `.pbo` extension.
 * Getting that wrong is the single most common Arma server misconfiguration:
 * `template = "MyMission.Altis.pbo"` parses fine, boots fine, and then the
 * server sits in the lobby forever because no such mission exists.
 */
class MissionRotation
{
    /**
     * @param array<int, array{template: string, difficulty?: string}> $entries
     */
    public function __construct(private array $entries = []) {}

    /**
     * @return array<int, array{template: string, difficulty: string}>
     */
    public function all(): array
    {
        return array_map(
            static fn (array $entry): array => [
                'template' => (string) $entry['template'],
                'difficulty' => (string) ($entry['difficulty'] ?? 'regular'),
            ],
            $this->entries,
        );
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * Strip the extension a customer will paste in anyway.
     *
     * `MyMission.Altis.pbo` and `MyMission.Altis` must produce the same
     * template, because the file browser shows the first and the config wants
     * the second.
     */
    public static function template(string $filename): string
    {
        $name = basename(trim($filename));

        return preg_replace('/\.pbo$/i', '', $name) ?? $name;
    }

    /**
     * @param array<int, array{template: string, difficulty?: string}> $entries
     */
    public static function fromArray(array $entries): self
    {
        $clean = [];

        foreach ($entries as $entry) {
            $template = self::template((string) ($entry['template'] ?? ''));

            if ($template === '') {
                continue;
            }

            $clean[] = [
                'template' => $template,
                'difficulty' => (string) ($entry['difficulty'] ?? 'regular'),
            ];
        }

        return new self($clean);
    }

    /**
     * Read the rotation back out of a rendered block.
     *
     * Only the fields this class writes are recovered. A block somebody hand
     * edited to carry extra keys will lose them on the next save, which is why
     * the Missions page says so before it saves.
     */
    public static function parse(string $block): self
    {
        if (preg_match_all('/class\s+[A-Za-z0-9_]+\s*\{(.*?)\}\s*;/is', $block, $matches) === false) {
            return new self([]);
        }

        $entries = [];

        foreach ($matches[1] as $body) {
            if (preg_match('/template\s*=\s*"(.*?)"\s*;/is', $body, $template) !== 1) {
                continue;
            }

            $difficulty = 'regular';

            if (preg_match('/difficulty\s*=\s*"(.*?)"\s*;/is', $body, $found) === 1) {
                $difficulty = $found[1];
            }

            $entries[] = [
                'template' => str_replace('""', '"', $template[1]),
                'difficulty' => str_replace('""', '"', $difficulty),
            ];
        }

        return new self($entries);
    }

    /**
     * The block body, ready for `ArmaConfigFile::setBlock('Missions', …)`.
     *
     * An empty rotation renders a comment rather than nothing: an empty
     * `class Missions {};` is valid and means "no rotation", and leaving a bare
     * pair of braces makes it look as though something failed to write.
     */
    public function render(): string
    {
        if ($this->entries === []) {
            return '    // No mission rotation configured.';
        }

        $out = [];
        $index = 1;

        foreach ($this->all() as $entry) {
            $template = str_replace('"', '""', $entry['template']);
            $difficulty = str_replace('"', '""', $entry['difficulty']);

            $out[] = "    class Mission_{$index}\n    {\n"
                . "        template = \"{$template}\";\n"
                . "        difficulty = \"{$difficulty}\";\n"
                . "    };";

            $index++;
        }

        return implode("\n", $out);
    }
}
