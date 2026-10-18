---
title: Language server
description: Run tenma lsp next to the editor's PHP server, and extend it from a package with Tenma\Lsp\Provider, Tenma\Lsp\Tokens, Tenma\Lsp\Imports, Tenma\Lsp\Variables and Tenma\Lsp\Elements.
order: 100
---

`tenma lsp` is a language server (LSP over stdin and stdout) that answers what the editor's own PHP language server cannot know, such as the class `$this` stands for in a template. It runs **next to** that server (Intelephense, PHP Tools, PhpStorm), not instead of it; the editor merges the answers of both.

Out of the box it shows the problems a running app reports with [`Tenma::diagnostics()`](diagnostics.md). Packages add the rest through a provider.

## Editor setup

**VS Code and VSCodium**: the [Tenma-VSCode](https://github.com/systonia/Tenma-VSCode) extension starts `tenma lsp` for PHP files in trusted workspaces (setting `tenma.lsp.enable`, binary in `tenma.executable`).

**Neovim** (0.11 or later):

```lua
vim.lsp.config('tenma', { cmd = { 'tenma', 'lsp' }, filetypes = { 'php' }, root_markers = { 'composer.json' } })
vim.lsp.enable('tenma')
```

Other editors: run `tenma lsp` as an additional language server for PHP files.

Each file belongs to the nearest directory with a `composer.json` above it, so one editor window can hold several projects.

## How it works

Two processes: the server keeps the open documents, and a worker per project (`tenma lsp --worker DIR`) loads the project's `vendor/autoload.php` and its providers and answers by reflection. PHP cannot load a changed class a second time, so saving a `.php` file ends the worker and the next question starts a new one: a class shows its new members once it is saved.

Loading the autoloader runs the project's code. Start `tenma lsp` only in projects you trust.

A provider that throws is logged on stderr and left out; one that takes longer than 5 seconds ends the worker.

## Writing a provider

A package names its provider class in its `composer.json`, and so can the project itself. Here the bakery adds one of its own:

```json
{
  "name": "systonia/waypoint-bakery",
  "autoload": { "psr-4": { "Bakery\\": "src/" } },
  "extra": { "tenma": { "lsp": "Bakery\\Editor" } }
}
```

Providers come from the project's own `composer.json` and from every installed package. Tenma creates each with the project's directory as the only constructor argument. A provider implements [`Tenma\Lsp\Provider`](../reference/Tenma/Lsp/Provider.md):

```php
<?php

namespace Bakery;

use Tenma\Lsp\Provider;

/**
 * Component templates run bound to their class: src/Components/OrderBoard.Template.php belongs to
 * Bakery\Components\OrderBoard. Product pictures in templates (/products/croissant.svg) complete, lead F12 to
 * the file in public/products and are flagged when the file is missing.
 */
final class Editor implements Provider
{
    public function __construct(private string $root) {}

    public function thisClass(string $file): ?string
    {
        if (!str_starts_with($file, "$this->root/src/Components/") || !str_ends_with($file, '.Template.php')) {
            return null;
        }
        $class = 'Bakery\\Components\\' . basename($file, '.Template.php');
        return class_exists($class) ? $class : null;
    }

    public function completions(string $file, string $text, int $offset): array
    {
        // After /products/ offer the pictures
        if (!preg_match('#/products/[\w-]*$#', substr($text, 0, $offset))) {
            return [];
        }
        return array_map(
            fn (string $path) => ['label' => basename($path), 'kind' => 'value', 'detail' => 'product picture'],
            glob("$this->root/public/products/*.svg"),
        );
    }

    public function definition(string $file, string $text, int $offset): ?array
    {
        // F12 on /products/croissant.svg opens public/products/croissant.svg
        $start = strrpos(substr($text, 0, $offset), '/products/');
        if ($start === false || !preg_match('#\G/products/([\w-]+\.svg)#', $text, $match, 0, $start)) {
            return null;
        }
        $target = "$this->root/public/products/$match[1]";
        return is_file($target) ? ['file' => $target, 'line' => 1] : null;
    }

    public function diagnostics(string $file, string $text): array
    {
        $problems = [];
        foreach (explode("\n", $text) as $index => $line) {
            preg_match_all('#/products/([\w-]+\.svg)#', $line, $found);
            foreach ($found[1] as $picture) {
                if (!is_file("$this->root/public/products/$picture")) {
                    $problems[] = ['line' => $index + 1, 'message' => "no picture public/products/$picture", 'severity' => 'error'];
                }
            }
        }
        return $problems;
    }
}
```

In the bakery itself, Waypoint Live's provider binds the component templates this way, and Waypoint's binds the views; a project only writes a provider for what no package knows.

The methods get absolute file paths, and `$text` is the editor's content, saved or not. Offsets and lengths count bytes.

| Method                               | Returns                                                                                                                                         |
| ------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| `thisClass($file)`                   | the class `$this` is bound to in a non-class file (a template), or `null`                                                                       |
| `completions($file, $text, $offset)` | a list of `['label' => …, 'kind' => 'method'\|'property'\|'class'\|'value'\|'keyword', 'detail' => …, 'insert' => …]`, all but `label` optional |
| `definition($file, $text, $offset)`  | `['file' => …, 'line' => …]` where the name at `$offset` is declared, or `null`                                                                 |
| `diagnostics($file, $text)`          | a list of `['line' => …, 'message' => …, 'severity' => 'error'\|'warning'\|'info']`, lines from 1                                               |

### What thisClass() gives you

When a provider names the class of a template, Tenma does the rest by reflection:

- completes `$this->` and `$this->prop->`, private members included (the template runs bound to the object),
- shows type and doc comment on hover and jumps to the declaration with F12,
- gives the variable of `foreach ($this->orders as $order)` (also `@foreach`) the element type from the PHPDoc of `orders` (`list<Order>`, `array<int, Order>` or `Order[]`), resolved through the class's `use` statements.
- gives a variable assigned in the file the type of what is assigned: `$order = $this->order();` the method's return type, `$order = $this->order;` the property's type, `$order = new Order(...);` the class. The last assignment or foreach above the cursor counts, not one in a comment or string; F12 on the variable leads to it. This works in plain `<?php $order = …; ?>` blocks as well as in a template syntax's own blocks.

## Semantic tokens

A provider that also implements [`Tenma\Lsp\Tokens`](../reference/Tenma/Lsp/Tokens.md) colours what the editor's grammar does not know, such as template directives:

```php
<?php

namespace Bakery;

use Tenma\Lsp\{Provider, Tokens};

final class Highlighter implements Provider, Tokens
{
    public function __construct(private string $root) {}

    public function thisClass(string $file): ?string { return null; }
    public function completions(string $file, string $text, int $offset): array { return []; }
    public function definition(string $file, string $text, int $offset): ?array { return null; }
    public function diagnostics(string $file, string $text): array { return []; }

    public function tokens(string $file, string $text): array
    {
        $tokens = [];
        foreach (explode("\n", $text) as $index => $line) {
            preg_match_all('/@(?:if|elseif|else|endif|foreach|endforeach|code|endcode|section|endsection|yield|use|model)\b/', $line, $found, PREG_OFFSET_CAPTURE);
            foreach ($found[0] as [$word, $column]) {
                $tokens[] = ['line' => $index + 1, 'column' => $column, 'length' => strlen($word), 'type' => 'keyword'];
            }
        }
        return $tokens;
    }
}
```

Lines count from 1; `column` is the byte offset within the line, from 0. Types: `keyword`, `comment`, `variable`, `property`, `method`, `function`, `class`, `string`, `number`, `operator`. Tokens may come in any order; overlapping ones are dropped.

## Imports

A template has no `use` statements of its own, so `OrderStatus::` in it would complete nothing. A provider that also implements [`Tenma\Lsp\Imports`](../reference/Tenma/Lsp/Imports.md) names the imports a file sees, here those of the template's class file:

```php
<?php

namespace Bakery;

use Tenma\Lsp\{Imports, Provider};

final class Editor implements Provider, Imports
{
    // thisClass(), completions(), definition() and diagnostics() as above

    public function imports(string $file, string $text): array
    {
        $class = $this->thisClass($file);
        if ($class === null) {
            return [];
        }
        // OrderBoard.Template.php sees what OrderBoard.php imports, and its namespace
        $source = (string) file_get_contents((new \ReflectionClass($class))->getFileName());
        preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?;/m', $source, $uses, PREG_SET_ORDER);
        $imports = ['' => (new \ReflectionClass($class))->getNamespaceName()];
        foreach ($uses as $use) {
            $imports[$use[2] ?? substr(strrchr('\\' . $use[1], '\\'), 1)] = $use[1];
        }
        return $imports;
    }
}
```

`imports($file, $text)` returns alias => fully qualified class name, and the namespace under the key `''`. Tenma resolves every class name in the file with them: completion after `Name::` (cases, constants, static properties and methods), hover and F12 on a class name before `::` or after `new` or `instanceof`, the types of loop and assigned variables (`new Order()`), and the `@var` and `@return` types it reads from doc comments.

The rule when both name something: the file's own `use` statements and `namespace` come first, and the provider fills in only what the file does not have. An alias the file imports itself keeps the file's class; a file without a `namespace` line takes the provider's. When several providers name the same alias, the first one wins.

## Variables

A template often gets more than `$this`: a model, say, as `$model`. A provider that also implements [`Tenma\Lsp\Variables`](../reference/Tenma/Lsp/Variables.md) names the class of such variables:

```php
<?php

namespace Bakery;

use Tenma\Lsp\{Provider, Variables};

final class Editor implements Provider, Variables
{
    // thisClass(), completions(), definition() and diagnostics() as above

    public function variables(string $file, string $text): array
    {
        // views/Shop/Product.php renders a ProductPage as $model
        return str_ends_with($file, '/views/Shop/Product.php') ? ['model' => \Bakery\Models\ProductPage::class] : [];
    }
}
```

`variables($file, $text)` returns variable name (without `$`) => fully qualified class name; a class that does not exist is left out. Tenma then treats such a variable as it treats `$this`: it completes `$model->` and `$model->product->` (public members), shows hover on the variable and its members, leads F12 to the declaration, gives `@foreach ($model->closed as $date => $holiday)` the element type of `closed` and `$product = $model->product;` the type of `product`. A file a provider types variables in counts as bound even without `thisClass()`. A `this` entry stands in for `thisClass()` when that returns `null`. Waypoint's provider does this for every view from its `@model` line.

The rule when both type a name: below an assignment or a loop in the file that introduces it, the file's own typing wins; above, the provider's. When several providers name the same variable, the first one wins.

## Elements

Tenma reads the element type of an array member from its PHPDoc (`@var list<OrderLine>`, `@return OrderLine[]`). A package that documents it another way, with attributes say, tells Tenma through [`Tenma\Lsp\Elements`](../reference/Tenma/Lsp/Elements.md). The bakery's `Order` names the class of its lines with Waypoint's `#[ListOf(OrderLine::class)]`, and Waypoint's provider reads it, shortened:

```php
<?php

use Tenma\Lsp\{Elements, Provider};
use Waypoint\Attributes\ListOf;

final class Editor implements Provider, Elements
{
    // thisClass(), completions(), definition() and diagnostics() as above

    public function elements(string $class): array
    {
        $elements = [];
        foreach (new \ReflectionClass($class)->getProperties() as $property) {
            foreach ($property->getAttributes(ListOf::class) as $listOf) {
                $elements[$property->getName()] = $listOf->newInstance()->type;
            }
        }
        // int, string, …: no class to complete
        return array_filter($elements, fn (string $type) => class_exists($type));
    }
}
```

`elements($class)` returns a property by its name and a method by its name followed by `()`, each => the fully qualified class of its elements; a class that does not exist is left out. Tenma then types the variable of `foreach ($order->lines as $line)` and `@foreach ($order->lines() as $line)`, also at the end of a chain (`$this->order->lines`).

Tenma asks every provider that implements `Elements`, whatever file is open and whether or not the provider binds it: an element type belongs to the class. It asks once per class and keeps the answer until the worker restarts (a saved `.php` file). The rule when both name a type: the member's own `@var` or `@return` PHPDoc wins when it names a class; the providers fill in where it names none. When several providers name the same member, the first one wins.

`extra.tenma.lsp` may also be a list of classes when a package has several providers.

See also: [`Tenma\Lsp\Provider`](../reference/Tenma/Lsp/Provider.md), [`Tenma\Lsp\Tokens`](../reference/Tenma/Lsp/Tokens.md), [`Tenma\Lsp\Imports`](../reference/Tenma/Lsp/Imports.md), [`Tenma\Lsp\Variables`](../reference/Tenma/Lsp/Variables.md), [`Tenma\Lsp\Elements`](../reference/Tenma/Lsp/Elements.md), [Diagnostics](diagnostics.md), [Debugger and editor setup](debugger.md).
