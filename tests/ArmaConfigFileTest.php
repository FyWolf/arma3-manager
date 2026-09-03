<?php

require __DIR__ . '/../src/Support/ArmaConfigFile.php';

use FyWolf\Arma3Manager\Support\ArmaConfigFile;

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

// A realistic, nasty server.cfg. Every awkward construct here is one that
// occurs in configs people actually run.
$original = <<<'CFG'
// Hexalabs Arma 3 server
/* block comment
   spanning lines */

hostname = "Test Server";
password = "";
passwordAdmin = "sec""ret";
maxPlayers = 64;

motd[] = {
    "Welcome to the server",
    "Rules: https://example.test/rules"
};

admins[] = {"76561198000000000", "76561198000000001"};

verifySignatures = 2;
BattlEye = 1;
voteThreshold = 0.33;
persistent = 1;

// A URL in a string: the // must NOT start a comment
logFile = "http://example.test/log";

class Missions
{
    class Mission_1
    {
        template = "MyMission.Altis";
        difficulty = "regular";
    };
};

forcedDifficulty = "custom";
someFutureKey = "hello";
duplicateKey = "first";
duplicateKey = "second";
CFG;

$c = ArmaConfigFile::parse($original);

echo "Parsing scalars:\n";
check('reads a quoted string', $c->get('hostname'), 'Test Server');
check('empty string parses as empty, not null', $c->get('password'), '');
check('doubled quotes decode to one quote', $c->get('passwordAdmin'), 'sec"ret');
check('integer stays a string', $c->get('maxPlayers'), '64');
check('float stays a string', $c->get('voteThreshold'), '0.33');
check('a // inside a string is not a comment', $c->get('logFile'), 'http://example.test/log');
check('unknown key is readable', $c->get('someFutureKey'), 'hello');
check('last duplicate wins', $c->get('duplicateKey'), 'second');
check('missing key returns the default', $c->get('nope', 'fallback'), 'fallback');
check('has() is true for a present key', $c->has('BattlEye'), true);
check('has() is false for an absent key', $c->has('nope'), false);

echo "\nParsing arrays:\n";
check('multi-line array parses', $c->get('motd'), ['Welcome to the server', 'Rules: https://example.test/rules']);
check('single-line array parses', $c->get('admins'), ['76561198000000000', '76561198000000001']);
check('arrays() collects them', array_keys($c->arrays()), ['motd', 'admins']);
check('all() excludes arrays', array_key_exists('motd', $c->all()), false);
check('all() includes scalars', $c->all()['hostname'], 'Test Server');

echo "\nParsing class blocks:\n";
check('class block is found', $c->classNames(), ['Missions']);
check('a nested class does not leak into scalars', array_key_exists('template', $c->all()), false);
check('a key after a class block still parses', $c->get('forcedDifficulty'), 'custom');

echo "\nRound trip:\n";
$untouched = ArmaConfigFile::parse($original)->render();
check('an untouched file round-trips byte-for-byte', $untouched, rtrim($original, "\n") . "\n");

echo "\nEditing:\n";
$edited = ArmaConfigFile::parse($original)->set('hostname', 'New Name');
check('the edited value reads back', $edited->get('hostname'), 'New Name');
check('the new value is quoted in the output', str_contains($edited->render(), 'hostname = "New Name";'), true);
check('an untouched neighbour is unchanged', str_contains($edited->render(), 'maxPlayers = 64;'), true);
check('comments survive an edit', str_contains($edited->render(), '// Hexalabs Arma 3 server'), true);
check('the class block survives an edit', str_contains($edited->render(), 'template = "MyMission.Altis";'), true);

$number = ArmaConfigFile::parse($original)->set('maxPlayers', '80');
check('a number stays unquoted when rewritten', str_contains($number->render(), 'maxPlayers = 80;'), true);
check('a number is not quoted', str_contains($number->render(), 'maxPlayers = "80";'), false);

$quoteInValue = ArmaConfigFile::parse($original)->set('hostname', 'He said "hi"');
check('a quote in a new value is doubled on write', str_contains($quoteInValue->render(), 'hostname = "He said ""hi""";'), true);
check('and decodes again on reparse', ArmaConfigFile::parse($quoteInValue->render())->get('hostname'), 'He said "hi"');

$arrayEdit = ArmaConfigFile::parse($original)->set('motd', ['One', 'Two', 'Three']);
check('an array rewrites as an array', str_contains($arrayEdit->render(), 'motd[] = {"One", "Two", "Three"};'), true);
check('and reparses', ArmaConfigFile::parse($arrayEdit->render())->get('motd'), ['One', 'Two', 'Three']);

