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
     * The structure is copied from a launcher export rather than invented: the
     * launcher matches on `data-type` attributes and on the `arma:Type` meta,
     * and a file missing either is rejected with no explanation.
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
          <meta name="arma:Type" content="preset" />
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
