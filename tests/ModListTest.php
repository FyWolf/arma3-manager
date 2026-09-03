<?php

require __DIR__ . '/../src/Support/ModList.php';

use FyWolf\Arma3Manager\Support\ModList;

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

echo "Parsing:\n";
check('a simple list parses in order', ModList::parse('@CBA_A3;@ace;@TFAR')->all(), ['@CBA_A3', '@ace', '@TFAR']);
check('a trailing semicolon does not add an empty entry', ModList::parse('@a;@b;')->all(), ['@a', '@b']);
check('repeated separators collapse', ModList::parse('@a;;;@b')->all(), ['@a', '@b']);
check('surrounding whitespace is trimmed', ModList::parse('  @a ; @b  ')->all(), ['@a', '@b']);
check('an empty string is an empty list', ModList::parse('')->all(), []);
check('null is an empty list', ModList::parse(null)->all(), []);
check('the -mod= flag is tolerated', ModList::parse('-mod=@a;@b')->all(), ['@a', '@b']);
check('the -serverMod= flag is tolerated', ModList::parse('-serverMod=@a')->all(), ['@a']);
check('a quoted whole value is unwrapped', ModList::parse('"@a;@b"')->all(), ['@a', '@b']);
check('commas are accepted as separators', ModList::parse('@a,@b')->all(), ['@a', '@b']);
check('paths are preserved', ModList::parse('mods/@ace;@CBA_A3')->all(), ['mods/@ace', '@CBA_A3']);

echo "\nDeduplication:\n";
check('an exact duplicate is dropped', ModList::parse('@a;@b;@a')->all(), ['@a', '@b']);
check('a case-different duplicate is dropped', ModList::parse('@CBA_A3;@cba_a3')->all(), ['@CBA_A3']);
check('the first spelling is the one kept', ModList::parse('@cba_a3;@CBA_A3')->all(), ['@cba_a3']);
check('a path duplicate of a bare name is dropped', ModList::parse('@ace;mods/@ace')->all(), ['@ace']);
check('the first position is kept', ModList::parse('@a;@b;@a;@c')->all(), ['@a', '@b', '@c']);

echo "\nMembership:\n";
$list = ModList::parse('@CBA_A3;@ace;@TFAR');
check('has() is case-insensitive', $list->has('@cba_a3'), true);
check('has() matches through a path', $list->has('mods/@ace'), true);
check('has() is false for an absent mod', $list->has('@nope'), false);
check('indexOf() returns the position', $list->indexOf('@ace'), 1);
check('indexOf() returns null when absent', $list->indexOf('@nope'), null);
check('count() counts', $list->count(), 3);
check('isEmpty() is false when populated', $list->isEmpty(), false);
check('isEmpty() is true when empty', ModList::parse('')->isEmpty(), true);

echo "\nAdding and removing:\n";
check('add appends', ModList::parse('@a')->add('@b')->all(), ['@a', '@b']);
check('add of an existing mod is a no-op', ModList::parse('@a;@b')->add('@a')->all(), ['@a', '@b']);
check('add does not reorder an existing mod', ModList::parse('@a;@b')->add('@a')->indexOf('@a'), 0);
check('add ignores an empty entry', ModList::parse('@a')->add('  ')->all(), ['@a']);
check('remove removes', ModList::parse('@a;@b;@c')->remove('@b')->all(), ['@a', '@c']);
check('remove is case-insensitive', ModList::parse('@CBA_A3')->remove('@cba_a3')->all(), []);
check('remove of an absent mod is a no-op', ModList::parse('@a')->remove('@z')->all(), ['@a']);

echo "\nOrdering:\n";
check('move to the front', ModList::parse('@a;@b;@c')->move('@c', 0)->all(), ['@c', '@a', '@b']);
check('move to the end', ModList::parse('@a;@b;@c')->move('@a', 2)->all(), ['@b', '@c', '@a']);
check('move to the middle', ModList::parse('@a;@b;@c')->move('@a', 1)->all(), ['@b', '@a', '@c']);
check('move past the end clamps', ModList::parse('@a;@b')->move('@a', 99)->all(), ['@b', '@a']);
check('move before the start clamps', ModList::parse('@a;@b')->move('@b', -5)->all(), ['@b', '@a']);
check('move to the same place is a no-op', ModList::parse('@a;@b')->move('@a', 0)->all(), ['@a', '@b']);
check('move of an absent mod is a no-op', ModList::parse('@a;@b')->move('@z', 0)->all(), ['@a', '@b']);

