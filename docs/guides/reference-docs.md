---
title: Reference pages
description: Generate Markdown reference pages for a PHP package with tenma docs.
order: 105
---

`tenma docs` writes one Markdown page per class, interface, trait and enum of a PHP project, and documents its functions on the index page of their namespace, from reflection and docblocks. The pages are meant to be committed next to hand-written guides; Tenma's own [reference](../reference/index.md) is made this way.

```bash
tenma docs src/                         # into docs/reference
tenma docs src/ lib/Printer.php --out site/api
tenma docs                              # Tenma's own built-in API
```

Paths are PHP files or directories; inside directories, `vendor`, `node_modules` and hidden directories are skipped. Tenma finds the types and functions declared in those files, loads the types through the nearest `vendor/autoload.php` above the first path (so parents, interfaces and attribute classes resolve), and loads straight from the files whatever the autoloader does not know. Loading runs the project's code: run it only on code you trust.

## What a page contains

For `src/Services/Cart.php` declaring `Bakery\Services\Cart`, the page is `docs/reference/Bakery/Services/Cart.md`:

````markdown
---
title: "Cart"
description: "A browser's cart."
order: 100
generated: true
---

`Bakery\Services\Cart` · final class · namespace [`Bakery\Services`](index.md)

```php
final class Cart implements \Countable
```

Implements [`Countable`](https://www.php.net/manual/en/class.countable.php)

A browser's cart. It holds products with their quantities and knows the total.

## Methods

### add()

```php
public function add(\Bakery\Models\Product $product, int $quantity = 1): void
```

Adds a product.

**Parameters**

- `$product` [`Product`](../Models/Product.md): the product to add
- `$quantity` `int`

**Throws**

- [`OutOfStock`](../Models/OutOfStock.md): when the bakery has fewer left
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

Functions declared at the top level of a file or of a namespace block are documented in a `## Functions` section of their namespace's `index.md` (functions without a namespace in the top `index.md`), sorted by name, each like a method: signature, text, parameters, return value, exceptions. A link to one is `index.md#name`, the heading's anchor. For `src/Support/price.php`:

```php
<?php

namespace Bakery\Support;

/**
 * Formats a price in the visitor's language.
 *
 * @param int $cents the price in euro cents
 * @param 'en'|'de' $locale
 * @return string such as "€1.80" or "1,80 €"
 */
function price(int $cents, string $locale): string { /* … */ }
```

`docs/reference/Bakery/Support/index.md` gets:

````markdown
## Functions

### price()

```php
function price(int $cents, string $locale): string
```

Formats a price in the visitor's language.

**Parameters**

- `$cents` `int`: the price in euro cents
- `$locale` `'en'|'de'`

**Returns** `string`: such as "€1.80" or "1,80 €"
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
 * $total = Money::of(180, 'EUR')->plus(Money::of(95, 'EUR'));   // a croissant and a pretzel
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
