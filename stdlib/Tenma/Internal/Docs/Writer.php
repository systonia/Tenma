<?php

namespace Tenma\Internal\Docs;

final class Writer
{
    /** The page being written, for relative links. */
    private string $page = '';
    /** Names imported with `use` in the file of the type being written, lowercased alias → name. */
    private array $uses = [];
    private string $namespace = '';

    /**
     * @param array<string, \ReflectionClass> $classes
     * @param array<string, \ReflectionFunction> $functions
     */
    public function __construct(private array $classes, private array $functions = []) {}

    /** @return array<string, string> path relative to the output directory → content */
    public function pages(): array
    {
        $pages = [];
        $members = ['' => []];
        $functions = [];
        foreach ($this->functions as $function) {
            $functions[$function->getNamespaceName()][] = $function;
        }
        // A namespace with functions only gets an index too
        foreach ([...array_values($this->classes), ...array_values($this->functions)] as $class) {
            $namespace = $class->getNamespaceName();
            if ($class instanceof \ReflectionClass) {
                $pages[$this->path($class->getName())] = $this->type($class);
                $members[$namespace][] = $class;
            } else {
                $members[$namespace] ??= [];
            }
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
            $pages[$path] = $this->index($namespace, $classes, $functions[$namespace] ?? [], $children, $path);
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

    private function signature(\ReflectionFunctionAbstract $method): string
    {
        $modifiers = $method instanceof \ReflectionMethod ? \Reflection::getModifierNames($method->getModifiers()) : [];
        if ($method instanceof \ReflectionMethod && $method->getDeclaringClass()->isInterface()) {
            $modifiers = array_diff($modifiers, ['abstract']);
        }
        $parameters = array_map(fn ($parameter) => $this->parameter($parameter), $method->getParameters());
        $head = ltrim(implode(' ', $modifiers) . ' function ' . ($method->returnsReference() ? '&' : '') . ($method instanceof \ReflectionMethod ? $method->getName() : $method->getShortName()));
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

    /** A method, or a function of a namespace's index page. */
    private function method(\ReflectionFunctionAbstract $method): string
    {
        [$text, $tags] = parse($method instanceof \ReflectionMethod ? $this->methodDoc($method) : $method->getDocComment());
        $name = $method instanceof \ReflectionMethod ? $method->getName() : $method->getShortName();
        $out = '### ' . $name . "()\n\n```php\n" . $this->signature($method) . "\n```\n\n";
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
        } elseif ($method->getReturnType() !== null && !in_array((string) $method->getReturnType(), ['void', 'never'], true) && !($method instanceof \ReflectionMethod && $method->isConstructor())) {
            $out .= '**Returns** ' . $this->typeText($method->getReturnType()) . "\n\n";
        }
        $out .= $this->list('Throws', array_map(function ($body) {
            [$type, $description] = splitType($body);
            return $this->typeText($type) . ($description !== '' ? ": $description" : '');
        }, $this->tags($tags, 'throws')));
        $out .= $this->templates($tags);
        return $out . $this->otherTags($tags);
    }

    /** The `use` imports of the file that declares $class or a function, lowercased alias → name. */
    private function imports(\ReflectionClass|\ReflectionFunction $class): array
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

    /**
     * @param list<\ReflectionClass> $classes
     * @param list<\ReflectionFunction> $functions
     */
    private function index(string $namespace, array $classes, array $functions, array $children, string $path): string
    {
        $this->page = $path;
        $title = $namespace === '' ? 'Reference' : $namespace;
        $description = match (true) {
            $namespace === '' && $this->functions !== [] => 'Every documented class, interface, trait, enum and function, by namespace.',
            $namespace === '' => 'Every documented class, interface, trait and enum, by namespace.',
            $functions !== [] => "The classes, interfaces, traits, enums and functions in $namespace.",
            default => "The classes, interfaces, traits and enums in $namespace.",
        };
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
            $out .= "\n";
        }
        if ($functions !== []) {
            usort($functions, fn ($a, $b) => strcasecmp($a->getShortName(), $b->getShortName()));
            $out .= "## Functions\n\n";
            foreach ($functions as $function) {
                // Names in the docblock resolve against the function's own file
                $this->namespace = $function->getNamespaceName();
                $this->uses = $this->imports($function);
                $out .= $this->method($function);
            }
        }
        return rtrim($out) . "\n";
    }
}
