<?php

require __DIR__ . '/../src/Support/WorkshopId.php';
require __DIR__ . '/../src/Support/SteamAcf.php';

use FyWolf\Arma3Manager\Support\SteamAcf;

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

/**
 * A realistic ACF: the shape SteamCMD actually writes, with the same item in
 * both blocks and tab indentation.
 */
$acf = <<<'ACF'
"AppWorkshop"
{
	"appid"		"107410"
	"SizeOnDisk"		"12345678"
	"NeedsUpdate"		"0"
	"NeedsDownload"		"0"
	"TimeLastUpdated"		"1699999999"
	"WorkshopItemsInstalled"
	{
		"450814997"
		{
			"manifest"		"1111111111"
			"size"		"231334"
			"timeupdated"		"1699999998"
		}
		"463939057"
		{
			"manifest"		"2222222222"
			"size"		"8388608"
			"timeupdated"		"1699999997"
		}
	}
	"WorkshopItemDetails"
	{
		"450814997"
		{
			"manifest"		"1111111111"
			"timeupdated"		"1699999998"
			"timetouched"		"1699999999"
		}
		"463939057"
		{
			"manifest"		"2222222222"
			"timeupdated"		"1699999997"
		}
	}
}
ACF;

echo "Finding an item:\n";
check('an installed item is found', SteamAcf::hasItem($acf, '450814997'), true);
check('the other installed item is found', SteamAcf::hasItem($acf, '463939057'), true);
check('an absent item is not', SteamAcf::hasItem($acf, '999999999'), false);
// The app id appears as a *value*. Matching it as a key would delete a block.
check('the app id is not mistaken for an item', SteamAcf::hasItem($acf, '107410'), false);
// So does a manifest id.
check('a manifest id is not mistaken for an item', SteamAcf::hasItem($acf, '1111111111'), false);

echo "\nRemoving an item:\n";
$stripped = SteamAcf::withoutItem($acf, '450814997');

check('the result is not a refusal', is_string($stripped), true);
check('the item is gone', SteamAcf::hasItem($stripped, '450814997'), false);
// Removing one mod must not disturb any other. This is the assertion that
// stands between "reinstall one mod" and "re-download the entire set".
check('the other item survives', SteamAcf::hasItem($stripped, '463939057'), true);
check('both of its blocks went', substr_count($stripped, '"450814997"'), 0);
check('the survivor keeps both of its blocks', substr_count($stripped, '"463939057"'), 2);
check('its manifest went with it', str_contains($stripped, '1111111111'), false);
check('the survivor keeps its manifest', str_contains($stripped, '2222222222'), true);

echo "\nThe file is still a file:\n";
check('the header survives', str_contains($stripped, '"appid"'), true);
check('both container blocks survive', str_contains($stripped, '"WorkshopItemsInstalled"')
    && str_contains($stripped, '"WorkshopItemDetails"'), true);
check('braces stay balanced', substr_count($stripped, '{') === substr_count($stripped, '}'), true);
check('no ragged blank line is left', str_contains($stripped, "\n\n"), false);

echo "\nRemoving the last item:\n";
$emptied = SteamAcf::withoutItem(SteamAcf::withoutItem($acf, '450814997'), '463939057');
check('emptying both blocks is allowed', is_string($emptied), true);
check('nothing is left listed', SteamAcf::hasItem($emptied, '463939057'), false);
check('the blocks themselves remain', str_contains($emptied, '"WorkshopItemsInstalled"'), true);
check('still balanced', substr_count($emptied, '{') === substr_count($emptied, '}'), true);

echo "\nNothing to do:\n";
check('an absent id returns the file unchanged', SteamAcf::withoutItem($acf, '999999999'), $acf);

echo "\nRefusals — these must never write:\n";
// A non-numeric id could match a structural key and take the whole block.
check('a non-numeric id', SteamAcf::withoutItem($acf, 'WorkshopItemsInstalled'), null);
check('an empty id', SteamAcf::withoutItem($acf, ''), null);
check('an id with a quote in it', SteamAcf::withoutItem($acf, '450814997"'), null);
check('an empty file', SteamAcf::withoutItem('', '450814997'), null);
check('whitespace only', SteamAcf::withoutItem("  \n\t ", '450814997'), null);
// Already-broken input is refused rather than "repaired" into something worse.
check('an unbalanced file', SteamAcf::withoutItem('"AppWorkshop" { "a" { ', '450814997'), null);
check('a file with a stray close brace', SteamAcf::withoutItem('"AppWorkshop" { } }', '450814997'), null);
check('an unterminated quote', SteamAcf::withoutItem('"AppWorkshop" { "450814997" { } ', '450814997'), null);

echo "\nBraces inside quoted values:\n";
// A mod title containing a brace would throw off every offset after it if the
// scanner did not know it was inside a string.
$tricky = <<<'ACF'
"AppWorkshop"
{
	"WorkshopItemDetails"
	{
		"450814997"
		{
			"title"		"A mod with { a brace"
		}
		"463939057"
		{
			"title"		"and } another"
		}
	}
}
ACF;
$out = SteamAcf::withoutItem($tricky, '450814997');
check('a braced title does not derail the cut', is_string($out), true);
check('the right item went', $out !== null && ! SteamAcf::hasItem($out, '450814997'), true);
check('the braced neighbour survives', $out !== null && SteamAcf::hasItem($out, '463939057'), true);
check('its title is intact', $out !== null && str_contains($out, 'and } another'), true);

echo "\nCRLF files:\n";
$crlf = str_replace("\n", "\r\n", $acf);
$strippedCrlf = SteamAcf::withoutItem($crlf, '450814997');
check('a CRLF file is handled', is_string($strippedCrlf), true);
check('the item is gone', $strippedCrlf !== null && ! SteamAcf::hasItem($strippedCrlf, '450814997'), true);
check('the neighbour survives', $strippedCrlf !== null && SteamAcf::hasItem($strippedCrlf, '463939057'), true);

echo "\n" . str_repeat('-', 40) . "\n";
echo "$pass passed, $fail failed\n";

exit($fail > 0 ? 1 : 0);
