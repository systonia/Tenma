<?php
// `tenma docs [<path>...] [--out <dir>]`: writes Markdown reference pages for PHP classes, interfaces,
// traits and enums, one file per type at <out>/<Namespace path>/<ShortName>.md plus an index.md per
// namespace folder. With paths it documents the types declared in those files and directories (loaded
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
    $names = $paths === [] ? builtIn() : load($paths);
    $classes = [];
    foreach ($names as $name) {
        $class = new \ReflectionClass($name);
        if (!$class->isInternal() && !$class->isAnonymous() && !internal($class->getDocComment())) {
            $classes[$class->getName()] = $class;
        }
    }
    ksort($classes, SORT_STRING | SORT_FLAG_CASE);
    $pages = (new Writer($classes))->pages();
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
    echo count($classes), ' types, ', count($pages), " pages in $out ($written written, $removed removed)\n";
}

/** The class Tenma and everything under Tenma\ except Tenma\Internal: what `tenma --stubs` covers. */
function builtIn(): array
{
    return array_filter(
        [...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()],
        fn ($name) => $name === 'Tenma' || (str_starts_with($name, 'Tenma\\') && !str_starts_with($name, 'Tenma\\Internal\\')),
    );
}

/** Loads the types declared in $paths and returns their names. */
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
    foreach ($files as $file) {
        foreach (declarations((string) file_get_contents($file)) as $name) {
            $declared[$name] = realpath($file);
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
    return $loaded;
}

/** Names of the classes, interfaces, traits and enums a PHP file declares. */
function declarations(string $code): array
{
    $tokens = array_values(array_filter(\PhpToken::tokenize($code), fn ($token) => !$token->isIgnorable()));
    $namespace = '';
    $names = [];
    foreach ($tokens as $i => $token) {
        if ($token->is(T_NAMESPACE) && ($tokens[$i + 1] ?? null)?->is([T_STRING, T_NAME_QUALIFIED])) {
            $namespace = $tokens[$i + 1]->text . '\\';
        } elseif ($token->is(T_NAMESPACE) && ($tokens[$i + 1] ?? null)?->text === '{') {
            $namespace = '';
        } elseif ($token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM]) && ($tokens[$i + 1] ?? null)?->is(T_STRING)
            && !($tokens[$i - 1] ?? null)?->is([T_NEW, T_DOUBLE_COLON])) {
            $names[] = $namespace . $tokens[$i + 1]->text;
        }
    }
    return $names;
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
            fn ($match) => match ($match[0]) { "\n" => '\n', "\r" => '\r', "\t" => '\t', "\v" => '\v', "\e" => '\e', "\f" => '\f', default => sprintf('\x%02X', ord($match[0])) },
            addcslashes($value, '\\"$'),
        ) . '"',
        default => var_export($value, true),
    };
}

final class Writer
{
    /** The page being written, for relative links. */
    private string $page = '';
    /** Names imported with `use` in the file of the type being written, lowercased alias → name. */
    private array $uses = [];
    private string $namespace = '';

    /** @param array<string, \ReflectionClass> $classes */
    public function __construct(private array $classes) {}

    /** @return array<string, string> path relative to the output directory → content */
    public function pages(): array
    {
        $pages = [];
        $members = ['' => []];
        foreach ($this->classes as $name => $class) {
            $pages[$this->path($name)] = $this->type($class);
            $namespace = $class->getNamespaceName();
            $members[$namespace][] = $class;
            // Every folder on the way gets an index, also one without types of its own
            for ($parent = $namespace; $parent !== ''; $parent = substr($parent, 0, (int) strrpos($parent, '\\'))) {
                $members[$parent] ??= [];
                if (!str_contains($parent, '\\')) {
                    break;
                }
            }
        }
        ksort($members, SORT_STRING | SORT_FLAG_CASE);
        foreach ($members as $namespace => $classes) {
            $children = array_filter(array_keys($members), fn ($other) => $other !== '' && $this->parent($other) === $namespace);
            $path = $namespace === '' ? 'index.md' : str_replace('\\', '/', $namespace) . '/index.md';
            $pages[$path] = $this->index($namespace, $classes, $children, $path);
        }
        ksort($pages, SORT_STRING);
        return $pages;
    }

