<?php

require __DIR__ . '/../src/Support/WorkshopId.php';
require __DIR__ . '/../src/Support/LauncherPreset.php';

use FyWolf\Arma3Manager\Support\LauncherPreset;
use FyWolf\Arma3Manager\Support\WorkshopId;

$pass = 0;
$fail = 0;

function check(string $label, $actual, $expected): void
{
    global $pass, $fail;

    if ($actual === $expected) {
        $pass++;
        echo "  ok   $label\n";
    } else {
        $fail++;
        echo "  FAIL $label\n";
        echo '       expected: ' . var_export($expected, true) . "\n";
        echo '       actual:   ' . var_export($actual, true) . "\n";
    }
}

echo "WorkshopId extraction:\n";
check('a bare id passes through', WorkshopId::extract('450814997'), '450814997');
check('a sharedfiles URL resolves', WorkshopId::extract('https://steamcommunity.com/sharedfiles/filedetails/?id=450814997'), '450814997');
check('a workshop URL resolves', WorkshopId::extract('https://steamcommunity.com/workshop/filedetails/?id=463939057'), '463939057');
check('extra query parameters do not confuse it', WorkshopId::extract('https://steamcommunity.com/sharedfiles/filedetails/?id=450814997&searchtext=cba'), '450814997');
check('a steam:// link resolves', WorkshopId::extract('steam://url/CommunityFilePage/450814997'), '450814997');
check('surrounding whitespace is tolerated', WorkshopId::extract('  450814997  '), '450814997');
check('an empty string yields null', WorkshopId::extract(''), null);
check('null yields null', WorkshopId::extract(null), null);
check('a non-numeric string yields null', WorkshopId::extract('@CBA_A3'), null);
check('a URL with several numbers still finds the id parameter', WorkshopId::extract('https://x.test/12345/y?id=450814997&v=99999'), '450814997');
check('ambiguous free text with two numbers is refused', WorkshopId::extract('1234 and 5678'), null);
check('a lone number in free text is accepted', WorkshopId::extract('mod 450814997 please'), '450814997');
check('an all-zero id is refused', WorkshopId::extract('0000'), null);

echo "\nWorkshopId validation:\n";
check('a plain id is valid', WorkshopId::isValid('450814997'), true);
check('too short is invalid', WorkshopId::isValid('12'), false);
check('letters are invalid', WorkshopId::isValid('45081499x'), false);
check('empty is invalid', WorkshopId::isValid(''), false);
check('null is invalid', WorkshopId::isValid(null), false);
check('a 20-digit id is still valid', WorkshopId::isValid(str_repeat('9', 20)), true);
check('a very large id stays an exact string', WorkshopId::extract('18446744073709551615'), '18446744073709551615');

echo "\nWorkshopId bulk extraction:\n";
check('newline separated ids', WorkshopId::extractAll("450814997\n463939057"), ['450814997', '463939057']);
check('mixed URLs and ids', WorkshopId::extractAll("https://steamcommunity.com/sharedfiles/filedetails/?id=450814997 463939057"), ['450814997', '463939057']);
check('duplicates collapse', WorkshopId::extractAll('450814997 450814997'), ['450814997']);
check('order is preserved', WorkshopId::extractAll('2 463939057 450814997'), ['463939057', '450814997']);
check('an empty block yields nothing', WorkshopId::extractAll("  \n "), []);

echo "\nWorkshopId helpers:\n";
check('url() builds the page link', WorkshopId::url('450814997'), 'https://steamcommunity.com/sharedfiles/filedetails/?id=450814997');
check('contentPath() is id-based, not title-based', WorkshopId::contentPath('450814997', 107410), 'steamapps/workshop/content/107410/450814997');

// A cut-down but structurally faithful launcher export.
$preset = <<<'HTML'
<?xml version="1.0" encoding="utf-8"?>
<html>
 <head>
  <meta name="arma:Type" content="preset" />
  <meta name="arma:PresetName" content="Unit Night &amp; Ops" />
  <title>Arma 3 Preset</title>
 </head>
 <body>
  <div class="mod-list">
   <table>
    <tr data-type="ModContainer">
     <td data-type="DisplayName">CBA_A3</td>
     <td><span class="from-steam">Steam</span></td>
     <td><a href="https://steamcommunity.com/sharedfiles/filedetails/?id=450814997" data-type="Link">link</a></td>
    </tr>
    <tr data-type="ModContainer">
     <td data-type="DisplayName">ace &amp; friends</td>
     <td><span class="from-steam">Steam</span></td>
     <td><a href="https://steamcommunity.com/sharedfiles/filedetails/?id=463939057" data-type="Link">link</a></td>
    </tr>
    <tr data-type="ModContainer">
     <td data-type="DisplayName">A Local Mod</td>
     <td><span class="from-local">Local</span></td>
    </tr>
   </table>
  </div>
  <div class="dlc-list">
   <table>
    <tr data-type="DlcContainer">
     <td data-type="DisplayName">S.O.G. Prairie Fire</td>
     <td><a href="https://store.steampowered.com/app/1227700" data-type="Link">link</a></td>
    </tr>
   </table>
  </div>
 </body>
