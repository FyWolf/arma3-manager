<?php

/**
 * Every Filament page must override the header-action method its own base class
 * actually calls.
 *
 * ## The bug this exists for
 *
 * There are two spellings and they are not interchangeable:
 *
 * - `ServerFormPage` (the panel's own) carries `CanCustomizeHeaderActions`,
 *   whose `getHeaderActions()` merges in actions other plugins registered via
 *   `registerCustomHeaderActions()` and then calls **`getDefaultHeaderActions()`**.
 *   Overriding `getHeaderActions()` there compiles fine and silently discards
 *   every other plugin's buttons.
 * - Filament's own `Page` gets **`getHeaderActions()`** from
 *   `InteractsWithHeaderActions` and has no `getDefaultHeaderActions()` at all.
 *   Defining one there compiles fine, is never called, and the page renders with
 *   no header buttons whatsoever.
 *
 * Four pages in this plugin shipped with the second fault: `ModsPage`'s "Write
 * mod list" — the primary action of the whole feature — `MissionsPage`'s upload
 * button, and both of `WorkshopPage`'s and `PresetsPage`'s. Every one of them
 * was dead code that `php -l` accepted, `verify-imports` accepted, and
 * `verify-overrides` accepted, because nothing about it is a *conflict*. It is
 * simply a method nobody calls.
 *
 * It needs no panel: the base class name is in the file, and the rule follows
 * from the name alone. So it runs in CI with the other parser tests.
 */

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
 * Base classes that call `getDefaultHeaderActions()`, i.e. the panel's own
 * page classes carrying `CanCustomizeHeaderActions`.
 *
 * @var array<int, string>
 */
$wrapsHeaderActions = ['ServerFormPage'];

$root = dirname(__DIR__) . '/src/Filament';

if (! is_dir($root)) {
    echo "No Filament directory; nothing to check.\n";
    exit(0);
}

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
$checked = 0;
$offenders = [];

foreach ($files as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $source = file_get_contents($file->getPathname());
    $name = $file->getBasename('.php');

    if (preg_match('/class\s+\w+\s+extends\s+(\w+)/', $source, $matches) !== 1) {
        continue;
    }

    $base = $matches[1];

    // Resources and ManageRecords pages are a different family and use
    // getHeaderActions() unconditionally; only page classes are in scope.
    if (! str_contains($base, 'Page')) {
        continue;
    }

    $checked++;

    $hasDefault = preg_match('/function\s+getDefaultHeaderActions\s*\(/', $source) === 1;
    $hasPlain = preg_match('/function\s+getHeaderActions\s*\(/', $source) === 1;

    if (in_array($base, $wrapsHeaderActions, true)) {
        if ($hasPlain) {
            $offenders[] = "$name extends $base and overrides getHeaderActions() — that discards other plugins' header actions; use getDefaultHeaderActions().";
        }

        continue;
    }

    // Anything else is a plain Filament Page.
    if ($hasDefault) {
        $offenders[] = "$name extends $base and defines getDefaultHeaderActions() — that method is never called on a plain Page, so its header buttons never render. Use getHeaderActions().";
    }
}

echo "Header actions:\n";
check('at least one page class was inspected', $checked > 0, true);
check('every page overrides the method its base class calls', $offenders, []);

foreach ($offenders as $offender) {
    echo "       $offender\n";
}

echo "\n" . str_repeat('-', 40) . "\n";
echo "Checked $checked page class(es).\n";
echo "$pass passed, $fail failed\n";

exit($fail > 0 ? 1 : 0);