echo "\nReordering:\n";
check('reorder applies the given order', ModList::parse('@a;@b;@c')->reorder(['@c', '@b', '@a'])->all(), ['@c', '@b', '@a']);
check('an unmentioned mod is kept, not dropped', ModList::parse('@a;@b;@c')->reorder(['@c', '@a'])->all(), ['@c', '@a', '@b']);
check('an unknown name in the order is ignored', ModList::parse('@a;@b')->reorder(['@z', '@b'])->all(), ['@b', '@a']);
check('an empty order changes nothing', ModList::parse('@a;@b')->reorder([])->all(), ['@a', '@b']);
check('a duplicate in the order does not duplicate the mod', ModList::parse('@a;@b')->reorder(['@a', '@a'])->all(), ['@a', '@b']);

echo "\nRendering:\n";
check('renders semicolon separated with a trailing separator', ModList::parse('@a;@b')->render(), '@a;@b;');
check('an empty list renders as an empty string', ModList::parse('')->render(), '');
check('an empty list renders no flag', ModList::parse('')->renderFlag(), '');
check('renderFlag defaults to -mod', ModList::parse('@a')->renderFlag(), '-mod=@a;');
check('renderFlag takes the flag name', ModList::parse('@a')->renderFlag('serverMod'), '-serverMod=@a;');
check('a render round-trips', ModList::parse(ModList::parse('@a;@b;@c')->render())->all(), ['@a', '@b', '@c']);

echo "\nfromArray:\n";
check('builds from an array', ModList::fromArray(['@a', '@b'])->all(), ['@a', '@b']);
check('deduplicates on build', ModList::fromArray(['@a', '@A'])->all(), ['@a']);
check('trims quotes on build', ModList::fromArray(['"@a"'])->all(), ['@a']);

echo "\nComparing against disk:\n";
$wanted = ModList::parse('@CBA_A3;@ace;@TFAR');
$onDisk = ModList::parse('@cba_a3;@ace');
check('reports what is missing', $wanted->missingFrom($onDisk), ['@TFAR']);
check('case differences do not count as missing', ModList::parse('@CBA_A3')->missingFrom(ModList::parse('@cba_a3')), []);
check('nothing missing yields an empty list', $onDisk->missingFrom($wanted), []);

echo "\nFolder resolution:\n";
check('a bare name is its own folder', ModList::folder('@ace'), '@ace');
check('a path resolves to its leaf', ModList::folder('mods/@ace'), '@ace');
check('a backslash path resolves too', ModList::folder('mods\\@ace'), '@ace');
check('a trailing slash is ignored', ModList::folder('mods/@ace/'), '@ace');
check('a deep path resolves to the leaf', ModList::folder('a/b/c/@ace'), '@ace');

echo "\nWorkshop ids as entries:\n";
// The load order holds Workshop ids, because that is the only value the egg's
// install script can hand to SteamCMD. It held guessed @folder names once and
// downloaded nothing at all.
check('ids parse in order', ModList::parse('450814997;463939057')->all(), ['450814997', '463939057']);
check('a duplicate id is dropped', ModList::parse('450814997;450814997')->all(), ['450814997']);
check('ids keep their exact digits', ModList::parse('18446744073709551615')->all(), ['18446744073709551615']);
check('a large id is not truncated to an int', ModList::parse('18446744073709551615')->all()[0], '18446744073709551615');
check('has() finds an id', ModList::parse('450814997;463939057')->has('463939057'), true);
check('remove() drops an id', ModList::parse('450814997;463939057')->remove('450814997')->all(), ['463939057']);
check('order is preserved, because load order is meaning', ModList::parse('3;1;2')->all(), ['3', '1', '2']);

echo "\nrenderPlain — the value written to the egg variable:\n";
// A trailing separator is right for -mod= and wrong here: an install script
// doing `IFS=';' read -ra ids` on a value ending in ';' gets an empty final
// element and hands SteamCMD an empty id.
check('no trailing separator', ModList::parse('450814997;463939057')->renderPlain(), '450814997;463939057');
check('render() still keeps the trailing one for -mod=', ModList::parse('450814997;463939057')->render(), '450814997;463939057;');
check('an empty list renders empty', ModList::parse('')->renderPlain(), '');
check('a single id has no separator at all', ModList::parse('450814997')->renderPlain(), '450814997');
check('renderPlain round-trips', ModList::parse(ModList::parse('1111;2222;3333')->renderPlain())->all(), ['1111', '2222', '3333']);
check('splitting renderPlain on ; yields no empty element', array_filter(
    explode(';', ModList::parse('450814997;463939057')->renderPlain()),
    static fn (string $part): bool => $part === '',
), []);

echo "\n" . str_repeat('-', 40) . "\n";
echo "$pass passed, $fail failed\n";

exit($fail > 0 ? 1 : 0);
