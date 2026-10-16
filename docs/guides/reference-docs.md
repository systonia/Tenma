---
title: Reference pages
description: Generate Markdown reference pages for a PHP package with tenma docs.
order: 105
---

`tenma docs` writes one Markdown page per class, interface, trait and enum of a PHP project, and documents its functions on the index page of their namespace, from reflection and docblocks. The pages are meant to be committed next to hand-written guides; Tenma's own [reference](../reference/index.md) is made this way.

```bash
tenma docs src/                         # into docs/reference
tenma docs src/ lib/Legacy.php --out site/api
tenma docs                              # Tenma's own built-in API
```

Paths are PHP files or directories; inside directories, `vendor`, `node_modules` and hidden directories are skipped. Tenma finds the types and functions declared in those files, loads the types through the nearest `vendor/autoload.php` above the first path (so parents, interfaces and attribute classes resolve), and loads straight from the files whatever the autoloader does not know. Loading runs the project's code: run it only on code you trust.

## What a page contains

For `src/Shop/Cart.php` declaring `Acme\Shop\Cart`, the page is `docs/reference/Acme/Shop/Cart.md`:

````markdown
---
title: "Cart"
description: "A shopping cart."
order: 100
generated: true
---

`Acme\Shop\Cart` · final class · namespace [`Acme\Shop`](index.md)

```php
final class Cart implements \Acme\Shop\Priced, \Countable
```

Implements [`Priced`](Priced.md), [`Countable`](https://www.php.net/manual/en/class.countable.php)

A shopping cart. It holds `Item`s and knows their total.

## Methods

### add()

```php
public function add(\Acme\Shop\Item $item, int $quantity = 1): static
```

Adds an item.

**Parameters**

- `$item` [`Item`](Item.md): the item to add
- `$quantity` `int`

**Throws**

- [`InvalidArgumentException`](https://www.php.net/manual/en/class.invalidargumentexception.php): when the cart is full
````

- **Front matter**: `title` is the short name, `description` the first sentence of the docblock, `generated: true` marks the page as written by `tenma docs`.
- **Head**: full name, kind (`final class`, `abstract class`, `readonly class`, `interface`, `trait`, `enum`), namespace, the declaration, and what it extends, implements and uses.
- **Text**: the docblock as Markdown. Paragraphs, lists and code blocks stay as written.
- **Sections**: enum cases, constants, properties (type, default, `readonly`, hooks) and methods (signature, text, parameters, return value, exceptions, template parameters), in declaration order.
- **Tags**: `@param`, `@return`, `@throws`, `@template` and `@var` become lists, with the docblock type preferred over the declared one (`list<Item>` says more than `array`). `@deprecated`, `@since` and `@see` are shown; tags for tools (`@phpstan-…`, `@codeCoverageIgnore`) are not.
- **Links**: a type documented in the same run links to its page, relatively; PHP's own classes link to the PHP manual.

Methods without a docblock take the one of the method they implement or override.

## What is left out

- private members, and protected ones of final classes and enums,
- types and members whose docblock has `@internal`,
- members inherited from a parent (they are on the parent's page); trait members appear on the using class.

## Index pages

Every namespace folder gets an `index.md` listing its sub-namespaces and its types with kind and first sentence; the output directory gets an `index.md` titled "Reference" over the top-level namespaces and the types without a namespace. In a docs site, each folder becomes a section with its `index.md` as landing page.

## Functions

Functions declared at the top level of a file or of a namespace block are documented in a `## Functions` section of their namespace's `index.md` (functions without a namespace in the top `index.md`), sorted by name, each like a method: signature, text, parameters, return value, exceptions. A link to one is `index.md#name`, the heading's anchor. For `src/text.php`:

```php
<?php

namespace Acme\Text;

/**
 * Joins CSS classes, each once.
 *
 * @param string|array<string, bool> ...$parts strings and arrays of class => condition
 * @return string the classes, separated by one space
 */
function classes(string|array ...$parts): string { /* … */ }
```

`docs/reference/Acme/Text/index.md` gets:

````markdown
## Functions

### classes()

```php
function classes(array|string ...$parts): string
```

Joins CSS classes, each once.

**Parameters**

- `...$parts` `string|array<string, bool>`: strings and arrays of class => condition

**Returns** `string`: the classes, separated by one space
````

A namespace with functions only still gets its `index.md`. Methods, closures and functions declared inside other functions are not listed, nor functions marked `@internal`. Functions do not autoload, so Tenma loads their files itself, except files whose functions the autoloader's `files` list already declared.

## Re-running

The output is sorted and contains no dates or versions, so running `tenma docs` again on unchanged code changes nothing. A CI step can run it and fail when the committed pages are out of date:

```bash
tenma docs src/ && test -z "$(git status --porcelain docs/reference)"
```

A run removes pages with `generated: true` whose type no longer exists, and folders left empty. It never touches files without that flag, and stops with an error instead of overwriting one, so hand-written pages can live in the same directory.

## Writing docblocks for it

```php
/**
 * Money in the smallest unit of a currency. Never a float.
 *
 * Amounts are immutable; arithmetic returns a new instance:
 *
 * ```php
 * $total = Money::of(1200, 'EUR')->plus(Money::of(300, 'EUR'));
 * ```
 *
 * @template TCurrency of string
 */
final readonly class Money
{
    /**
     * Adds two amounts of the same currency.
     *
     * @param Money<TCurrency> $other
     * @return Money<TCurrency>
     * @throws CurrencyMismatch when the currencies differ
     */
    public function plus(Money $other): self { /* … */ }
}
```

- Start with one sentence that stands on its own: it becomes the description and the line in the index.
- Write Markdown; fenced code blocks inside docblocks are kept.
- Put the extra type information in `@param` and `@return`; the signature already shows the declared types.

See also: [Language server](language-server.md), [the reference](../reference/index.md).
