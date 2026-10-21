<?php

// `tenma docs [<path>...] [--out <dir>]`: writes Markdown reference pages for PHP classes, interfaces,
// traits and enums, one file per type at <out>/<Namespace path>/<ShortName>.md plus an index.md per
// namespace folder, which also documents the namespace's functions. With paths it documents the types and
// functions declared in those files and directories (loaded
// through the project's vendor/autoload.php, or straight from the files); without, Tenma's own built-in
// API. Signatures come from reflection, prose from docblocks. Pages carry `generated: true` in their front
// matter: a run replaces them and removes the ones whose type is gone, and never touches any other file.

namespace Tenma\Internal\Docs;

const USAGE = 'Usage: tenma docs [<path>...] [--out <dir>]';

function main(string $binary, array $args): void
{
    $out = 'docs/reference';
    $paths = [];
    while ($args !== []) {
        $arg = array_shift($args);
        if ($arg === '--out') {
            $out = array_shift($args) ?? throw new \RuntimeException('--out needs a directory');
        } elseif (str_starts_with($arg, '--out=')) {
            $out = substr($arg, 6);
        } elseif ($arg === '--help' || $arg === '-h') {
            echo USAGE, "\n";
            return;
        } elseif (str_starts_with($arg, '-')) {
            throw new \RuntimeException("unknown option $arg\n" . USAGE);
        } else {
            $paths[] = $arg;
        }
    }
    $out = rtrim($out, '/');
    [$names, $functionNames] = $paths === [] ? [builtIn(), []] : load($paths);
    $functions = [];
    foreach ($functionNames as $name) {
        $function = new \ReflectionFunction($name);
        if (!$function->isInternal() && !internal($function->getDocComment())) {
            $functions[$function->getName()] = $function;
        }
    }
    ksort($functions, SORT_STRING | SORT_FLAG_CASE);
    $classes = [];
    foreach ($names as $name) {
        $class = new \ReflectionClass($name);
        if (!$class->isInternal() && !$class->isAnonymous() && !internal($class->getDocComment())) {
            $classes[$class->getName()] = $class;
        }
    }
    ksort($classes, SORT_STRING | SORT_FLAG_CASE);
    $pages = (new Writer($classes, $functions))->pages();
    $written = 0;
    foreach ($pages as $path => $content) {
        $file = "$out/$path";
        if (is_file($file) && !generated($file)) {
            throw new \RuntimeException("$file was not written by tenma docs (no `generated: true`), not overwriting it");
        }
        if (!is_file($file) || file_get_contents($file) !== $content) {
            @mkdir(dirname($file), 0777, true);
            file_put_contents($file, $content) !== false || throw new \RuntimeException("cannot write $file");
            $written++;
        }
    }
    $removed = is_dir($out) ? prune($out, $out, $pages) : 0;
    echo count($classes), ' types, ', $functions !== [] ? count($functions) . ' functions, ' : '', count($pages), " pages in $out ($written written, $removed removed)\n";
}

/** The classes of the public namespaces: what `tenma --stubs` covers. */
function builtIn(): array
{
    return array_filter(
        [...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()],
        \Tenma\Internal\isPublic(...),
    );
}

