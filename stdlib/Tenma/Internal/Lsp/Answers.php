<?php

namespace Tenma\Internal\Lsp;

use Lsp\Provider;

/** The worker's answers: what the providers say, and `$this->` from the class they name. */
final class Answers
{
    /** @param list<Provider> $providers */
    public function __construct(private readonly array $providers) {}

    public function answer(string $method, array $args): mixed
    {
        return match ($method) {
            'completions' => $this->completions(...$args),
            'definition' => $this->definition(...$args),
            'hover' => $this->hover(...$args),
            'diagnostics' => $this->collect(static fn (Provider $p) => $p->diagnostics(...$args)),
            'tokens' => $this->collect(static fn (Provider $p) => $p instanceof \Lsp\Tokens ? $p->tokens(...$args) : []),
            default => null,
        };
    }

    private function completions(string $file, string $text, int $offset): array
    {
        $items = $this->collect(static fn (Provider $p) => $p->completions($file, $text, $offset));
        $before = substr($text, 0, $offset);
        $types = $this->types($file, $text);
        if (preg_match('/\$(\w+)((?:->\w+(?:\([^()]*\))?)*)->\w*$/', $before, $m) === 1) {
            $root = $this->variable($file, $text, $before, $m[1]);
            $target = $root === null ? null : $this->follow($root, $m[2]);
            if ($target !== null) {
                array_push($items, ...self::members($target, $target->getName() === ($types['this'] ?? null)));
            }
        }
        if (preg_match('/(?<![\w\\\\$>:])(\\\\?[A-Za-z_][\w\\\\]*)::\$?\w*$/', $before, $m) === 1 && $types !== []) {
            $class = $this->resolve($m[1], $file, $text);
            if ($class !== null) {
                array_push($items, ...self::statics(new \ReflectionClass($class), $class === ($types['this'] ?? null)));
            }
        }
        return $items;
    }

    private function definition(string $file, string $text, int $offset): ?array
    {
        foreach ($this->providers as $provider) {
            $place = $this->guard(static fn () => $provider->definition($file, $text, $offset));
            if (is_array($place)) {
                return $place;
            }
        }
        $member = $this->member($file, $text, $offset);
        if ($member instanceof \ReflectionMethod) {
            return ['file' => (string) $member->getFileName(), 'line' => (int) $member->getStartLine()];
        }
        if ($member instanceof \ReflectionProperty) {
            $owner = self::propertyOwner($member);
            return ['file' => (string) $owner->getFileName(), 'line' => self::propertyLine($owner, $member->getName())];
        }
        $class = $this->classAt($file, $text, $offset);
        if ($class !== null) {
            return ['file' => (string) $class->getFileName(), 'line' => (int) $class->getStartLine()];
        }
        // In a class file the editor's PHP server knows the variable; a template's @foreach or assignment it does not
        return $this->types($file, $text) === [] ? null : self::introduction($file, $text, $offset);
    }

    /**
     * For a plain `$name` at $offset: the last foreach (as value or key) or assignment above that introduces it,
     * not one in a comment or string.
     */
    private static function introduction(string $file, string $text, int $offset): ?array
    {
        preg_match('/^\$?\w*/', substr($text, $offset), $rest);
        $before = substr($text, 0, $offset);
        if (preg_match('/\$(\w*)$/', $before . $rest[0], $m) !== 1 || $m[1] === '' || $m[1] === 'this') {
            return null;
        }
        $at = $offset + strlen($rest[0]);
        $name = preg_quote($m[1], '/');
        $pattern = '/\bas\s+(?:\$\w+\s*=>\s*)?&?\$' . $name . '\b|\bas\s+&?\$' . $name . '\s*=>|\$' . $name . '\b\s*=(?![=>])/';
        if (preg_match_all($pattern, self::code(substr($text, 0, $at)), $found, PREG_OFFSET_CAPTURE) === 0) {
            return null;
        }
        return ['file' => $file, 'line' => substr_count($text, "\n", 0, end($found[0])[1]) + 1];
    }

