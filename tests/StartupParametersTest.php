<?php

require __DIR__ . '/../src/Support/StartupParameters.php';

use FyWolf\Arma3Manager\Support\StartupParameters;

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

// The config() helper is not available outside a panel; these tests exercise
// parsing and rendering, which do not need it.

echo "Parsing:\n";
check('a bare flag becomes true', StartupParameters::parse('-autoInit')->get('autoInit'), true);
check('a valued flag keeps its value', StartupParameters::parse('-cpuCount=4')->get('cpuCount'), '4');
check('several flags parse', array_keys(StartupParameters::parse('-autoInit -cpuCount=4 -maxMem=8192')->all()), ['autoInit', 'cpuCount', 'maxMem']);
check('a quoted value with spaces survives', StartupParameters::parse('-name="My Server Profile"')->get('name'), 'My Server Profile');
check('an unquoted value stops at whitespace', StartupParameters::parse('-name=Server -autoInit')->get('name'), 'Server');
check('a trailing = is treated as a bare flag', StartupParameters::parse('-filePatching=')->get('filePatching'), true);
check('an empty string parses to nothing', StartupParameters::parse('')->all(), []);
check('null parses to nothing', StartupParameters::parse(null)->all(), []);
check('junk between flags is ignored', StartupParameters::parse('nonsense -autoInit more')->all(), ['autoInit' => true]);
check('a repeated flag keeps the last value', StartupParameters::parse('-cpuCount=2 -cpuCount=8')->get('cpuCount'), '8');
check('a path value with slashes survives', StartupParameters::parse('-profiles=/home/container/profiles')->get('profiles'), '/home/container/profiles');
check('a mod list value survives intact', StartupParameters::parse('-mod=@CBA_A3;@ace;')->get('mod'), '@CBA_A3;@ace;');

echo "\nMembership:\n";
$flags = StartupParameters::parse('-autoInit -cpuCount=4');
check('has() finds a bare flag', $flags->has('autoInit'), true);
check('has() finds a valued flag', $flags->has('cpuCount'), true);
check('has() is false for an absent flag', $flags->has('nope'), false);
check('get() returns null when absent', $flags->get('nope'), null);

echo "\nEditing:\n";
check('set adds a valued flag', StartupParameters::parse('')->set('cpuCount', '8')->get('cpuCount'), '8');
check('set adds a bare flag', StartupParameters::parse('')->set('autoInit', true)->get('autoInit'), true);
check('set to false removes the flag', StartupParameters::parse('-autoInit')->set('autoInit', false)->has('autoInit'), false);
check('set to an empty string removes the flag', StartupParameters::parse('-cpuCount=4')->set('cpuCount', '')->has('cpuCount'), false);
check('set replaces an existing value', StartupParameters::parse('-cpuCount=2')->set('cpuCount', '16')->get('cpuCount'), '16');
check('forget removes', StartupParameters::parse('-autoInit -cpuCount=4')->forget('autoInit')->has('autoInit'), false);
check('forget leaves the rest', StartupParameters::parse('-autoInit -cpuCount=4')->forget('autoInit')->get('cpuCount'), '4');

echo "\nRendering:\n";
check('a bare flag renders bare', StartupParameters::parse('-autoInit')->render(), '-autoInit');
check('a valued flag renders with =', StartupParameters::parse('-cpuCount=4')->render(), '-cpuCount=4');
check('a value with a space is quoted', StartupParameters::parse('-name="My Server"')->render(), '-name="My Server"');
check('a plain value is not quoted', StartupParameters::parse('-name=Server')->render(), '-name=Server');
check('an empty set renders empty', StartupParameters::parse('')->render(), '');
check('flags are space separated', StartupParameters::parse('-autoInit -cpuCount=4')->render(), '-autoInit -cpuCount=4');

echo "\nRound trip:\n";
$original = '-autoInit -cpuCount=4 -maxMem=8192 -name="Night Ops" -filePatching';
$round = StartupParameters::parse(StartupParameters::parse($original)->render());
check('a round trip keeps every flag', array_keys($round->all()), ['autoInit', 'cpuCount', 'maxMem', 'name', 'filePatching']);
check('a round trip keeps a quoted value', $round->get('name'), 'Night Ops');
check('a round trip keeps a bare flag bare', $round->get('filePatching'), true);
check('a second round trip is stable', StartupParameters::parse($original)->render(), StartupParameters::parse(StartupParameters::parse($original)->render())->render());

echo "\n" . str_repeat('-', 40) . "\n";
echo "$pass passed, $fail failed\n";

exit($fail > 0 ? 1 : 0);
