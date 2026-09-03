<?php

namespace FyWolf\Arma3Manager\Support;

/**
 * An Arma 3 Launcher HTML preset.
 *
 * This is the format the official launcher exports from *Mods → Preset → Export*
 * and the one every Arma community already passes around, so importing it is
 * the difference between "type in forty workshop ids" and "drop the file your
 * unit already published". Exporting it matters just as much in the other
 * direction: a headless client, or a player who wants to match the server, can
 * load the exported file straight into their own launcher.
 *
 * ## It is HTML, and it is not parsed as HTML
 *
 * The file claims to be XHTML and frequently is not: mod names contain
 * ampersands and angle brackets that the launcher does not escape, which makes
 * a strict XML parse fail on a file the real launcher reads happily. Feeding it
 * to DOMDocument in HTML mode works but drags in libxml error handling and a
 * dependency this plugin otherwise does not need.
 *
 * So it is scanned with anchored regular expressions over the container rows.
 * The structure is fixed — the launcher generates it, not a human — and the
 * only field taken from it is the workshop id, which is then validated as a
 * number. A malformed name is cosmetic; a malformed id is refused.
 *
 * ## The DLC list is separate and is not a mod list
 *
 * Creator DLC appear in their own `dlc-list` table keyed by Steam *app* id, not
 * by published-file id. They are owned rather than downloaded, so importing one
 * as a workshop item would queue a download that can never succeed. They are
 * returned separately and the caller decides.
 */
class LauncherPreset
{
    /**
     * @param array<int, array{id: string, name: string}> $mods
     * @param array<int, array{app_id: string, name: string}> $dlc
     */
    private function __construct(
        public readonly string $name,
        public readonly array $mods,
        public readonly array $dlc,
    ) {}

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function mods(): array
    {
        return $this->mods;
    }

    /**
     * @return array<int, string>
     */
    public function ids(): array
    {
        return array_column($this->mods, 'id');
    }

    /**
     * The largest file this will look at, in bytes.
     *
     * Checked *before* any pattern runs, which is the point of it. Every
     * extraction below uses lazy quantifiers over the whole document, and PCRE
     * on a multi-megabyte input is where a "just paste your preset" feature
     * turns into a way to occupy a web worker. A real launcher preset for a
     * 400-mod modset is well under 200 KB.
     */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /**
     * The most mods one preset may declare.
     *
     * Each one becomes a Workshop metadata lookup, batched 100 at a time, so an
     * absurd preset is an absurd number of requests to Steam under this panel's
     * IP. The largest published Arma collections are in the low hundreds.
     */
    public const MAX_MODS = 500;

    /**
     * Read a preset out of an uploaded file, refusing anything that is not one.
     *
     * ## What actually protects this, and what does not
     *
     * Being honest about the difference matters, because the obvious-looking
     * check is the weakest one here.
     *
     * The **real** defences are structural:
     *
     * - **The file is never rendered.** Not in a Blade view, not in a
     *   notification, not in an iframe. It is pattern-matched and thrown away,
     *   so a `<script>` in it has nowhere to run. This is why the feature is
     *   safe, and it is a property to preserve rather than a check to add.
     * - **No XML parser touches it.** `DOMDocument`/`SimpleXML` would bring
     *   entity expansion with them — billion laughs, and XXE reading files off
     *   the panel. Presets *claim* to be XHTML, so reaching for an XML parser
     *   is the natural mistake. Regex over a byte string cannot expand an
     *   entity.
     * - **Only `\d{4,20}` escapes the parser.** Workshop ids are validated as
     *   digits before they are used, and folder names are derived from Steam's
     *   response rather than from the file, so nothing attacker-controlled
     *   reaches a path.
     * - **The size cap runs first**, bounding the work every pattern does.
     *
     * The `arma:Type` marker check below is **not** a security control —
     * anyone can put that meta tag in a file. It stops a customer uploading
     * their bank statement and getting a confusing error, which is a different
     * and equally worthwhile job.
     *
     * @throws InvalidPresetException with a message meant for the customer
     */
    public static function fromFile(string $contents): self
    {
        $length = strlen($contents);

        if ($length === 0) {
            throw new InvalidPresetException('That file is empty.');
        }

        // First, and before any pattern runs.
        if ($length > self::MAX_BYTES) {
            throw new InvalidPresetException(sprintf(
                'That file is %s and the limit is %s. A launcher preset is normally well under 200 KB, so this is probably not one.',
                self::bytesForHumans($length),
                self::bytesForHumans(self::MAX_BYTES),
            ));
        }

        // A NUL byte means this is not text at all — an archive or an image
        // renamed to .html. Checked separately from the encoding test because
        // it is the cheaper and more definite of the two.
        if (str_contains($contents, "\0")) {
            throw new InvalidPresetException('That is a binary file, not an HTML preset.');
        }

        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw new InvalidPresetException('That file is not valid UTF-8 text. Export the preset again from the Arma 3 Launcher rather than editing it by hand.');
        }