    private function parent(string $namespace): string
    {
        $at = strrpos($namespace, '\\');
        return $at === false ? '' : substr($namespace, 0, $at);
    }

    private function path(string $name): string
    {
        return str_replace('\\', '/', $name) . '.md';
    }

    /** A relative link from the current page to $target (a path below the output directory). */
    private function link(string $target): string
    {
        $from = explode('/', $this->page);
        array_pop($from);
        $to = explode('/', $target);
        while ($from !== [] && count($to) > 1 && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }
        return str_repeat('../', count($from)) . implode('/', $to);
    }

    private function kind(\ReflectionClass $class): string
    {
        if ($class->isInterface()) {
            return 'interface';
        }
        if ($class->isTrait()) {
            return 'trait';
        }
        if ($class->isEnum()) {
            return 'enum';
        }
        return ltrim(($class->isFinal() ? 'final ' : '') . ($class->isAbstract() ? 'abstract ' : '') . ($class->isReadOnly() ? 'readonly ' : '') . 'class');
    }

    /** A class name as Markdown: a link if it is documented here or a PHP class, code otherwise. */
    private function name(string $name): string
    {
        $name = ltrim($name, '\\');
        $short = substr($name, (int) strrpos($name, '\\') + (str_contains($name, '\\') ? 1 : 0));
        if (isset($this->classes[$name])) {
            return '[`' . $short . '`](' . $this->link($this->path($name)) . ')';
        }
        if ((class_exists($name, false) || interface_exists($name, false)) && (new \ReflectionClass($name))->isInternal()) {
            return '[`' . $name . '`](https://www.php.net/manual/en/class.' . strtolower(str_replace('\\', '-', $name)) . '.php)';
        }
        return '`' . $name . '`';
    }

    /** Resolves a class name written in a docblock against the type's namespace and imports. */
    private function resolve(string $name): ?string
    {
        if (str_starts_with($name, '\\')) {
            $name = substr($name, 1);
            return isset($this->classes[$name]) || class_exists($name, false) || interface_exists($name, false) ? $name : null;
        }
        $first = strtolower(explode('\\', $name)[0]);
        if (isset($this->uses[$first])) {
            return $this->uses[$first] . substr($name, strlen($first));
        }
        foreach ([$this->namespace === '' ? $name : "$this->namespace\\$name", $name] as $candidate) {
            if (isset($this->classes[$candidate]) || class_exists($candidate, false) || interface_exists($candidate, false)) {
                return $candidate;
            }
        }
        return null;
    }

