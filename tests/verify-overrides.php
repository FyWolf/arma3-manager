<?php

/**
 * Check every overridden method against the declaration it inherits.
 *
 *   php tests/verify-overrides.php /path/to/panel
 *
 * PHP enforces inherited visibility at class-load time, not at call time, so
 * `protected function getFormActions()` over a trait's `public` one is a FATAL
 * the moment the class is autoloaded. In a Filament panel that happens during
 * boot, so it does not break one page — it takes the whole panel down, and the
 * error page itself then fails to render because Filament never got far enough
 * to register its view namespace ("No hint path defined for [filament]").
 *
 * `php -l` cannot see this: it is not a syntax error. The import checker cannot
 * either: every class resolves fine.
 *
 * This deliberately does NOT autoload the plugin's own classes — doing so would
 * hit the exact fatal it exists to report, and die on the first one. Instead it
 * tokenises each file to learn its parent and its method visibilities, then
 * reflects only on the parent (panel and Filament code, which loads safely).
 */

$panel = $argv[1] ?? getenv('PELICAN_PANEL_PATH') ?: null;

if (! $panel || ! is_file($panel . '/vendor/autoload.php')) {
    fwrite(STDERR, "Usage: php tests/verify-overrides.php /path/to/panel\n");

    exit(2);
}

$plugin = dirname(__DIR__);

require $panel . '/vendor/autoload.php';

/**
 * Pull the namespace, class name, parent, interfaces and declared methods out
 * of a source file without executing it.
 *
 * @return array{class: ?string, parent: ?string, interfaces: array<int, string>, methods: array<string, array{visibility: string, static: bool, line: int}>}
 */
function parseFile(string $path): array
{
    $tokens = token_get_all((string) file_get_contents($path));

    $namespace = '';
    $class = null;
    $parent = null;
    $interfaces = [];
    $methods = [];
    $uses = [];   // alias => FQN, from top-level `use X\Y;`

    $count = count($tokens);
    $visibility = 'public';
    $static = false;
    $inClassBody = false;
    $depth = 0;

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (is_string($token)) {
            if ($token === '{') {
                $depth++;
            } elseif ($token === '}') {
                $depth--;
            } elseif ($token === ';') {
                // End of a statement — most importantly a property declaration.
                // Without this, `protected static ?string $slug = 'x';` leaves
                // the static flag set and the NEXT method is wrongly reported as
                // static.
                $visibility = 'public';
                $static = false;
            }

            continue;
        }

        [$id, $text] = $token;

        $next = static function (int $from) use ($tokens, $count): array {
            for ($j = $from + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                return [$j, $tokens[$j]];
            }

            return [$count, null];
        };

        $readName = static function (int $from) use ($tokens, $count): array {
            $name = '';

            for ($j = $from; $j < $count; $j++) {
                $t = $tokens[$j];

                if (is_array($t) && in_array($t[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $name .= $t[1];

                    continue;
                }

                if (is_array($t) && $t[0] === T_WHITESPACE && $name === '') {
                    continue;
                }

                break;
            }

            return [$j ?? $from, $name];
        };

        switch ($id) {
            case T_NAMESPACE:
                [$j, $namespace] = $readName($i + 1);
                $i = $j - 1;

                break;

            case T_USE:
                // Only top-level imports (outside a class body).
                if ($depth === 0 && $class === null) {
                    [$j, $name] = $readName($i + 1);

                    if ($name !== '') {
                        $alias = substr($name, strrpos($name, '\\') === false ? 0 : strrpos($name, '\\') + 1);
                        $uses[$alias] = ltrim($name, '\\');
                    }

                    $i = $j - 1;
                }

                break;

            case T_CLASS:
                // Skip anonymous classes and `::class`.
                [, $peek] = $next($i);

                if (! is_array($peek) || $peek[0] !== T_STRING) {
                    break;
                }

                if ($class !== null) {
                    break;
                }

                [$j, $class] = $readName($i + 1);
                $i = $j - 1;
                $inClassBody = true;

                break;

            case T_EXTENDS:
                [$j, $parent] = $readName($i + 1);
                $i = $j - 1;

                break;

            case T_IMPLEMENTS:
                for ($j = $i + 1; $j < $count; $j++) {
                    $t = $tokens[$j];

                    if (is_string($t) && $t === '{') {
                        break;
                    }

                    if (is_array($t) && in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                        $interfaces[] = $t[1];
                    }
                }

                $i = $j - 1;

                break;

            case T_PUBLIC:
                $visibility = 'public';

                break;

            case T_PROTECTED:
                $visibility = 'protected';

                break;

            case T_PRIVATE:
                $visibility = 'private';

                break;

            case T_STATIC:
                $static = true;

                break;

            case T_FUNCTION:
                if ($inClassBody) {
                    [, $peek] = $next($i);

                    if (is_array($peek) && $peek[0] === T_STRING) {
                        $methods[$peek[1]] = [
                            'visibility' => $visibility,
                            'static' => $static,
                            'line' => $token[2],
                        ];
                    }
                }

                $visibility = 'public';
                $static = false;

                break;
        }
    }

    $resolve = static function (?string $name) use ($uses, $namespace): ?string {
        if ($name === null || $name === '') {
            return null;
        }

        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $head = explode('\\', $name)[0];

        if (isset($uses[$head])) {
            return $uses[$head] . substr($name, strlen($head));
        }

        return ($namespace !== '' ? $namespace . '\\' : '') . $name;
    };

    return [
        'class' => $class ? (($namespace !== '' ? $namespace . '\\' : '') . $class) : null,
        'parent' => $resolve($parent),
        'interfaces' => array_values(array_filter(array_map($resolve, $interfaces))),
        'methods' => $methods,
        'file' => $path,
    ];
}

$files = [];

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($plugin . '/src')) as $entry) {
    if ($entry->isFile() && $entry->getExtension() === 'php') {
        $files[] = $entry->getPathname();
    }
}

