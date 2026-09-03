<?php

/**
 * Resolve every `use X;` in this plugin against a real panel's autoloader.
 *
 *   php tests/verify-imports.php /path/to/panel
 *
 * Worth running before every release. A wrong namespace in a Pelican plugin is
 * a silent failure — PluginService catches the exception, flips the plugin to
 * Errored and moves on — so a mistyped import produces a plugin that simply
 * does not appear, with the reason buried in a status column. This catches that
 * entire class of bug in a second.
 *
 * Not run in CI: it needs a checked-out panel with vendor/ installed.
 */

$panel = $argv[1] ?? getenv('PELICAN_PANEL_PATH') ?: null;

if (! $panel || ! is_file($panel . '/vendor/autoload.php')) {
    fwrite(STDERR, "Usage: php tests/verify-imports.php /path/to/panel\n");
    fwrite(STDERR, "(the path must contain vendor/autoload.php)\n");

    exit(2);
}

$plugin = dirname(__DIR__);

require $panel . '/vendor/autoload.php';

// Register this plugin's PSR-4 root exactly as PluginService does at runtime.
spl_autoload_register(function (string $class) use ($plugin) {
    $prefix = 'FyWolf\\Arma3Manager\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $file = $plugin . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

$files = [];

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($plugin)) as $entry) {
    if ($entry->isFile() && $entry->getExtension() === 'php' && ! str_contains($entry->getPathname(), '.git')) {
        $files[] = $entry->getPathname();
    }
}

$byFile = [];
$checked = 0;

foreach ($files as $file) {
    // `use A\B\C;` / `use A\B\C as D;`, ignoring closure `use (...)`.
    preg_match_all(
        '/^use\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*(?:as\s+\w+)?;/m',
        (string) file_get_contents($file),
        $matches,
    );

    foreach ($matches[1] as $class) {
        $checked++;

        if (class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class)) {
            continue;
        }

        $byFile[basename($file)][] = $class;
    }
}

echo "Checked $checked imports across " . count($files) . " files.\n\n";

if ($byFile === []) {
    echo "All imports resolve.\n";

    exit(0);
}

echo "UNRESOLVED:\n";

foreach ($byFile as $file => $classes) {
    echo "  $file\n";

    foreach (array_unique($classes) as $class) {
        echo "    - $class\n";
    }
}

exit(1);