/** Loads the types and functions declared in $paths and returns their names: [types, functions]. */
function load(array $paths): array
{
    $files = [];
    foreach ($paths as $path) {
        if (is_dir($path)) {
            $found = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                fn (\SplFileInfo $entry) => $entry->isDir()
                    ? !in_array($entry->getFilename(), ['vendor', 'node_modules'], true) && !str_starts_with($entry->getFilename(), '.')
                    : $entry->getExtension() === 'php',
            ));
            foreach ($found as $entry) {
                $files[] = $entry->getPathname();
            }
        } elseif (is_file($path)) {
            $files[] = $path;
        } else {
            throw new \RuntimeException("$path: no such file or directory");
        }
    }
    $declared = [];
    $functions = [];
    foreach ($files as $file) {
        [$types, $named] = declarations((string) file_get_contents($file));
        foreach ($types as $name) {
            $declared[$name] = realpath($file);
        }
        foreach ($named as $name) {
            $functions[$name] = realpath($file);
        }
    }
    // The project's autoloader (the nearest vendor/ above the first path) first, so dependencies resolve; the files themselves for anything it misses
    $directory = realpath(is_dir($paths[0]) ? $paths[0] : dirname($paths[0]));
    for ($dir = $directory; $dir !== false; $dir = dirname($dir) === $dir ? false : dirname($dir)) {
        if (is_file("$dir/vendor/autoload.php")) {
            require_once "$dir/vendor/autoload.php";
            break;
        }
    }
    spl_autoload_register(function (string $name) use ($declared) {
        if (isset($declared[$name])) {
            require_once $declared[$name];
        }
    });
    // A type that does not load (say, its interface exists only inside a tool's phar) is skipped, not fatal
    $loaded = [];
    foreach ($declared as $name => $file) {
        try {
            $ok = class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name);
            $error = 'did not load';
        } catch (\Throwable $e) {
            $ok = false;
            $error = $e->getMessage();
        }
        if ($ok) {
            $loaded[] = $name;
        } else {
            file_put_contents('php://stderr', "warning: skipping $name from $file: $error\n");
        }
    }
    // Functions do not autoload: their files are loaded here, unless the autoloader's `files` did already
    // (loading one twice would declare its functions again)
    $loadedFunctions = [];
    foreach ($functions as $name => $file) {
        try {
            if (!function_exists($name) && !array_filter(array_keys($functions, $file, true), function_exists(...))) {
                require_once $file;
            }
            $error = 'did not load';
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
        if (function_exists($name)) {
            $loadedFunctions[] = $name;
        } else {
            file_put_contents('php://stderr', "warning: skipping $name() from $file: $error\n");
        }
    }
    return [$loaded, $loadedFunctions];
}

/**
 * Names of the classes, interfaces, traits and enums a PHP file declares, and of the functions it declares
 * at the top level of a namespace (not methods, closures or functions inside functions): [types, functions].
 */
function declarations(string $code): array
{
    $tokens = array_values(array_filter(\PhpToken::tokenize($code), fn ($token) => !$token->isIgnorable()));
    $namespace = '';
    $names = [];
    $functions = [];
    // The brace depth, and the depth at which a namespace's declarations are (1 inside `namespace X { }`)
    $depth = 0;
    $top = 0;
    foreach ($tokens as $i => $token) {
        if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
            $depth++;
        } elseif ($token->text === '}') {
            $depth--;
            $top = min($top, $depth);
        } elseif ($token->is(T_NAMESPACE) && ($tokens[$i + 1] ?? null)?->is([T_STRING, T_NAME_QUALIFIED])) {
            $namespace = $tokens[$i + 1]->text . '\\';
            $top = ($tokens[$i + 2] ?? null)?->text === '{' ? $depth + 1 : $depth;
        } elseif ($token->is(T_NAMESPACE) && ($tokens[$i + 1] ?? null)?->text === '{') {
            $namespace = '';
            $top = $depth + 1;
        } elseif ($token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM]) && ($tokens[$i + 1] ?? null)?->is(T_STRING)
            && !($tokens[$i - 1] ?? null)?->is([T_NEW, T_DOUBLE_COLON])) {
            $names[] = $namespace . $tokens[$i + 1]->text;
        } elseif ($token->is(T_FUNCTION) && $depth === $top && !($tokens[$i - 1] ?? null)?->is(T_USE)) {
            $name = ($tokens[$i + 1] ?? null)?->text === '&' ? $tokens[$i + 2] ?? null : $tokens[$i + 1] ?? null;
            if ($name?->is(T_STRING)) {
                $functions[] = $namespace . $name->text;
            }
        }
    }
    return [$names, $functions];
}

function generated(string $file): bool
{
    $head = (string) file_get_contents($file, length: 4096);
    return (bool) preg_match('/\A---\r?\n(?:.*\r?\n)*?generated:\s*true\s*\r?\n(?:.*\r?\n)*?---/', $head);
}

/** Removes generated pages below $dir that this run did not write, then directories left empty. */
function prune(string $out, string $dir, array $pages): int
{
    $removed = 0;
    foreach (scandir($dir) as $entry) {
        $path = "$dir/$entry";
        $relative = substr($path, strlen($out) + 1);
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (is_dir($path) && !is_link($path)) {
            $removed += prune($out, $path, $pages);
            if (count(scandir($path)) === 2) {
                rmdir($path);
            }
        } elseif (str_ends_with($entry, '.md') && !isset($pages[$relative]) && generated($path)) {
            unlink($path);
            $removed++;
        }
    }
    return $removed;
}