    /** Comments and strings of the PHP parts blanked out, offsets kept; a template's own syntax is inline HTML. */
    private static function code(string $text): string
    {
        $code = '';
        foreach (token_get_all($text) as $token) {
            $hidden = is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true);
            $code .= $hidden ? (string) preg_replace('/[^\n]/', ' ', $token[1]) : (is_array($token) ? $token[1] : $token);
        }
        return $code;
    }

    private function hover(string $file, string $text, int $offset): ?string
    {
        $member = $this->member($file, $text, $offset) ?? $this->classAt($file, $text, $offset);
        if ($member === null) {
            return $this->typeAt($file, $text, $offset);
        }
        $doc = trim((string) preg_replace('#^\s*/\*\*|\*/\s*$|^\s*\* ?#m', '', (string) $member->getDocComment()));
        $head = $member instanceof \ReflectionClass ? self::kind($member) . ' ' . $member->getName() : self::signature($member);
        return "```php\n$head\n```" . ($doc !== '' ? "\n\n$doc" : '');
    }

    /** The hover of a plain `$name` at $offset that has a class: the class and the name. */
    private function typeAt(string $file, string $text, int $offset): ?string
    {
        preg_match('/^\w*/', substr($text, $offset), $rest);
        if (preg_match('/\$(\w+)$/', substr($text, 0, $offset) . $rest[0], $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $class = $this->variable($file, $text, substr($text, 0, $m[0][1]), $m[1][0]);
        return $class === null ? null : "```php\n{$class->getName()} \${$m[1][0]}\n```";
    }

    /**
     * In a file a provider binds: the class named at $offset where PHP code names one, before `::` or after
     * `new` or `instanceof`; plain words of the template stay words.
     */
    private function classAt(string $file, string $text, int $offset): ?\ReflectionClass
    {
        preg_match('/[\w\\\\]*$/', substr($text, 0, $offset), $head);
        preg_match('/^[\w\\\\]*/', substr($text, $offset), $tail);
        $name = $head[0] . $tail[0];
        $before = substr($text, 0, $offset - strlen($head[0]));
        $after = substr($text, $offset + strlen($tail[0]));
        if (preg_match('/^\\\\?[A-Za-z_]/', $name) !== 1 || preg_match('/(?:\$|->|::)$/', $before) === 1
            || (preg_match('/^\s*::/', $after) !== 1 && preg_match('/\b(?:new|instanceof)\s+$/', $before) !== 1)
            || $this->types($file, $text) === []) {
            return null;
        }
        $class = $this->resolve($name, $file, $text);
        return $class === null ? null : new \ReflectionClass($class);
    }

    private static function kind(\ReflectionClass $class): string
    {
        return match (true) {
            $class->isEnum() => 'enum',
            $class->isInterface() => 'interface',
            $class->isTrait() => 'trait',
            default => ltrim(($class->isFinal() ? 'final ' : '') . ($class->isAbstract() ? 'abstract ' : '') . 'class'),
        };
    }

    /** The method or property named at $offset in a `$this->a->b()->c` chain. */
    private function member(string $file, string $text, int $offset): \ReflectionMethod|\ReflectionProperty|null
    {
        preg_match('/^\w*/', substr($text, $offset), $rest);
        $before = substr($text, 0, $offset);
        if (preg_match('/\$(\w+)((?:->\w+(?:\([^()]*\))?)*)->(\w*)$/', $before . $rest[0], $m) !== 1 || $m[3] === '') {
            return null;
        }
        $root = $this->variable($file, $text, $before, $m[1]);
        $target = $root === null ? null : $this->follow($root, $m[2]);
        return match (true) {
            $target === null => null,
            $target->hasMethod($m[3]) => $target->getMethod($m[3]),
            $target->hasProperty($m[3]) => $target->getProperty($m[3]),
            default => null,
        };
    }

    private function thisClass(string $file): ?string
    {
        foreach ($this->providers as $provider) {
            $class = $this->guard(static fn () => $provider->thisClass($file));
            if (is_string($class) && class_exists($class)) {
                return $class;
            }
        }
        return null;
    }

    /**
     * @return array<string, string> The variables the providers type in $file, by name: `$this` from thisClass(),
     *     the others from Lsp\Variables (the first provider wins); empty in a file no provider binds
     */
    private function types(string $file, string $text): array
    {
        $types = [];
        $bound = $this->thisClass($file);
        if ($bound !== null) {
            $types['this'] = $bound;
        }
        foreach ($this->providers as $provider) {
            $given = $provider instanceof \Lsp\Variables ? $this->guard(static fn () => $provider->variables($file, $text)) : null;
            foreach (is_array($given) ? $given : [] as $name => $class) {
                $class = is_string($class) ? trim($class, '\\') : '';
                if (class_exists($class) || interface_exists($class)) {
                    $types[ltrim((string) $name, '$')] ??= $class;
                }
            }
        }
        return $types;
    }

    /**
     * The class of `$name` in the text before the cursor. `$this` is the class a provider binds, and a variable
     * a provider types (Lsp\Variables) has that class unless the file introduces it above. In a file a
     * provider binds, another variable gets its type from the last foreach or assignment above that introduces
     * it: a foreach over `$this->items` (or another such variable) gives the element type of `items`, from its
     * PHPDoc (`list<Item>`, `array<int, Item>`, `Item[]`); `$name = $this->item;`, `$name = $this->item();` and
     * `$name = new Item(...);` give the declared type.
     */
    private function variable(string $file, string $text, string $before, string $name, int $depth = 0): ?\ReflectionClass
    {
        $types = $this->types($file, $text);
        if ($name === 'this') {
            return isset($types['this']) ? new \ReflectionClass($types['this']) : null;
        }
        if ($depth > 3 || $types === []) {
            return null;
        }
        $chain = '\$(\w+)((?:->\w+(?:\([^()]*\))?)*)';
        $n = preg_quote($name, '/');
        $pattern = '/foreach\s*\(\s*' . $chain . '\s+as\s+(?:\$\w+\s*=>\s*)?&?\$' . $n . '\s*\)'
            . '|\$' . $n . '\b\s*=(?![=>])\s*(?:new\s+(\\\\?[\w\\\\]+)\s*(?:\([^()]*\))?\s*(?:;|\?>)|' . $chain . '\s*(?:;|\?>))?/';
        $code = self::code($before);
        if (preg_match_all($pattern, $code, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL) === 0) {
            return isset($types[$name]) ? new \ReflectionClass($types[$name]) : null;
        }
        $last = end($found);
        $above = substr($code, 0, $last[0][1]);
        if ($last[1][0] !== null) {
            // A foreach: the element type of the last member in the chain
            if (preg_match('/^(.*)->(\w+)(?:\([^()]*\))?$/', $last[2][0], $split) !== 1) {
                return null;
            }
            $root = $this->variable($file, $text, $above, $last[1][0], $depth + 1);
            $owner = $root === null ? null : $this->follow($root, $split[1]);
            $member = match (true) {
                $owner === null => null,
                $owner->hasProperty($split[2]) => $owner->getProperty($split[2]),
                $owner->hasMethod($split[2]) => $owner->getMethod($split[2]),
                default => null,
            };
            $element = $member === null ? null : $this->elementClass($member);
            return $element === null ? null : new \ReflectionClass($element);
        }
        if ($last[3][0] !== null) {
            $class = $this->resolve($last[3][0], $file, $text);
            return $class === null ? null : new \ReflectionClass($class);
        }
        // An assignment of something else (a string, a sum) leaves no class
        $root = $last[4][0] === null ? null : $this->variable($file, $text, $above, $last[4][0], $depth + 1);
        return $root === null ? null : $this->follow($root, (string) $last[5][0]);
    }

    /**
     * The class of the elements a property or method holds: from `@var` or `@return`, else (no such PHPDoc, or
     * one that names no class) from the providers' Lsp\Elements for the class that declares it.
     */
    private function elementClass(\ReflectionProperty|\ReflectionMethod $member): ?string
    {
        $tag = $member instanceof \ReflectionProperty ? '@var' : '@return';
        preg_match('/' . $tag . '\s+((?:[^\s<>{}]|<(?:[^<>]|<[^<>]*>)*>|\{[^}]*\})+)/', (string) $member->getDocComment(), $m);
        foreach (isset($m[1]) ? explode('|', ltrim($m[1], '?')) : [] as $type) {
            $element = match (true) {
                str_ends_with($type, '[]') => substr($type, 0, -2),
                preg_match('/^[\w\\\\-]+<(.*)>$/', $type, $generic) === 1 => trim((string) preg_replace('/^(?:[^,<]|<[^>]*>)*,/', '', $generic[1])),
                default => null,
            };
            $file = (string) $member->getDeclaringClass()->getFileName();
            $class = $element === null ? null : $this->resolve($element, $file, (string) @file_get_contents($file));
            if ($class !== null) {
                return $class;
            }
        }
        $key = $member->getName() . ($member instanceof \ReflectionMethod ? '()' : '');
        return $this->elements($member->getDeclaringClass()->getName())[$key] ?? null;
    }

    /** @var array<string, array<string, string>> class => its members' element classes from the providers */
    private array $elements = [];

    /**
     * @return array<string, string> What the providers' Lsp\Elements say about $class: a property by its name,
     *     a method by its name and `()`, => the class of its elements (the first provider wins). Every provider is
     *     asked, not only those that bind the file: the type belongs to the class. A saved class ends the worker,
     *     so the cache cannot go stale.
     */
    private function elements(string $class): array
    {
        if (isset($this->elements[$class])) {
            return $this->elements[$class];
        }
        $elements = [];
        foreach ($this->providers as $provider) {
            $given = $provider instanceof \Lsp\Elements ? $this->guard(static fn () => $provider->elements($class)) : null;
            foreach (is_array($given) ? $given : [] as $member => $element) {
                $element = is_string($element) ? trim($element, '\\') : '';
                if (class_exists($element) || interface_exists($element)) {
                    $elements[(string) $member] ??= $element;
                }
            }
        }
        return $this->elements[$class] = $elements;
    }

    /** A class name written in $file, resolved like PHP does: `use` statements, then the namespace. */
    private function resolve(string $name, string $file, string $text): ?string
    {
        if (str_starts_with($name, '\\')) {
            $class = substr($name, 1);
        } else {
            [$first] = explode('\\', $name, 2);
            $imports = $this->imports($file, $text);
            $imported = $imports[strtolower($first)] ?? null;
            $namespace = $imports[''] ?? '';
            $class = $imported !== null ? $imported . substr($name, strlen($first)) : ($namespace !== '' ? "$namespace\\$name" : $name);
        }
        return class_exists($class) || interface_exists($class) ? $class : null;
    }

    /**
     * @return array<string, string> The names a file sees, by lower-case alias, its namespace under '': its own `use`
     *     statements and namespace, and for what it has not, the providers' Lsp\Imports (the first one wins)
     */
    private function imports(string $file, string $text): array
    {
        $imports = self::own($text);
        foreach ($this->providers as $provider) {
            $given = $provider instanceof \Lsp\Imports ? $this->guard(static fn () => $provider->imports($file, $text)) : null;
            foreach (is_array($given) ? $given : [] as $alias => $class) {
                if (is_string($class)) {
                    $imports[strtolower((string) $alias)] ??= trim($class, '\\');
                }
            }
        }
        return $imports;
    }

    /** @var array<string, array<string, string>> hash of a text => its imports */
    private static array $own = [];

    /** @return array<string, string> The `use` statements of a text by lower-case alias, its namespace under '' */
    private static function own(string $text): array
    {
        $key = md5($text);
        if (isset(self::$own[$key])) {
            return self::$own[$key];
        }
        // Every keystroke is a new text
        if (count(self::$own) > 100) {
            self::$own = [];
        }
        $imports = [];
        // ponytail: one namespace per file and no function or const imports, as PSR-4 files are
        // The code up to the first declaration, without comments: "class" in a comment is no declaration
        $code = '';
        foreach (token_get_all($text) as $token) {
            if (is_array($token) && in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                break;
            }
            $code .= is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : (is_array($token) ? $token[1] : $token);
        }
        if (preg_match('/\bnamespace\s+([\w\\\\]+)\s*[;{]/', $code, $namespace) === 1) {
            $imports[''] = trim($namespace[1], '\\');
        }
        preg_match_all('/\buse\s+([\w\\\\]+)(?:\s*\{([^}]*)\}|\s+as\s+(\w+))?\s*;/', $code, $uses, PREG_SET_ORDER);
        foreach ($uses as $use) {
            $prefix = trim($use[1], '\\');
            foreach (isset($use[2]) && $use[2] !== '' ? explode(',', $use[2]) : [''] as $part) {
                $part = trim($part);
                [$name, $alias] = preg_match('/^([\w\\\\]+)\s+as\s+(\w+)$/', $part, $as) === 1 ? [$as[1], $as[2]] : [$part, null];
                $class = $name === '' ? $prefix : "$prefix\\$name";
                $imports[strtolower($alias ?? ($use[3] ?? '') ?: substr((string) strrchr('\\' . $class, '\\'), 1))] = $class;
            }
        }
        return self::$own[$key] = $imports;
    }

    /** The class at the end of `->a->b` (or `->a()`): the declared types of properties and of methods' returns. */
    private function follow(\ReflectionClass $target, string $chain): ?\ReflectionClass
    {
        foreach (array_filter(explode('->', (string) preg_replace('/\([^()]*\)/', '', $chain))) as $name) {
            $type = match (true) {
                $target->hasProperty($name) => $target->getProperty($name)->getType(),
                $target->hasMethod($name) => $target->getMethod($name)->getReturnType(),
                default => null,
            };
            if (!$type instanceof \ReflectionNamedType || ($type->getName() !== 'self' && $type->getName() !== 'static' && ($type->isBuiltin() || !class_exists($type->getName())))) {
                return null;
            }
            $target = in_array($type->getName(), ['self', 'static'], true) ? $target : new \ReflectionClass($type->getName());
        }
        return $target;
    }

    /** @return list<array{label: string, kind: string, detail: string}> */
    private static function members(\ReflectionClass $class, bool $inside): array
    {
        $items = [];
        foreach ($class->getProperties() as $property) {
            if (!$property->isStatic() && ($inside || $property->isPublic())) {
                $items[] = ['label' => $property->getName(), 'kind' => 'property', 'detail' => self::signature($property)];
            }
        }
        foreach ($class->getMethods() as $method) {
            if (!$method->isStatic() && !str_starts_with($method->getName(), '__') && ($inside || $method->isPublic())) {
                $items[] = ['label' => $method->getName(), 'kind' => 'method', 'detail' => self::signature($method)];
            }
        }
        return $items;
    }

    /** @return list<array{label: string, kind: string, detail: string}> What `Name::` offers: cases, constants, statics */
    private static function statics(\ReflectionClass $class, bool $inside): array
    {
        $items = [];
        foreach ($class->getReflectionConstants() as $constant) {
            if ($inside || $constant->isPublic()) {
                $items[] = ['label' => $constant->getName(), 'kind' => 'value', 'detail' => ($constant->isEnumCase() ? 'case ' : 'const ') . $constant->getName()];
            }
        }
        foreach ($class->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
            if ($inside || $property->isPublic()) {
                $items[] = ['label' => '$' . $property->getName(), 'kind' => 'property', 'detail' => self::signature($property)];
            }
        }
        foreach ($class->getMethods(\ReflectionMethod::IS_STATIC) as $method) {
            if ($inside || $method->isPublic()) {
                $items[] = ['label' => $method->getName(), 'kind' => 'method', 'detail' => self::signature($method)];
            }
        }
        return $items;
    }

    private static function signature(\ReflectionMethod|\ReflectionProperty $member): string
    {
        $modifiers = implode(' ', \Reflection::getModifierNames($member->getModifiers()));
        if ($member instanceof \ReflectionProperty) {
            return trim("$modifiers {$member->getType()} \${$member->getName()}");
        }
        $parameters = array_map(static fn (\ReflectionParameter $p) => trim("{$p->getType()} " . ($p->isVariadic() ? '...' : '') . "\${$p->getName()}"), $member->getParameters());
        $return = $member->getReturnType();
        return "$modifiers function {$member->getName()}(" . implode(', ', $parameters) . ')' . ($return !== null ? ": $return" : '');
    }

    /** Where a property is written: getDeclaringClass() names the class that uses a trait, not the trait. */
    private static function propertyOwner(\ReflectionProperty $property): \ReflectionClass
    {
        $owner = $property->getDeclaringClass();
        do {
            $traits = array_filter($owner->getTraits(), static fn (\ReflectionClass $trait) => $trait->hasProperty($property->getName()));
            $owner = $traits ? reset($traits) : $owner;
        } while ($traits);
        return $owner;
    }

    /**
     * Reflection knows no line for a property: the first `$name` in the declaration of $class.
     *
     * ponytail: a method parameter of the same name above the property wins; good enough while properties come first
     */
    private static function propertyLine(\ReflectionClass $class, string $name): int
    {
        foreach (token_get_all((string) file_get_contents((string) $class->getFileName())) as $token) {
            if (is_array($token) && $token[0] === T_VARIABLE && $token[1] === '$' . $name && $token[2] >= $class->getStartLine() && $token[2] <= $class->getEndLine()) {
                return $token[2];
            }
        }
        return (int) $class->getStartLine();
    }

    /** Every provider's list, one after another; a failing provider is logged and left out. */
    private function collect(\Closure $ask): array
    {
        $all = [];
        foreach ($this->providers as $provider) {
            array_push($all, ...array_values((array) $this->guard(static fn () => $ask($provider))));
        }
        return $all;
    }

    private function guard(\Closure $work): mixed
    {
        try {
            return $work();
        } catch (\Throwable $e) {
            log($e::class . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return null;
        }
    }
}
