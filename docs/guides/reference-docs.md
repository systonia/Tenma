---
title: Reference pages
description: Generate Markdown reference pages for a PHP package with tenma docs.
order: 350
---

`tenma docs` writes one Markdown page per class, interface, trait and enum of a PHP project, from reflection and docblocks. Functions go on the index page of their namespace. The pages are meant to be committed next to hand-written guides; Tenma's own [reference](../reference/index.md) is made this way.

```bash
tenma docs src/                                   # into docs/reference
tenma docs src/ lib/Printer.php --out site/api
tenma docs                                        # Tenma's own built-in API
```

| Argument       | Default          | Does                                             |
| -------------- | ---------------- | ------------------------------------------------ |
| paths          | Tenma's own API  | PHP files or directories to document             |
| `--out <dir>`  | `docs/reference` | the output directory; also written `--out=<dir>` |
| `--help`, `-h` |                  | prints the usage                                 |

## Finding the code

- Paths are PHP files or directories. Inside directories, `vendor`, `node_modules` and hidden directories are skipped.
- Tenma finds the types and functions declared in those files.
- It loads the types through the nearest `vendor/autoload.php` above the first path, so parents, interfaces and attribute classes resolve.
- Whatever the autoloader does not know, it loads straight from the files.

## What a page contains

For `src/Services/Queue.php` declaring `App\Services\Queue`, the page is `docs/reference/App/Services/Queue.md`:

````markdown
---
title: "Queue"
description: "A queue of jobs."
order: 100
generated: true
---

`App\Services\Queue` · final class · namespace [`App\Services`](index.md)

```php
final class Queue implements \Countable
```

Implements [`Countable`](https://www.php.net/manual/en/class.countable.php)

A queue of jobs. It holds jobs in the order they were pushed and knows how many wait.

## Methods

### push()

```php
public function push(\App\Jobs\Job $job, int $delay = 0): void
```

Adds a job.

**Parameters**

- `$job` [`Job`](../Jobs/Job.md): the job to add
- `$delay` `int`

**Throws**

- [`QueueFull`](QueueFull.md): when the queue is full
````

| Part         | Holds                                                                                                                                                           |
| ------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| front matter | `title` (the short name), `description` (the docblock's first sentence), `generated: true`                                                                      |
| head         | full name, kind (`final class`, `abstract class`, `readonly class`, `interface`, `trait`, `enum`), namespace, declaration, what it extends, implements and uses |
| text         | the docblock as Markdown; paragraphs, lists and code blocks stay as written                                                                                     |
| sections     | enum cases, constants, properties (type, default, `readonly`, hooks) and methods, in declaration order                                                          |
| methods      | signature, text, parameters, return value, exceptions, template parameters                                                                                      |

- `@param`, `@return`, `@throws`, `@template` and `@var` become lists. The docblock type wins over the declared one, since `list<Item>` says more than `array`.
- `@deprecated`, `@since` and `@see` are shown. Tags for tools (`@phpstan-…`, `@codeCoverageIgnore`) are not.
- A type documented in the same run links to its page, relatively. PHP's own classes link to the PHP manual.
- A method without a docblock takes the one of the method it implements or overrides.

## What is left out

- private members, and protected ones of final classes and enums,
- types and members whose docblock has `@internal`,
- members inherited from a parent (they are on the parent's page); trait members appear on the using class.

## Index pages

- Every namespace folder gets an `index.md` that lists its sub-namespaces and its types, with kind and first sentence.
- The output directory gets an `index.md` titled "Reference", over the top-level namespaces and the types without a namespace.
- In a docs site, each folder becomes a section with its `index.md` as landing page.

## Functions

Functions declared at the top level of a file or a namespace block go into a `## Functions` section of their namespace's `index.md`. Functions without a namespace go into the top `index.md`. They are sorted by name, and each looks like a method. A link to one is `index.md#name`. For `src/Support/size.php`:

```php
<?php

namespace App\Support;

/**
 * Formats a size in bytes for people.
 *
 * @param int $bytes the size in bytes
 * @param 'si'|'iec' $units
 * @return string such as "1.5 kB" or "1.5 KiB"
 */
function size(int $bytes, string $units): string { /* … */ }
```

`docs/reference/App/Support/index.md` gets:

````markdown
## Functions

### size()

```php
function size(int $bytes, string $units): string
```

Formats a size in bytes for people.

**Parameters**

- `$bytes` `int`: the size in bytes
- `$units` `'si'|'iec'`

**Returns** `string`: such as "1.5 kB" or "1.5 KiB"
````

- A namespace with functions only still gets its `index.md`.
- Methods, closures, functions declared inside other functions, and functions marked `@internal` are not listed.
- Functions do not autoload, so Tenma loads their files itself. Files whose functions the autoloader's `files` list already declared are not loaded again.

## Checking in CI

The output is sorted and contains no dates or versions. Running `tenma docs` again on unchanged code changes nothing. A CI step can run it and fail when the committed pages are out of date:

```bash
tenma docs src/ && test -z "$(git status --porcelain docs/reference)"
```

## Writing docblocks for it

````php
/**
 * Money in the smallest unit of a currency. Never a float.
 *
 * Amounts are immutable; arithmetic returns a new instance:
 *
 * ```php
 * $total = Money::of(180, 'EUR')->plus(Money::of(95, 'EUR'));
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
````

- Start with one sentence that stands on its own. It becomes the description and the line in the index.
- Write Markdown. Fenced code blocks inside docblocks are kept.
- Put the extra type information in `@param` and `@return`. The signature already shows the declared types.

## Behaviour

- Loading runs the project's code. Run `tenma docs` only on code you trust.
- A run removes pages with `generated: true` whose type no longer exists, and folders left empty.
- It never touches files without that flag. It stops with an error (status 1) instead of overwriting one, so hand-written pages can live in the same directory.

See also: [Language server](language-server.md), [Command line](command-line.md), [the reference](../reference/index.md).
