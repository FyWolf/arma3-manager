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

// ---------------------------------------------------------------------------
// Nothing may read a server variable through the Server::variables relation.
// ---------------------------------------------------------------------------
//
// The panel's `Server::variables()` is a hasMany over `egg_variables` with a
// left join onto `server_variables`, constrained inside a closure that reads
// `$this->id`. Under lazy loading that is the real model. Under **eager**
// loading Laravel builds the relation with
// `Relation::noConstraints(fn () => $model->newInstance()->$name())` — a fresh,
// attribute-less instance — so `$this->id` is null, the join matches nothing,
// and `server_value` is null for every variable on the server.
//
// This plugin called `loadMissing('variables')` and then read `server_value`,
// so every read returned "unset" no matter what was stored. The write was
// unaffected, because it only needs `egg_variables.id`. The result: the panel
// showed ninety mods, the database held them, and every page here showed an
// empty list — through six rounds of diagnosis, including one where this
// plugin's own diagnostic command reported "no server_variables row" and then
// printed that row in full a few lines later.
//
// `ServerVariables` goes to the tables directly. Nothing else should reach for
// the relation, and `server_value` is the tell because it exists nowhere else.

$eager = [];

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/src')) as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $name = $file->getBasename('.php');

    // ServerVariables is the one place allowed to discuss any of this, and it
    // only does so in prose.
    if ($name === 'ServerVariables') {
        continue;
    }

    // Comments explain the trap on purpose; only code counts.
    $source = file_get_contents($file->getPathname());
    $source = preg_replace('#/\*.*?\*/#s', '', $source) ?? $source;
    $source = preg_replace('#//[^\n]*#', '', $source) ?? $source;

    if (str_contains($source, 'server_value')) {
        $eager[] = "$name reads ->server_value, which is null whenever the relation was eager loaded. Use ServerVariables::read().";
    }

    if (preg_match("/loadMissing\(\s*'variables'\s*\)/", $source) === 1) {
        $eager[] = "$name calls loadMissing('variables'), which eager loads and makes every server_value null. Use ServerVariables.";
    }
}

echo "\nServer variables:\n";
check('nothing reads a server variable through the relation', $eager, []);

foreach ($eager as $offender) {
    echo "       $offender\n";
}

// ---------------------------------------------------------------------------
// The workshop directory is resolved, never hardcoded without `Steam/`.
// ---------------------------------------------------------------------------
//
// SteamCMD runs `+workshop_download_item` with no `+force_install_dir`, so it
// falls back to `$HOME/Steam` — and HOME is the server root. Items therefore
// land in `Steam/steamapps/workshop/content/<app>/<id>`. Only the *game* is in
// the root, installed with an explicit `+force_install_dir`.
//
// This plugin looked in `steamapps/workshop` for its whole life. That directory
// does not exist on the stock image, `listDirectories()` catches the 404 and
// returns an empty array, and so every mod read as "Waiting" forever while the
// download was in fact working. Nothing logged an error.
//
// A bare `steamapps/workshop` literal is therefore always the bug, and the
// prefixed form is deliberately not matched here.

$paths = [];

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/src')) as $file) {
    if ($file->isDir() || $file->getExtension() !== 'php') {
        continue;
    }

    $name = $file->getBasename();
    $source = file_get_contents($file->getPathname());

    // Comments explain the bug by name, so they must not count as one.
    $source = preg_replace('#/\*.*?\*/#s', '', $source) ?? $source;
    $source = preg_replace('#//[^\n]*#', '', $source) ?? $source;

    if (preg_match('#[\'"]steamapps/workshop#', $source) === 1) {
        $paths[] = "$name hardcodes 'steamapps/workshop', which does not exist on the stock image — mods are in 'Steam/steamapps/workshop'. Resolve it with ModService::workshopRoot() instead.";
    }
}

echo "\nWorkshop directory:\n";
check('no file hardcodes the pre-Steam/ workshop path', $paths, []);

foreach ($paths as $offender) {
    echo "       $offender\n";
}

// ---------------------------------------------------------------------------
// A row action must act on the list its row came from.
// ---------------------------------------------------------------------------
//
// The Mods page renders two lists — `-mod=` and `-serverMod=` — into one table,
// and a mod may legally be in both, which shows as two rows. An action that
// takes only the entry name has to guess which list it meant, and it guessed by
// searching the client list first. Two silent bugs came out of that:
//
//   - Remove on a server-only row deleted the *client* entry instead. The row
//     the customer clicked stayed exactly where it was.
//   - The reorder arrows read the client list unconditionally, found no index
//     for a server-only row, and returned. A button that did nothing and said
//     nothing.
//
// Neither raised an error, and both look completely reasonable in the source.
// The row already knows which list it came from, so the fix is to pass it —
// and this check is here because "look it up again" is the natural thing to
// write and reads fine every time.

$scoped = [];

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/src')) as $file) {
    if ($file->isDir() || $file->getExtension() !== 'php') {
        continue;
    }

    $name = $file->getBasename();
    $source = file_get_contents($file->getPathname());
    $source = preg_replace('#/\*.*?\*/#s', '', $source) ?? $source;
    $source = preg_replace('#//[^\n]*#', '', $source) ?? $source;

    if (preg_match_all('#\$this->(remove|move|setScope)\((\$record\[[^)]*)\)#', $source, $matches, PREG_SET_ORDER) === 0) {
        continue;
    }

    foreach ($matches as $match) {
        // setScope takes the target scope as its own argument, so it is already
        // explicit about which list it is writing to.
        if ($match[1] === 'setScope') {
            continue;
        }

        if (! str_contains($match[2], 'server_only')) {
            $scoped[] = "$name calls \$this->{$match[1]}() on a row without passing \$record['server_only'], so it will guess which of the two mod lists to act on.";
        }
    }
}

echo "\nRow scope:\n";
check('a row action is told which mod list its row came from', $scoped, []);

foreach ($scoped as $offender) {
    echo "       $offender\n";
}

echo "\n" . str_repeat('-', 40) . "\n";
echo "Checked $checked page class(es).\n";
echo "$pass passed, $fail failed\n";

exit($fail > 0 ? 1 : 0);
