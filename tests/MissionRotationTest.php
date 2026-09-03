<?php

require __DIR__ . '/../src/Support/MissionRotation.php';

use FyWolf\Arma3Manager\Support\MissionRotation;

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

echo "Template normalisation:\n";
check('a .pbo extension is stripped', MissionRotation::template('MyMission.Altis.pbo'), 'MyMission.Altis');
check('an uppercase extension is stripped', MissionRotation::template('MyMission.Altis.PBO'), 'MyMission.Altis');
check('a name without an extension is unchanged', MissionRotation::template('MyMission.Altis'), 'MyMission.Altis');
check('the map suffix is never mistaken for an extension', MissionRotation::template('Co30_Domination.Malden'), 'Co30_Domination.Malden');
check('a path is reduced to its basename', MissionRotation::template('mpmissions/MyMission.Altis.pbo'), 'MyMission.Altis');
check('surrounding whitespace goes', MissionRotation::template('  MyMission.Altis.pbo  '), 'MyMission.Altis');

echo "\nBuilding:\n";
$rotation = MissionRotation::fromArray([
    ['template' => 'First.Altis.pbo'],
    ['template' => 'Second.Malden', 'difficulty' => 'veteran'],
]);
check('two entries survive', count($rotation->all()), 2);
check('the extension is stripped on build', $rotation->all()[0]['template'], 'First.Altis');
check('difficulty defaults to regular', $rotation->all()[0]['difficulty'], 'regular');
check('an explicit difficulty is kept', $rotation->all()[1]['difficulty'], 'veteran');
check('an empty template is dropped', count(MissionRotation::fromArray([['template' => '  ']])->all()), 0);
check('an empty rotation reports empty', MissionRotation::fromArray([])->isEmpty(), true);
check('a populated rotation is not empty', $rotation->isEmpty(), false);

echo "\nRendering:\n";
$block = $rotation->render();
check('classes are numbered from one', str_contains($block, 'class Mission_1'), true);
check('and increment', str_contains($block, 'class Mission_2'), true);
check('the template is written without .pbo', str_contains($block, 'template = "First.Altis";'), true);
check('the .pbo does not leak into the config', str_contains($block, '.pbo'), false);
check('the difficulty is written', str_contains($block, 'difficulty = "veteran";'), true);
check('an empty rotation renders a comment, not bare braces', str_contains(MissionRotation::fromArray([])->render(), '//'), true);

echo "\nRound trip:\n";
$reparsed = MissionRotation::parse($rotation->render());
check('a rendered block reparses to the same count', count($reparsed->all()), 2);
check('templates survive', array_column($reparsed->all(), 'template'), ['First.Altis', 'Second.Malden']);
check('difficulties survive', array_column($reparsed->all(), 'difficulty'), ['regular', 'veteran']);
check('order survives', $reparsed->all()[0]['template'], 'First.Altis');

echo "\nParsing hand-written blocks:\n";
$handWritten = <<<'CFG'
    class Mission_Alpha
    {
        template = "Handwritten.Stratis";
        difficulty = "custom";
    };
    class WhateverNameTheyChose
    {
        template = "Second.Tanoa";
    };
CFG;
$parsed = MissionRotation::parse($handWritten);
check('arbitrary class names are accepted', count($parsed->all()), 2);
check('the first template is read', $parsed->all()[0]['template'], 'Handwritten.Stratis');
check('a missing difficulty defaults', $parsed->all()[1]['difficulty'], 'regular');
check('an empty block yields nothing', MissionRotation::parse('')->all(), []);
check('a comment-only block yields nothing', MissionRotation::parse('// none')->all(), []);
check('a class with no template is skipped', MissionRotation::parse('class X { difficulty = "regular"; };')->all(), []);
check('a rotation of one renders and reparses', count(MissionRotation::parse(
    MissionRotation::fromArray([['template' => 'Only.Altis']])->render(),
)->all()), 1);

echo "\n" . str_repeat('-', 40) . "\n";
echo "$pass passed, $fail failed\n";

exit($fail > 0 ? 1 : 0);
