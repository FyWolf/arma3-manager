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

// ---------------------------------------------------------------------------
// No Filament upload may gate on MIME type.
// ---------------------------------------------------------------------------
//
// `acceptedFileTypes()` becomes a Laravel `mimetypes:` rule, and for a Livewire
// upload that resolves through TemporaryUploadedFile::getMimeType() ->
// Storage::mimeType() -> libmagic **on the server**. A genuine Arma 3 Launcher
// preset is a UTF-8 BOM, then an XML prolog, then an HTML body, and what
// libmagic makes of that varies by version and magic database: Windows PHP
// reports text/xml, and a Linux panel reported something outside a list that
// already held text/xml, application/xml, text/html, application/xhtml+xml and
// text/plain.
//
// Widening the list is not a fix — it is the same bug waiting for a different
// server, and it fails with a framework message naming MIME types the customer
// cannot check. `LauncherPreset::fromFile()` reads the bytes and is the
// authority. This pins that decision, because re-adding the call looks like an
// obvious improvement right up until somebody else's libmagic disagrees.

$uploads = [];

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $source = file_get_contents($file->getPathname());

    if (preg_match('/->\s*acceptedFileTypes\s*\(/', $source) === 1) {
        $uploads[] = $file->getBasename('.php') . ' calls acceptedFileTypes(), which gates uploads on server-side MIME detection. Validate the bytes instead.';
    }
}

echo "\nUploads:\n";
check('no upload gates on MIME type', $uploads, []);

foreach ($uploads as $upload) {
    echo "       $upload\n";
}

// ---------------------------------------------------------------------------
// Nothing may synthesise a mod folder name from a Steam title.
// ---------------------------------------------------------------------------
//
// The load order is Workshop ids. It briefly held `'@' . preg_replace(...)` of
// each mod's Steam title, which is wrong twice: the egg's install script cannot
// download a name, so nothing was ever fetched; and the guess did not match the
// folder anyway, because the real one comes from the mod's own mod.cpp. A title
// like "[AFR] - Arma Factions Reimagined" sanitises to something no publisher
// ever chose.
//
// The shape is distinctive enough to grep for, and it looked entirely
// reasonable in review, so it is worth failing the build over.

$guesses = [];

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/src')) as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $source = file_get_contents($file->getPathname());

    if (preg_match("/'@'\s*\.\s*preg_replace/", $source) === 1) {
        $guesses[] = $file->getBasename('.php') . " builds a mod folder name with '@' . preg_replace(...). The load order holds Workshop ids; the folder is the install script's business.";
    }
}

echo "\nMod folder names:\n";
check('no page invents a folder name from a Steam title', $guesses, []);

foreach ($guesses as $guess) {
    echo "       $guess\n";
}

echo "\n" . str_repeat('-', 40) . "\n";
echo "Checked $checked page class(es).\n";
echo "$pass passed, $fail failed\n";

exit($fail > 0 ? 1 : 0);
