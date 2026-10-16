---
title: Language server
description: Run tenma lsp next to the editor's PHP server, and extend it from a package with Tenma\Lsp\Provider and Tenma\Lsp\Tokens.
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

A package names its provider class in its `composer.json`:

```json
{
    "name": "acme/views",
    "autoload": { "psr-4": { "Acme\\Views\\": "src/" } },
    "extra": { "tenma": { "lsp": "Acme\\Views\\Editor" } }
}
```

Providers come from the project's own `composer.json` and from every installed package. Tenma creates each with the project's directory as the only constructor argument. A provider implements [`Tenma\Lsp\Provider`](../reference/Tenma/Lsp/Provider.md):

```php
<?php

namespace Acme\Views;

use Tenma\Lsp\Provider;

/** Templates in views/ run bound to their view class: views/cart.php belongs to Acme\Views\Cart. */
final class Editor implements Provider
{
    public function __construct(private string $root) {}

    public function thisClass(string $file): ?string
    {
        if (!str_starts_with($file, "$this->root/views/")) {
            return null;
        }
        $class = 'Acme\\Views\\' . ucfirst(basename($file, '.php'));
        return class_exists($class) ? $class : null;
    }

    public function completions(string $file, string $text, int $offset): array
    {
        // After @include(' offer the template names
        if (!preg_match("/@include\\('[\\w-]*$/", substr($text, 0, $offset))) {
            return [];
        }
        return array_map(
            fn (string $path) => ['label' => basename($path, '.php'), 'kind' => 'value', 'detail' => 'template'],
            glob("$this->root/views/*.php"),
        );
    }

    public function definition(string $file, string $text, int $offset): ?array
    {
        // F12 on @include('name') opens views/name.php
        $start = strrpos(substr($text, 0, $offset), "@include('");
        if ($start === false || !preg_match("/\\G@include\\('([\\w-]+)'\\)/", $text, $match, 0, $start)) {
            return null;
        }
        $target = "$this->root/views/$match[1].php";
        return is_file($target) ? ['file' => $target, 'line' => 1] : null;
    }

    public function diagnostics(string $file, string $text): array
    {
        $problems = [];
        foreach (explode("\n", $text) as $index => $line) {
            if (preg_match("/@include\\('([\\w-]+)'\\)/", $line, $match) && !is_file("$this->root/views/$match[1].php")) {
                $problems[] = ['line' => $index + 1, 'message' => "no template views/$match[1].php", 'severity' => 'error'];
            }
        }
        return $problems;
    }
}
```

The methods get absolute file paths, and `$text` is the editor's content, saved or not. Offsets and lengths count bytes.

| Method | Returns |
|---|---|
| `thisClass($file)` | the class `$this` is bound to in a non-class file (a template), or `null` |
| `completions($file, $text, $offset)` | a list of `['label' => …, 'kind' => 'method'\|'property'\|'class'\|'value'\|'keyword', 'detail' => …, 'insert' => …]`, all but `label` optional |
| `definition($file, $text, $offset)` | `['file' => …, 'line' => …]` where the name at `$offset` is declared, or `null` |
| `diagnostics($file, $text)` | a list of `['line' => …, 'message' => …, 'severity' => 'error'\|'warning'\|'info']`, lines from 1 |

### What thisClass() gives you

When a provider names the class of a template, Tenma does the rest by reflection:

- completes `$this->` and `$this->prop->`, private members included (the template runs bound to the object),
- shows type and doc comment on hover and jumps to the declaration with F12,
- gives the variable of `foreach ($this->items as $item)` (also `@foreach`) the element type from the PHPDoc of `items` (`list<Item>`, `array<int, Item>` or `Item[]`), resolved through the class's `use` statements.

## Semantic tokens

A provider that also implements [`Tenma\Lsp\Tokens`](../reference/Tenma/Lsp/Tokens.md) colours what the editor's grammar does not know, such as template directives:

```php
<?php

namespace Acme\Views;

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
            preg_match_all('/@(?:if|else|endif|foreach|endforeach|include)\b/', $line, $found, PREG_OFFSET_CAPTURE);
            foreach ($found[0] as [$word, $column]) {
                $tokens[] = ['line' => $index + 1, 'column' => $column, 'length' => strlen($word), 'type' => 'keyword'];
            }
        }
        return $tokens;
    }
}
```

Lines count from 1; `column` is the byte offset within the line, from 0. Types: `keyword`, `comment`, `variable`, `property`, `method`, `function`, `class`, `string`, `number`, `operator`. Tokens may come in any order; overlapping ones are dropped.

`extra.tenma.lsp` may also be a list of classes when a package has several providers.

See also: [`Tenma\Lsp\Provider`](../reference/Tenma/Lsp/Provider.md), [`Tenma\Lsp\Tokens`](../reference/Tenma/Lsp/Tokens.md), [Diagnostics](diagnostics.md), [Debugger and editor setup](debugger.md).
