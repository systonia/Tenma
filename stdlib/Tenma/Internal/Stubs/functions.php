<?php

// `tenma --stubs`: prints Tenma's public PHP API (the namespaces of Tenma\Internal\NAMESPACES
// without members marked @internal) as a stub file for IDEs and static analysis. Signatures
// and doc comments come from reflection over the standard library this binary embeds; bodies are empty.

namespace Tenma\Internal\Stubs;

function type(?\ReflectionType $type): string
{
    if ($type === null) {
        return '';
    }
    if ($type instanceof \ReflectionUnionType) {
        return implode('|', array_map(fn ($part) => $part instanceof \ReflectionIntersectionType ? '(' . type($part) . ')' : type($part), $type->getTypes()));
    }
    if ($type instanceof \ReflectionIntersectionType) {
        return implode('&', array_map(type(...), $type->getTypes()));
    }
    /** @var \ReflectionNamedType $type */
    $name = $type->getName();
    $qualified = $type->isBuiltin() || in_array($name, ['self', 'static', 'parent'], true) ? $name : '\\' . $name;
    return ($type->allowsNull() && $name !== 'mixed' && $name !== 'null' ? '?' : '') . $qualified;
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
        is_float($value) && floor($value) === $value && is_finite($value) => sprintf('%.1F', $value),
        default => var_export($value, true),
    };
}

function doc(\ReflectionClass|\ReflectionMethod|\ReflectionProperty|\ReflectionClassConstant $member, string $indent): string
{
    $doc = $member->getDocComment();
    if ($doc === false) {
        return '';
    }
    $lines = array_map('trim', explode("\n", $doc));
    return implode("\n", array_map(fn ($line, $i) => $indent . ($i > 0 ? ' ' : '') . $line, $lines, array_keys($lines))) . "\n";
}

function internal(\ReflectionClass|\ReflectionMethod|\ReflectionProperty|\ReflectionClassConstant $member): bool
{
    return str_contains((string) $member->getDocComment(), '@internal');
}

function parameter(\ReflectionParameter $parameter, bool $promoted): string
{
    $out = '';
    if ($promoted && $parameter->isPromoted()) {
        $property = new \ReflectionProperty($parameter->getDeclaringClass()->getName(), $parameter->getName());
        $out .= implode(' ', \Reflection::getModifierNames($property->getModifiers())) . ' ';
    }
    $type = type($parameter->getType());
    $out .= $type !== '' ? "$type " : '';
    $out .= ($parameter->isPassedByReference() ? '&' : '') . ($parameter->isVariadic() ? '...' : '') . '$' . $parameter->getName();
    if ($parameter->isDefaultValueAvailable()) {
        $out .= ' = ' . ($parameter->isDefaultValueConstant() ? '\\' . ltrim((string) $parameter->getDefaultValueConstantName(), '\\') : value($parameter->getDefaultValue()));
    }
    return $out;
}

function method(\ReflectionMethod $method): string
{
    $modifiers = array_diff(\Reflection::getModifierNames($method->getModifiers()), ['abstract']);
    $promoted = $method->isConstructor();
    $parameters = array_map(fn ($parameter) => parameter($parameter, $promoted), $method->getParameters());
    $signature = implode(' ', $modifiers) . ' function ' . $method->getName() . '(' . implode(', ', $parameters) . ')';
    $return = type($method->getReturnType());
    return doc($method, '        ') . '        ' . $signature . ($return !== '' ? ": $return" : '') . ($method->isAbstract() ? ";\n" : " {}\n");
}