$added = ArmaConfigFile::parse($original)->set('brandNew', 'value');
check('a new key is appended', ArmaConfigFile::parse($added->render())->get('brandNew'), 'value');
check('a new numeric key is written bare', str_contains(
    ArmaConfigFile::parse($original)->set('newNumber', '42')->render(),
    'newNumber = 42;',
), true);

$dedup = ArmaConfigFile::parse($original)->set('duplicateKey', 'only');
check('setting a duplicated key removes the later copy', substr_count($dedup->render(), 'duplicateKey'), 1);
check('and the surviving value is the new one', ArmaConfigFile::parse($dedup->render())->get('duplicateKey'), 'only');

$forgotten = ArmaConfigFile::parse($original)->forget('someFutureKey');
check('forget() removes the key', $forgotten->has('someFutureKey'), false);
check('forget() leaves neighbours alone', $forgotten->get('forcedDifficulty'), 'custom');

echo "\nClass blocks:\n";
$block = ArmaConfigFile::parse($original)->setBlock('Missions', "    class Only\n    {\n        template = \"Other.Malden\";\n    };");
check('the block is replaced', str_contains($block->render(), 'Other.Malden'), true);
check('the old body is gone', str_contains($block->render(), 'MyMission.Altis'), false);
check('only one Missions block remains', substr_count($block->render(), 'class Missions'), 1);
check('a reparse still sees one block', ArmaConfigFile::parse($block->render())->classNames(), ['Missions']);

$newBlock = ArmaConfigFile::parse("hostname = \"x\";\n")->setBlock('Missions', '    // empty');
check('a missing block is appended', str_contains($newBlock->render(), 'class Missions'), true);
check('and the file still parses', ArmaConfigFile::parse($newBlock->render())->get('hostname'), 'x');

echo "\nchangedKeys:\n";
$c2 = ArmaConfigFile::parse($original);
check('detects a scalar change', $c2->changedKeys(['maxPlayers' => '80']), ['maxPlayers']);
check('ignores an unchanged scalar', $c2->changedKeys(['maxPlayers' => '64']), []);
check('treats a new key as changed', $c2->changedKeys(['brandNew' => 'x']), ['brandNew']);
check('detects an array change', $c2->changedKeys(['admins' => ['1']]), ['admins']);
check('ignores an unchanged array', $c2->changedKeys(['admins' => ['76561198000000000', '76561198000000001']]), []);

echo "\nEdge cases:\n";
check('an empty file yields no pairs', ArmaConfigFile::parse('')->all(), []);
check('a comment-only file yields no pairs', ArmaConfigFile::parse("// nothing here\n")->all(), []);
check('a comment-only file round-trips', ArmaConfigFile::parse("// nothing here\n")->render(), "// nothing here\n");
check('CRLF input parses', ArmaConfigFile::parse("a = 1;\r\nb = 2;\r\n")->get('b'), '2');
check('a missing semicolon still parses', ArmaConfigFile::parse("hostname = \"x\"\nmaxPlayers = 8;\n")->get('maxPlayers'), '8');
check('whitespace around = is tolerated', ArmaConfigFile::parse("a   =   \"b\";\n")->get('a'), 'b');
check('no whitespace around = is tolerated', ArmaConfigFile::parse('a="b";')->get('a'), 'b');
check('an unterminated string does not swallow the parser', is_array(ArmaConfigFile::parse('a = "oops;')->all()), true);
check('an unclosed class block is preserved verbatim', str_contains(ArmaConfigFile::parse("class X\n{\n  y = 1;\n")->render(), 'class X'), true);
check('a semicolon inside a string does not end the statement', ArmaConfigFile::parse('a = "one;two";')->get('a'), 'one;two');
check('a brace inside a string does not open a block', ArmaConfigFile::parse('a = "{";')->get('a'), '{');
check('a block comment between statements survives', str_contains(
    ArmaConfigFile::parse("a = 1;\n/* mid */\nb = 2;\n")->set('a', '9')->render(),
    '/* mid */',
), true);

// basic.cfg is the same grammar with different keys, so one parser serves both.
$basic = ArmaConfigFile::parse("MaxMsgSend = 128;\nMinBandwidth = 131072;\nclass sockets { maxPacketSize = 1400; };\n");
check('basic.cfg scalars parse', $basic->get('MaxMsgSend'), '128');
check('basic.cfg nested class is opaque', $basic->classNames(), ['sockets']);
check('basic.cfg round-trips', ArmaConfigFile::parse($basic->render())->get('MinBandwidth'), '131072');

echo "\n" . str_repeat('-', 40) . "\n";
echo "$pass passed, $fail failed\n";

exit($fail > 0 ? 1 : 0);