        if (! self::looksLikePreset($contents)) {
            throw new InvalidPresetException('That does not look like an Arma 3 Launcher preset. Export one with Mods → Preset → Export and upload the .html file it writes.');
        }

        $preset = self::parse($contents);

        if ($preset->mods === []) {
            throw new InvalidPresetException('That preset lists no Steam Workshop mods. Local-only mods carry no Workshop link, so there is nothing here that could be downloaded.');
        }

        if (count($preset->mods) > self::MAX_MODS) {
            throw new InvalidPresetException(sprintf(
                'That preset lists %d mods and the limit is %d.',
                count($preset->mods),
                self::MAX_MODS,
            ));
        }

        return $preset;
    }

    /**
     * Whether this is plausibly a launcher export.
     *
     * ## The marker value is `list`, not `preset`
     *
     * A real export from the Arma 3 Launcher carries:
     *
     *     <meta name="arma:Type" content="list" />
     *
     * An earlier version of this method required `content="preset"`, which is a
     * value the launcher never writes — it was inferred from the feature's name
     * rather than from a file. Only the `mod-list` fallback below stopped every
     * genuine preset being refused, which is exactly the kind of near-miss that
     * makes a second check worth having.
     *
     * So the content is no longer inspected at all: the *presence* of an
     * `arma:Type` meta is the signal, whatever it says. That costs nothing,
     * because this check has never been a security control — anyone can write
     * that tag. It exists so somebody who picks the wrong file out of their
     * downloads folder gets told so.
     */
    private static function looksLikePreset(string $html): bool
    {
        if (preg_match('/<meta\s+name=(["\'])arma:Type\1/i', $html) === 1) {
            return true;
        }

        return preg_match('/class=(["\'])[^"\']*\bmod-list\b[^"\']*\1/i', $html) === 1;
    }

    private static function bytesForHumans(int $bytes): string
    {
        $units = ['B', 'KB', 'MB'];
        $power = min((int) floor(log(max($bytes, 1), 1024)), count($units) - 1);

        return round($bytes / (1024 ** $power), $power > 1 ? 1 : 0) . ' ' . $units[$power];
    }

    public static function parse(string $html): self
    {
        return new self(
            self::presetName($html),
            self::modRows($html),
            self::dlcRows($html),
        );
    }

    private static function presetName(string $html): string
    {
        if (preg_match('/<meta\s+name=(["\'])arma:PresetName\1\s+content=(["\'])(.*?)\2/is', $html, $matches) === 1) {
            return self::decode($matches[3]);
        }

        return 'Imported preset';
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    private static function modRows(string $html): array
    {
        // Only rows inside the mod list. A preset also contains a dlc-list
        // whose rows use the same ModContainer type, and mixing the two would
        // queue a workshop download for an app id.
        $section = self::section($html, 'mod-list');

        if ($section === null) {
            return [];
        }

        if (preg_match_all('/<tr[^>]*data-type=(["\'])ModContainer\1[^>]*>(.*?)<\/tr>/is', $section, $matches) === false) {
            return [];
        }

        $mods = [];
        $seen = [];

        foreach ($matches[2] as $row) {
            $link = '';

            if (preg_match('/<a[^>]+href=(["\'])(.*?)\1/is', $row, $anchor) === 1) {
                $link = $anchor[2];
            }

            $id = WorkshopId::extract(self::decode($link));

            // A row with no resolvable workshop id is a local mod the exporter
            // included by name only. It cannot be downloaded, so it is skipped
            // rather than imported as an unfetchable entry.
            if ($id === null || isset($seen[$id])) {
                continue;
            }

            $name = '';

            if (preg_match('/<td[^>]*data-type=(["\'])DisplayName\1[^>]*>(.*?)<\/td>/is', $row, $display) === 1) {
                $name = trim(self::decode(strip_tags($display[2])));
            }

            $seen[$id] = true;
            $mods[] = ['id' => $id, 'name' => $name !== '' ? $name : $id];
        }

        return $mods;
    }

    /**
     * @return array<int, array{app_id: string, name: string}>
     */
    private static function dlcRows(string $html): array
    {
        $section = self::section($html, 'dlc-list');

        if ($section === null) {
            return [];
        }

        if (preg_match_all('/<tr[^>]*data-type=(["\'])DlcContainer\1[^>]*>(.*?)<\/tr>/is', $section, $matches) === false) {
            return [];
        }

        $dlc = [];

        foreach ($matches[2] as $row) {
            $appId = '';

            if (preg_match('/<a[^>]+href=(["\'])(.*?)\1/is', $row, $anchor) === 1
                && preg_match('#/app/(\d+)#i', self::decode($anchor[2]), $app) === 1) {
                $appId = $app[1];
            }

            $name = '';

            if (preg_match('/<td[^>]*data-type=(["\'])DisplayName\1[^>]*>(.*?)<\/td>/is', $row, $display) === 1) {
                $name = trim(self::decode(strip_tags($display[2])));
            }

            if ($appId !== '') {
                $dlc[] = ['app_id' => $appId, 'name' => $name !== '' ? $name : $appId];
            }
        }

        return $dlc;
    }

    /**
     * The inner HTML of the first `<div class="…">` whose class list contains
     * `$class`, up to the end of its table.
     *
     * Bounded by `</table>` rather than by a matching `</div>`, because nested
     * divs would need a real parser and the launcher always wraps exactly one
     * table per list.
     */
    private static function section(string $html, string $class): ?string
    {
        if (preg_match('/<div[^>]*class=(["\'])[^"\']*\b' . preg_quote($class, '/') . '\b[^"\']*\1[^>]*>(.*?)<\/table>/is', $html, $matches) === 1) {
            return $matches[2];
        }

        return null;
    }

    private static function decode(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Render a preset the official launcher will import.
     *
     * The structure is copied from a real launcher export rather than invented,
     * including `arma:Type` being **`list`** — the launcher matches on that meta
     * and on the `data-type` attributes, and a file carrying the wrong type may
     * not import. This wrote `preset` until a real export was compared against
     * it.
     *
     * @param array<int, array{id: string, name: string}> $mods
     */
    public static function render(string $name, array $mods): string
    {
        $rows = '';

        foreach ($mods as $mod) {
            $id = (string) ($mod['id'] ?? '');

            if (! WorkshopId::isValid($id)) {
                continue;
            }

            $url = self::escape(WorkshopId::url($id));
            $label = self::escape((string) ($mod['name'] ?? $id));

            $rows .= <<<HTML
     <tr data-type="ModContainer">
      <td data-type="DisplayName">{$label}</td>
      <td>
       <span class="from-steam">Steam</span>
      </td>
      <td>
       <a href="{$url}" data-type="Link">{$url}</a>
      </td>
     </tr>

    HTML;
        }

        $presetName = self::escape($name);
        $generated = date('c');

        return <<<HTML
        <?xml version="1.0" encoding="utf-8"?>
        <html>
         <!--Created by Arma 3 Manager on {$generated}-->
         <head>
          <meta name="arma:Type" content="list" />
          <meta name="arma:PresetName" content="{$presetName}" />
          <meta name="generator" content="Arma 3 Manager" />
          <title>Arma 3 Preset {$presetName}</title>
         </head>
         <body>
          <h1>Arma 3 Preset <strong>{$presetName}</strong></h1>
          <p>Drag this file onto the Arma 3 Launcher, or use Mods &gt; Preset &gt; Import.</p>
          <div class="mod-list">
           <table>
        {$rows}   </table>
          </div>
         </body>
        </html>

        HTML;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