function render(\ReflectionClass $class): string
{
    $kind = $class->isInterface() ? 'interface' : 'class';
    $modifiers = [];
    if ($class->isFinal()) {
        $modifiers[] = 'final';
    }
    if ($class->isAbstract() && !$class->isInterface()) {
        $modifiers[] = 'abstract';
    }
    if ($class->isReadOnly()) {
        $modifiers[] = 'readonly';
    }
    $head = ltrim(implode(' ', $modifiers) . " $kind " . $class->getShortName());
    if ($parent = $class->getParentClass()) {
        $head .= ' extends \\' . $parent->getName();
    }
    $interfaces = array_diff($class->getInterfaceNames(), $class->getParentClass() ? $class->getParentClass()->getInterfaceNames() : []);
    if ($interfaces !== [] && !$class->isInterface()) {
        $head .= ' implements ' . implode(', ', array_map(fn ($name) => '\\' . $name, $interfaces));
    }

    $body = [];
    foreach ($class->getReflectionConstants(\ReflectionClassConstant::IS_PUBLIC) as $constant) {
        if ($constant->getDeclaringClass()->getName() === $class->getName() && !internal($constant)) {
            $body[] = doc($constant, '        ') . '        public const ' . $constant->getName() . ' = ' . value($constant->getValue()) . ";\n";
        }
    }
    foreach ($class->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
        if ($property->getDeclaringClass()->getName() !== $class->getName() || $property->isPromoted() || internal($property)) {
            continue;
        }
        $type = type($property->getType());
        $line = implode(' ', \Reflection::getModifierNames($property->getModifiers())) . ($type !== '' ? " $type" : '') . ' $' . $property->getName();
        if ($property->hasDefaultValue() && !$property->isReadOnly() && ($property->getDefaultValue() !== null || $property->getType()?->allowsNull())) {
            $line .= ' = ' . value($property->getDefaultValue());
        }
        $body[] = doc($property, '        ') . "        $line;\n";
    }
    foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() === $class->getName() && !internal($method)) {
            $body[] = method($method);
        }
    }
    return doc($class, '    ') . "    $head\n    {\n" . implode("\n", $body) . "    }\n";
}

/** Prints the stubs of the public API. */
/** The header of every stub file. */
function header(): string
{
    return "<?php\n\n// Tenma's PHP API for IDEs and static analysis, generated by `tenma --stubs` (Tenma " . VERSION . ").\n"
        . "// Stubs only: Tenma provides the real classes at runtime. Do not edit; regenerate.\n";
}

/** @return array<string, array<string, string>> Namespace => short name => the stub of the class. */
function stubs(): array
{
    $byNamespace = [];
    foreach ([...get_declared_classes(), ...get_declared_interfaces()] as $name) {
        $class = new \ReflectionClass($name);
        if ($class->isInternal() || !\Tenma\Internal\isPublic($name) || internal($class)) {
            continue;
        }
        $byNamespace[$class->getNamespaceName()][$class->getShortName()] = render($class);
    }
    ksort($byNamespace);
    foreach ($byNamespace as &$classes) {
        ksort($classes);
    }
    return $byNamespace;
}

/**
 * Prints the stubs as one file, or writes one file per class below $directory: Http/Request.php,
 * Threading/Task.php and so on. Files of classes that are gone are not removed.
 */
function main(?string $directory = null): void
{
    if ($directory === null) {
        echo header();
        foreach (stubs() as $namespace => $classes) {
            $name = $namespace !== '' ? " $namespace" : '';
            echo "\nnamespace$name {\n" . implode("\n", $classes) . "}\n";
        }
        return;
    }

    foreach (stubs() as $namespace => $classes) {
        $folder = rtrim($directory . '/' . str_replace('\\', '/', $namespace), '/');
        if (!is_dir($folder) && !mkdir($folder, 0777, true)) {
            throw new \RuntimeException("cannot create $folder");
        }
        foreach ($classes as $short => $stub) {
            $code = header() . "\n";
            if ($namespace !== '') {
                $code .= "namespace $namespace;\n\n";
            }
            // render() indents for a namespace block
            $code .= preg_replace('/^    /m', '', $stub);
            file_put_contents("$folder/$short.php", $code);
        }
    }
}