    /** A type (reflection or docblock string) as Markdown, with the class names in it linked. */
    private function typeText(\ReflectionType|string|null $type): string
    {
        if ($type === null) {
            return '';
        }
        $text = is_string($type) ? $type : $this->code($type);
        $parts = preg_split('/(\'[^\']*\'|"[^"]*"|\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out = '';
        $code = '';
        foreach ($parts as $i => $part) {
            // Odd parts are names; a name that is no class (int, list, an array key) stays code
            $key = preg_match('/^\??:(?!:)/', $parts[$i + 1] ?? '') === 1;
            $class = $i % 2 === 1 && !str_contains('\'"', $part[0]) && !$key ? $this->resolve($part) : null;
            $known = $class !== null && (class_exists($class, false) || interface_exists($class, false));
            if ($class !== null && (isset($this->classes[$class]) || ($known && (new \ReflectionClass($class))->isInternal()))) {
                $out .= ($code !== '' ? '`' . $code . '`' : '') . $this->name($class);
                $code = '';
            } else {
                $code .= $part;
            }
        }
        return $out . ($code !== '' ? '`' . $code . '`' : '');
    }

    private function signature(\ReflectionMethod $method): string
    {
        $modifiers = \Reflection::getModifierNames($method->getModifiers());
        if ($method->getDeclaringClass()->isInterface()) {
            $modifiers = array_diff($modifiers, ['abstract']);
        }
        $parameters = array_map(fn ($parameter) => $this->parameter($parameter), $method->getParameters());
        $head = implode(' ', $modifiers) . ' function ' . $method->getName();
        $list = implode(', ', $parameters);
        if (strlen($head . $list) > 100) {
            $list = "\n    " . implode(",\n    ", $parameters) . ",\n";
        }
        $return = $method->getReturnType() !== null ? ': ' . $this->code($method->getReturnType()) : '';
        return "$head($list)$return";
    }

    /** A type for a code block: classes fully qualified. */
    private function code(?\ReflectionType $type): string
    {
        if ($type === null) {
            return '';
        }
        return preg_replace_callback('/(?<![\\\\\w])([A-Za-z_][\w\\\\]*)/', function ($match) {
            $name = $match[1];
            $builtin = in_array(strtolower($name), ['int', 'float', 'string', 'bool', 'array', 'callable', 'iterable', 'object', 'mixed', 'void', 'null', 'never', 'false', 'true', 'self', 'static', 'parent'], true);
            return $builtin ? $name : '\\' . $name;
        }, (string) $type);
    }

    private function parameter(\ReflectionParameter $parameter): string
    {
        $out = '';
        if ($parameter->isPromoted()) {
            $property = new \ReflectionProperty($parameter->getDeclaringClass()->getName(), $parameter->getName());
            $out .= implode(' ', $this->propertyModifiers($property)) . ' ';
        }
        $type = $this->code($parameter->getType());
        $out .= $type !== '' ? "$type " : '';
        $out .= ($parameter->isPassedByReference() ? '&' : '') . ($parameter->isVariadic() ? '...' : '') . '$' . $parameter->getName();
        if ($parameter->isDefaultValueAvailable()) {
            $out .= ' = ' . ($parameter->isDefaultValueConstant() ? constantName((string) $parameter->getDefaultValueConstantName()) : value($parameter->getDefaultValue()));
        }
        return $out;
    }

    private function propertyModifiers(\ReflectionProperty $property): array
    {
        $modifiers = array_diff(\Reflection::getModifierNames($property->getModifiers()), ['virtual']);
        // private(set) implies final and readonly implies protected(set); neither is written
        if (in_array('private(set)', $modifiers, true)) {
            $modifiers = array_diff($modifiers, ['final']);
        }
        if (in_array('readonly', $modifiers, true)) {
            $modifiers = array_diff($modifiers, ['protected(set)']);
        }
        return array_values($modifiers);
    }

    /** Whether a member belongs in the documentation: public, or protected in a class that can be extended. */
    private function visible(\ReflectionMethod|\ReflectionProperty|\ReflectionClassConstant $member, \ReflectionClass $class): bool
    {
        return ($member->isPublic() || ($member->isProtected() && !$class->isFinal() && !$class->isEnum()))
            && $member->getDeclaringClass()->getName() === $class->getName()
            && !internal($member->getDocComment());
    }

    /** The docblock of a method, or the one it implements or overrides when it has none. */
    private function methodDoc(\ReflectionMethod $method): string|false
    {
        $doc = $method->getDocComment();
        $class = $method->getDeclaringClass();
        foreach ([...($class->getParentClass() ? [$class->getParentClass()] : []), ...$class->getInterfaces()] as $ancestor) {
            if ($doc !== false && !preg_match('/\{@inheritDoc\}|@inheritDoc\b/i', $doc)) {
                break;
            }
            if ($ancestor->hasMethod($method->getName())) {
                $doc = $this->methodDoc($ancestor->getMethod($method->getName()));
            }
        }
        return $doc;
    }

    /** @deprecated, @since, @see and @link as Markdown; tags for tools (@codeCoverageIgnore, @phpstan-…) are left out. */
    private function otherTags(array $tags): string
    {
        $out = '';
        foreach ($tags as [$tag, $body]) {
            $out .= match ($tag) {
                'deprecated' => '> **Deprecated**' . ($body !== '' ? ": $body" : '') . "\n\n",
                'since' => "**Since** $body\n\n",
                'see', 'link' => "**See** $body\n\n",
                default => '',
            };
        }
        return $out;
    }

    private function tags(array $tags, string $name): array
    {
        return array_values(array_map(fn ($tag) => $tag[1], array_filter($tags, fn ($tag) => $tag[0] === $name)));
    }

    private function list(string $title, array $items): string
    {
        return $items === [] ? '' : "**$title**\n\n" . implode('', array_map(fn ($item) => "- $item\n", $items)) . "\n";
    }

    private function type(\ReflectionClass $class): string
    {
        $this->page = $this->path($class->getName());
        $this->namespace = $class->getNamespaceName();
        $this->uses = $this->imports($class);
        [$text, $tags] = parse($class->getDocComment());
        $kind = $this->kind($class);

        $out = "---\ntitle: " . yaml($class->getShortName()) . "\n";
        $description = sentence($text);
        if ($description !== '') {
            $out .= 'description: ' . yaml($description) . "\n";
        }
        $out .= "order: 100\ngenerated: true\n---\n\n";
        $namespace = $class->getNamespaceName();
        $out .= '`' . $class->getName() . "` · $kind" . ($namespace !== '' ? ' · namespace [`' . $namespace . '`](index.md)' : '') . "\n\n";

        $head = $kind . ' ' . $class->getShortName();
        if ($class->isEnum() && ($backing = (new \ReflectionEnum($class->getName()))->getBackingType()) !== null) {
            $head .= ": $backing";
        }
        $relations = [];
        if ($parent = $class->getParentClass()) {
            $head .= ' extends \\' . $parent->getName();
            $relations[] = 'Extends ' . $this->name($parent->getName());
        }
        $interfaces = array_values(array_diff($class->getInterfaceNames(), $parent ? $parent->getInterfaceNames() : [], ['UnitEnum', 'BackedEnum']));
        $interfaces = array_values(array_filter($interfaces, fn ($name) => !array_filter($interfaces, fn ($other) => $other !== $name && is_subclass_of($other, $name))));
        sort($interfaces);
        if ($interfaces !== []) {
            $head .= ($class->isInterface() ? ' extends ' : ' implements ') . implode(', ', array_map(fn ($name) => '\\' . $name, $interfaces));
            $relations[] = ($class->isInterface() ? 'Extends ' : 'Implements ') . implode(', ', array_map($this->name(...), $interfaces));
        }
        $traits = $class->getTraitNames();
        sort($traits);
        if ($traits !== []) {
            $relations[] = 'Uses ' . implode(', ', array_map($this->name(...), $traits));
        }
        $out .= "```php\n$head\n```\n\n";
        $out .= $relations !== [] ? implode('  ' . "\n", $relations) . "\n\n" : '';
        $out .= $text !== '' ? "$text\n\n" : '';
        $out .= $this->templates($tags);
        $out .= $this->otherTags($tags);

        if ($class->isEnum()) {
            $cases = '';
            foreach ((new \ReflectionEnum($class->getName()))->getCases() as $case) {
                if (internal($case->getDocComment())) {
                    continue;
                }
                [$caseText] = parse($case->getDocComment());
                $value = $case instanceof \ReflectionEnumBackedCase ? ' = ' . value($case->getBackingValue()) : '';
                $cases .= '### ' . $case->getName() . "\n\n```php\ncase " . $case->getName() . "$value;\n```\n\n" . ($caseText !== '' ? "$caseText\n\n" : '');
            }
            $out .= $cases !== '' ? "## Cases\n\n$cases" : '';
        }

        $constants = '';
        foreach ($class->getReflectionConstants() as $constant) {
            if ($constant->isEnumCase() || !$this->visible($constant, $class)) {
                continue;
            }
            [$constText, $constTags] = parse($constant->getDocComment());
            $var = $this->tags($constTags, 'var')[0] ?? null;
            if ($var !== null) {
                [$varType, $varText] = splitType($var);
                $constText = trim($varText . "\n\n" . $constText);
            }
            $modifiers = implode(' ', array_diff(\Reflection::getModifierNames($constant->getModifiers()), ['final']));
            $type = $constant->getType() !== null ? ' ' . $this->code($constant->getType()) : '';
            $constants .= '### ' . $constant->getName() . "\n\n```php\n$modifiers const$type " . $constant->getName() . ' = ' . value($constant->getValue()) . ";\n```\n\n"
                . ($var !== null ? '**Type** ' . $this->typeText($varType) . "\n\n" : '')
                . ($constText !== '' ? "$constText\n\n" : '');
        }
        $out .= $constants !== '' ? "## Constants\n\n$constants" : '';

        $constructor = $class->getConstructor();
        $constructorParams = $constructor !== null ? $this->params(parse($this->methodDoc($constructor))[1]) : [];
        $properties = '';
        foreach ($class->getProperties() as $property) {
            if (!$this->visible($property, $class) || ($class->isEnum() && in_array($property->getName(), ['name', 'value'], true))) {
                continue;
            }
            [$propText, $propTags] = parse($property->getDocComment());
            $var = $this->tags($propTags, 'var')[0] ?? null;
            $varType = null;
            if ($var !== null) {
                [$varType, $varText] = splitType($var);
                $varText = preg_replace('/^\$\w+\s*/', '', $varText);
                $propText = trim($varText . "\n\n" . $propText);
            }
            if ($propText === '' && $property->isPromoted()) {
                [$varType, $propText] = $constructorParams[$property->getName()] ?? [null, ''];
            }
            $line = implode(' ', $this->propertyModifiers($property));
            $line .= ($property->getType() !== null ? ' ' . $this->code($property->getType()) : '') . ' $' . $property->getName();
            if ($property->isPromoted()) {
                $parameter = array_values(array_filter($constructor->getParameters(), fn ($p) => $p->getName() === $property->getName()))[0];
                if ($parameter->isDefaultValueAvailable()) {
                    $line .= ' = ' . value($parameter->getDefaultValue());
                }
            } elseif ($property->hasDefaultValue() && ($property->getDefaultValue() !== null || $property->getType() === null || $property->getType()->allowsNull())) {
                $line .= ' = ' . value($property->getDefaultValue());
            }
            if ($property->hasHooks()) {
                $hooks = array_keys($property->getHooks());
                sort($hooks);
                $line .= ' { ' . implode(' ', array_map(fn ($hook) => "$hook;", $hooks)) . ' }';
            } else {
                $line .= ';';
            }
            $notes = [];
            if ($property->isReadOnly()) {
                $notes[] = 'read-only';
            }
            if ($property->hasHooks()) {
                $notes[] = 'hooks: ' . implode(', ', array_map(fn ($hook) => "`$hook`", array_keys($property->getHooks())));
            }
            $properties .= '### $' . $property->getName() . "\n\n```php\n$line\n```\n\n"
                . ($varType !== null && $varType !== '' ? '**Type** ' . $this->typeText($varType) . "\n\n" : '')
                . ($propText !== '' ? "$propText\n\n" : '');
        }
        $out .= $properties !== '' ? "## Properties\n\n$properties" : '';

        $methods = '';
        foreach ($class->getMethods() as $method) {
            if ($this->visible($method, $class) && !($class->isEnum() && in_array($method->getName(), ['cases', 'from', 'tryFrom'], true))) {
                $methods .= $this->method($method);
            }
        }
        $out .= $methods !== '' ? "## Methods\n\n$methods" : '';
        return rtrim($out) . "\n";
    }

    private function templates(array $tags): string
    {
        return $this->list('Template parameters', array_map(function ($body) {
            preg_match('/^(\S+)(?:\s+(?:of|as)\s+(\S+))?\s*(.*)$/s', $body, $match);
            return "`$match[1]`" . ($match[2] !== '' ? ' of ' . $this->typeText($match[2]) : '') . ($match[3] !== '' ? ": $match[3]" : '');
        }, $this->tags($tags, 'template')));
    }

    /** @return array<string, array{string, string}> parameter name → [type, description] from @param tags */
    private function params(array $tags): array
    {
        $params = [];
        foreach ($this->tags($tags, 'param') as $body) {
            if (preg_match('/^(?:(.*?)\s+)?(?:&\s*)?(?:\.\.\.\s*)?\$(\w+)\s*(.*)$/s', $body, $match)) {
                $params[$match[2]] = [trim($match[1]), trim($match[3])];
            }
        }
        return $params;
    }

    private function method(\ReflectionMethod $method): string
    {
        [$text, $tags] = parse($this->methodDoc($method));
        $out = '### ' . $method->getName() . "()\n\n```php\n" . $this->signature($method) . "\n```\n\n";
        $out .= $text !== '' ? "$text\n\n" : '';
        $params = $this->params($tags);
        $items = [];
        foreach ($method->getParameters() as $parameter) {
            [$type, $description] = $params[$parameter->getName()] ?? ['', ''];
            $type = $type !== '' ? $this->typeText($type) : $this->typeText($parameter->getType());
            $items[] = '`' . ($parameter->isVariadic() ? '...' : '') . '$' . $parameter->getName() . '`' . ($type !== '' ? " $type" : '') . ($description !== '' ? ": $description" : '');
        }
        $out .= $this->list('Parameters', $items);
        $return = $this->tags($tags, 'return')[0] ?? null;
        if ($return !== null) {
            [$type, $description] = splitType($return);
            $out .= '**Returns** ' . $this->typeText($type) . ($description !== '' ? ": $description" : '') . "\n\n";
        } elseif ($method->getReturnType() !== null && !in_array((string) $method->getReturnType(), ['void', 'never'], true) && !$method->isConstructor()) {
            $out .= '**Returns** ' . $this->typeText($method->getReturnType()) . "\n\n";
        }
        $out .= $this->list('Throws', array_map(function ($body) {
            [$type, $description] = splitType($body);
            return $this->typeText($type) . ($description !== '' ? ": $description" : '');
        }, $this->tags($tags, 'throws')));
        $out .= $this->templates($tags);
        return $out . $this->otherTags($tags);
    }

    /** The `use` imports of the file that declares $class, lowercased alias → name. */
    private function imports(\ReflectionClass $class): array
    {
        $file = $class->getFileName();
        if ($file === false || !is_file($file)) {
            return [];
        }
        $tokens = array_values(array_filter(\PhpToken::tokenize((string) file_get_contents($file)), fn ($token) => !$token->isIgnorable()));
        $uses = [];
        $depth = 0;
        foreach ($tokens as $i => $token) {
            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($token->text === '}') {
                $depth--;
            } elseif ($token->is(T_USE) && $depth === 0) {
                // use A\B; use A\B as C; use A\{B, C as D};
                $statement = '';
                for ($j = $i + 1; isset($tokens[$j]) && $tokens[$j]->text !== ';'; $j++) {
                    $statement .= $tokens[$j]->is(T_AS) ? ' as ' : ($tokens[$j]->is([T_FUNCTION, T_CONST]) ? 'skip ' : $tokens[$j]->text);
                }
                if (str_starts_with($statement, 'skip ')) {
                    continue;
                }
                $prefix = '';
                if (preg_match('/^(.*?)\{(.*)\}$/', $statement, $group)) {
                    $prefix = $group[1];
                    $statement = $group[2];
                }
                foreach (explode(',', $statement) as $part) {
                    [$name, $alias] = array_pad(explode(' as ', trim($part)), 2, null);
                    $name = ltrim($prefix . $name, '\\');
                    $alias ??= substr($name, (int) strrpos($name, '\\') + (str_contains($name, '\\') ? 1 : 0));
                    $uses[strtolower($alias)] = $name;
                }
            }
        }
        return $uses;
    }

    /** @param list<\ReflectionClass> $classes */
    private function index(string $namespace, array $classes, array $children, string $path): string
    {
        $this->page = $path;
        $title = $namespace === '' ? 'Reference' : $namespace;
        $description = $namespace === '' ? 'Every documented class, interface, trait and enum, by namespace.' : "The classes, interfaces, traits and enums in $namespace.";
        $out = "---\ntitle: " . yaml($title) . "\ndescription: " . yaml($description) . "\norder: " . ($namespace === '' ? 900 : 200) . "\ngenerated: true\n---\n\n";
        sort($children, SORT_STRING | SORT_FLAG_CASE);
        if ($children !== []) {
            $out .= "## Namespaces\n\n";
            foreach ($children as $child) {
                $out .= '- [`' . $child . '`](' . $this->link(str_replace('\\', '/', $child) . '/index.md') . ")\n";
            }
            $out .= "\n";
        }
        if ($classes !== []) {
            usort($classes, fn ($a, $b) => strcasecmp($a->getShortName(), $b->getShortName()));
            $out .= "## Types\n\n";
            foreach ($classes as $class) {
                $summary = sentence(parse($class->getDocComment())[0]);
                $out .= '- ' . $this->name($class->getName()) . ' · ' . $this->kind($class) . ($summary !== '' ? ": $summary" : '') . "\n";
            }
        }
        return rtrim($out) . "\n";
    }
}