</html>
HTML;

$parsed = LauncherPreset::parse($preset);

echo "\nPreset parsing:\n";
check('the preset name is read and decoded', $parsed->name, 'Unit Night & Ops');
check('two workshop mods are found', count($parsed->mods()), 2);
check('ids are extracted in order', $parsed->ids(), ['450814997', '463939057']);
check('display names are decoded', $parsed->mods()[1]['name'], 'ace & friends');
check('a local mod with no link is skipped', in_array('A Local Mod', array_column($parsed->mods(), 'name'), true), false);
check('DLC is not imported as a mod', in_array('1227700', $parsed->ids(), true), false);
check('DLC is returned separately', $parsed->dlc, [['app_id' => '1227700', 'name' => 'S.O.G. Prairie Fire']]);

echo "\nPreset parsing edge cases:\n";
check('an empty document yields no mods', LauncherPreset::parse('')->ids(), []);
check('an empty document gets a fallback name', LauncherPreset::parse('')->name, 'Imported preset');
check('a document with no mod-list yields no mods', LauncherPreset::parse('<html><body>hi</body></html>')->ids(), []);
check('duplicate rows collapse', LauncherPreset::parse(
    '<div class="mod-list"><table>'
    . '<tr data-type="ModContainer"><td data-type="DisplayName">A</td><td><a href="?id=450814997"></a></td></tr>'
    . '<tr data-type="ModContainer"><td data-type="DisplayName">A again</td><td><a href="?id=450814997"></a></td></tr>'
    . '</table></div>',
)->ids(), ['450814997']);
check('a row with an unparseable link is skipped', LauncherPreset::parse(
    '<div class="mod-list"><table><tr data-type="ModContainer"><td data-type="DisplayName">X</td><td><a href="not-a-link"></a></td></tr></table></div>',
)->ids(), []);
check('single-quoted attributes parse', LauncherPreset::parse(
    "<div class='mod-list'><table><tr data-type='ModContainer'><td data-type='DisplayName'>Y</td><td><a href='?id=450814997'></a></td></tr></table></div>",
)->ids(), ['450814997']);
check('an unescaped ampersand in a name does not break the scan', LauncherPreset::parse(
    '<div class="mod-list"><table><tr data-type="ModContainer"><td data-type="DisplayName">Guns & Ammo</td><td><a href="?id=450814997"></a></td></tr></table></div>',
)->mods()[0]['name'], 'Guns & Ammo');
check('a name falls back to the id when absent', LauncherPreset::parse(
    '<div class="mod-list"><table><tr data-type="ModContainer"><td><a href="?id=450814997"></a></td></tr></table></div>',
)->mods()[0]['name'], '450814997');

echo "\nPreset rendering:\n";
$rendered = LauncherPreset::render('My Preset', [
    ['id' => '450814997', 'name' => 'CBA_A3'],
    ['id' => '463939057', 'name' => 'ace & friends'],
]);
check('the type meta is present', str_contains($rendered, 'name="arma:Type" content="preset"'), true);
check('the preset name is written', str_contains($rendered, 'content="My Preset"'), true);
check('a name is HTML-escaped on the way out', str_contains($rendered, 'ace &amp; friends'), true);
check('rows carry the container type', substr_count($rendered, 'data-type="ModContainer"'), 2);
check('an invalid id is dropped rather than written', substr_count(
    LauncherPreset::render('x', [['id' => 'nonsense', 'name' => 'X'], ['id' => '450814997', 'name' => 'Y']]),
    'data-type="ModContainer"',
), 1);

echo "\nRound trip:\n";
$again = LauncherPreset::parse($rendered);
check('a rendered preset reparses to the same ids', $again->ids(), ['450814997', '463939057']);
check('a rendered preset reparses to the same name', $again->name, 'My Preset');
check('an escaped name survives the round trip', LauncherPreset::parse(
    LauncherPreset::render('Night & Day', [['id' => '450814997', 'name' => 'A']]),
)->name, 'Night & Day');
check('an escaped mod name survives the round trip', $again->mods()[1]['name'], 'ace & friends');

echo "\n" . str_repeat('-', 40) . "\n";
echo "$pass passed, $fail failed\n";

exit($fail > 0 ? 1 : 0);