sort($files);

$rank = ['private' => 0, 'protected' => 1, 'public' => 2];
$problems = [];
$checkedMethods = 0;
$checkedClasses = 0;

foreach ($files as $file) {
    $parsed = parseFile($file);

    if (! $parsed['class'] || $parsed['methods'] === []) {
        continue;
    }

    $checkedClasses++;

    /** @var array<int, ReflectionClass<object>> $ancestors */
    $ancestors = [];

    // The parent chain. Reflecting on it is safe: it is panel and Filament
    // code, which loads independently of this plugin.
    if ($parsed['parent'] && (class_exists($parsed['parent']) || interface_exists($parsed['parent']))) {
        $ancestors[] = new ReflectionClass($parsed['parent']);
    }

    foreach ($parsed['interfaces'] as $interface) {
        if (interface_exists($interface)) {
            $ancestors[] = new ReflectionClass($interface);
        }
    }

    if ($ancestors === []) {
        continue;
    }

    foreach ($parsed['methods'] as $name => $declared) {
        foreach ($ancestors as $ancestor) {
            if (! $ancestor->hasMethod($name)) {
                continue;
            }

            $inherited = $ancestor->getMethod($name);
            $checkedMethods++;

            $theirs = $inherited->isPrivate() ? 'private' : ($inherited->isProtected() ? 'protected' : 'public');

            if ($rank[$declared['visibility']] < $rank[$theirs]) {
                $problems[] = sprintf(
                    "%s::%s()\n    line %d — declared %s, inherited %s from %s\n    FATAL at class load; takes the whole panel down.",
                    $parsed['class'],
                    $name,
                    $declared['line'],
                    $declared['visibility'],
                    $theirs,
                    $inherited->getDeclaringClass()->getName(),
                );
            }

            if ($declared['static'] !== $inherited->isStatic()) {
                $problems[] = sprintf(
                    "%s::%s()\n    line %d — static mismatch against %s",
                    $parsed['class'],
                    $name,
                    $declared['line'],
                    $inherited->getDeclaringClass()->getName(),
                );
            }

            break;
        }
    }
}

echo "Checked $checkedClasses classes, $checkedMethods inherited method(s).\n\n";

if ($problems === []) {
    echo "No visibility or signature conflicts.\n";

    exit(0);
}

echo "CONFLICTS:\n";

foreach ($problems as $problem) {
    echo '  ' . $problem . "\n\n";
}

exit(1);