function internal(string|false $doc): bool
{
    return $doc !== false && preg_match('/^[\s*\/]*@internal\b/m', $doc) === 1;
}

/** A docblock as Markdown text and its tags: [text, [[tag, body], …]]. */
function parse(string|false $doc): array
{
    if ($doc === false) {
        return ['', []];
    }
    $doc = preg_replace(['/\A\/\*\*[ \t]?/', '/\s*\*\/\z/'], '', $doc);
    $lines = array_map(fn ($line) => preg_replace('/^\s*\* ?/', '', rtrim($line)), explode("\n", $doc));
    $text = [];
    $tags = [];
    $fenced = false;
    foreach ($lines as $line) {
        if (str_starts_with(ltrim($line), '```')) {
            $fenced = !$fenced;
        }
        if (!$fenced && preg_match('/^\s*@([\w-]+)\s*(.*)$/', $line, $match)) {
            $tags[] = [$match[1], $match[2]];
        } elseif ($tags !== [] && !$fenced) {
            $tags[count($tags) - 1][1] .= $line === '' ? "\n" : ' ' . trim($line);
        } else {
            $text[] = $line;
        }
    }
    $tags = array_map(fn ($tag) => [$tag[0], trim($tag[1])], $tags);
    return [trim(implode("\n", $text)), $tags];
}

/** The first sentence of a docblock's text, on one line. */
function sentence(string $text): string
{
    $paragraph = preg_replace('/\s+/', ' ', explode("\n\n", $text)[0]);
    // A code span is never cut: `a. b` is one piece
    return preg_match('/^((?:`[^`]*`|[^`])+?[.!?])(?:\s|$)/', $paragraph, $match) ? $match[1] : $paragraph;
}

/** Splits "type $name description" or "type description" at the end of the type, respecting brackets. */
function splitType(string $body): array
{
    $depth = 0;
    for ($i = 0; $i < strlen($body); $i++) {
        $char = $body[$i];
        if (str_contains('<({[', $char)) {
            $depth++;
        } elseif (str_contains('>)}]', $char) && !($char === '>' && ($body[$i - 1] ?? '') === '-')) {
            $depth--;
        } elseif ($depth === 0 && ctype_space($char)) {
            // A space next to | & : , is still inside the type: `callable(): int|string`, `A | B`
            $before = substr(rtrim(substr($body, 0, $i)), -1);
            $after = substr(ltrim(substr($body, $i)), 0, 1);
            if (!str_contains('|&:,', $before) && !str_contains('|&:,', $after)) {
                return [substr($body, 0, $i), ltrim(substr($body, $i))];
            }
        }
    }
    return [$body, ''];
}

function yaml(string $value): string
{
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/** A constant as written in code: fully qualified, except for self::, static:: and parent::. */
function constantName(string $name): string
{
    return preg_match('/^(self|static|parent)::/i', $name) ? $name : '\\' . ltrim($name, '\\');
}

function value(mixed $value): string
{
    return match (true) {
        is_array($value) => '[' . implode(', ', array_map(
            fn ($key, $item) => (array_is_list($value) ? '' : value($key) . ' => ') . value($item),
            array_keys($value),
            $value,
        )) . ']',
        $value === null => 'null',
        is_bool($value) => $value ? 'true' : 'false',
        $value instanceof \UnitEnum => '\\' . $value::class . '::' . $value->name,
        is_object($value) => 'new \\' . $value::class . '(…)',
        is_float($value) && floor($value) === $value && is_finite($value) => sprintf('%.1F', $value),
        // Control characters escaped as in PHP source, so "\r\n" does not break the line
        is_string($value) && preg_match('/[\x00-\x1f\x7f]/', $value) => '"' . preg_replace_callback(
            '/[\x00-\x1f\x7f]/',
            fn ($match) => match ($match[0]) {
                "\n" => '\n', "\r" => '\r', "\t" => '\t', "\v" => '\v', "\e" => '\e', "\f" => '\f', default => sprintf('\x%02X', ord($match[0])),
            },
            addcslashes($value, '\\"$'),
        ) . '"',
        default => var_export($value, true),
    };
}
