<?php

require __DIR__ . '/../src/Support/WorkshopId.php';
require __DIR__ . '/../src/Support/DownloadStatus.php';

use FyWolf\Arma3Manager\Support\DownloadStatus;

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
 * A status document, with `updated_at` defaulting to now so staleness is not
 * accidentally under test in every case.
 */
function status(array $overrides = [], array $mods = []): ?DownloadStatus
{
    return DownloadStatus::fromJson(json_encode(array_merge([
        'version' => 1,
        'updated_at' => time(),
        'phase' => 'mods',
        'sync_only' => false,
        'mods' => $mods,
    ], $overrides)));
}

echo "Refusing what is not ours:\n";
check('null input', DownloadStatus::fromJson(null), null);
check('an empty string', DownloadStatus::fromJson(''), null);
check('whitespace', DownloadStatus::fromJson("  \n "), null);
check('not JSON at all', DownloadStatus::fromJson('<html>nope</html>'), null);
check('a JSON scalar', DownloadStatus::fromJson('42'), null);
check('a truncated document', DownloadStatus::fromJson('{"version":1,"mods":['), null);
// The version gate is what stops a future format being read as this one — a
// silently misread document would report confident nonsense.
check('a document with no version', DownloadStatus::fromJson('{"phase":"mods"}'), null);
check('a future version', DownloadStatus::fromJson('{"version":2,"phase":"mods"}'), null);
check('version as a string, not a number', DownloadStatus::fromJson('{"version":"1"}'), null);

echo "\nReading mods:\n";
$s = status([], [
    ['id' => '450814997', 'state' => 'done', 'name' => 'CBA_A3', 'bytes' => 100, 'expected_bytes' => 100, 'percent' => 100, 'error' => null],
    ['id' => '463939057', 'state' => 'downloading', 'name' => 'ACE3', 'bytes' => 50, 'expected_bytes' => 200, 'percent' => 25, 'error' => null],
    ['id' => '332350688', 'state' => 'failed', 'name' => null, 'bytes' => 0, 'expected_bytes' => null, 'percent' => null, 'error' => 'Timed out.'],
    ['id' => '111111111', 'state' => 'waiting', 'name' => null, 'bytes' => 0, 'expected_bytes' => null, 'percent' => null, 'error' => null],
]);

check('states are read back', $s->state('450814997'), 'done');
check('an untracked id has no state', $s->state('999999999'), null);
check('done ids', $s->idsInState('done'), ['450814997']);
check('downloading ids', $s->idsInState('downloading'), ['463939057']);
check('failed ids', $s->idsInState('failed'), ['332350688']);
check('waiting ids', $s->idsInState('waiting'), ['111111111']);
check('a state nothing is in', $s->idsInState('exploded'), []);

echo "\nPercentages:\n";
check('a percentage is read', $s->percent('463939057'), 25);
// Null, never 0: "no bar" and "started and got nowhere" are different claims,
// and the page renders them differently.
check('an absent percentage is null, not zero', $s->percent('332350688'), null);
check('an untracked id has no percentage', $s->percent('999999999'), null);
check('bytes are read', $s->bytes('463939057'), 50);
check('expected bytes are read', $s->expectedBytes('463939057'), 200);
check('a zero expected size reads as unknown', $s->expectedBytes('332350688'), null);

$odd = status([], [
    ['id' => '450814997', 'state' => 'downloading', 'percent' => 140],
    ['id' => '463939057', 'state' => 'downloading', 'percent' => -20],
    ['id' => '332350688', 'state' => 'downloading', 'percent' => '60'],
]);
// It genuinely overshoots: `bytes` is what is on disk and `expected_bytes` is
// what Steam last reported, so a mod updated since is larger than its record.
check('an over-100 percentage clamps', $odd->percent('450814997'), 100);
check('a negative percentage clamps', $odd->percent('463939057'), 0);
check('a numeric string is accepted', $odd->percent('332350688'), 60);

echo "\nNames and errors:\n";
check('a name is read', $s->name('450814997'), 'CBA_A3');
check('a null name is null', $s->name('332350688'), null);
check('an error is read', $s->error('332350688'), 'Timed out.');
check('a healthy mod has no error', $s->error('450814997'), null);

$blank = status([], [['id' => '450814997', 'state' => 'done', 'name' => '   ', 'error' => '  ']]);
check('a whitespace-only name is null', $blank->name('450814997'), null);
check('a whitespace-only error is null', $blank->error('450814997'), null);

echo "\nRefusing junk inside a valid document:\n";
// This file lives in the customer's own file manager and can be hand-edited.
$junk = status([], [
    ['id' => 'not-an-id', 'state' => 'done'],
    ['id' => '../../etc/passwd', 'state' => 'done'],
    ['id' => '', 'state' => 'done'],
    'a bare string, not an object',
    ['no_id_at_all' => true],
    ['id' => '450814997', 'state' => 'done'],
]);
check('only real workshop ids survive', $junk->idsInState('done'), ['450814997']);
check('a traversal attempt is dropped', $junk->state('../../etc/passwd'), null);

echo "\nStaleness:\n";
// A `mods` phase that stopped being rewritten means the container died
// mid-download. Believing it would leave a mod "downloading" forever, which is
// indistinguishable from a slow mod — the exact distinction this page exists for.
check('a fresh mods phase is believed', status(['phase' => 'mods'])->isStale(), false);
check('an old mods phase is not', status(['phase' => 'mods', 'updated_at' => time() - 600])->isStale(), true);
check('a mods phase with no timestamp is not', status(['phase' => 'mods', 'updated_at' => 0])->isStale(), true);
// Terminal phases are never rewritten again, so age proves nothing about them.
check('an old running phase is still true', status(['phase' => 'running', 'updated_at' => time() - 86400])->isStale(), false);
check('an old synced phase is still true', status(['phase' => 'synced', 'updated_at' => time() - 86400])->isStale(), false);
check('the boundary is not stale', status(['phase' => 'mods', 'updated_at' => time() - DownloadStatus::STALE_AFTER_SECONDS + 5])->isStale(), false);

echo "\nPhases:\n";
check('the phase is read', status(['phase' => 'running'])->phase, 'running');
check('a missing phase is unknown', DownloadStatus::fromJson('{"version":1}')->phase, 'unknown');
check('sync_only is read', status(['sync_only' => true])->syncOnly, true);
check('sync_only defaults to false', DownloadStatus::fromJson('{"version":1}')->syncOnly, false);
check('synced counts as synced', status(['phase' => 'synced'])->isSynced(), true);
check('synced_with_errors counts as synced', status(['phase' => 'synced_with_errors'])->isSynced(), true);
check('running does not', status(['phase' => 'running'])->isSynced(), false);

echo "\nAn empty but valid document:\n";
$empty = DownloadStatus::fromJson('{"version":1,"phase":"starting","mods":[]}');
check('parses', $empty instanceof DownloadStatus, true);
check('has no mods', $empty->idsInState('waiting'), []);
check('answers about an unknown id safely', $empty->state('450814997'), null);

echo "\n" . str_repeat('-', 40) . "\n";
echo "$pass passed, $fail failed\n";

exit($fail > 0 ? 1 : 0);
