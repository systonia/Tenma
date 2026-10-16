---
title: "Hook"
description: "A build step for `tenma --watch` and `tenma build`, returned in a list from tenma.dev.php next to the script."
order: 100
generated: true
---

`Tenma\Hook` · final readonly class · namespace [`Tenma`](index.md)

```php
final readonly class Hook
```

A build step for `tenma --watch` and `tenma build`, returned in a list from tenma.dev.php
next to the script. Runs once at startup and again
whenever a file matching $inputs changes. $run is PHP code or a command: a list of program
and arguments (an array is always a command) that Tenma starts as a process of its own,
without a shell, in the directory of tenma.dev.php.

Tenma orders the hooks by their files: a hook reading what another one writes runs after it.

## Properties

### $run

```php
public readonly \Closure|array $run;
```

**Type** [`Closure`](https://www.php.net/manual/en/class.closure.php)`(list<string>): mixed|list<string>`

### $inputs

```php
public readonly array $inputs;
```

**Type** `list<string>`

globs relative to tenma.dev.php: * and ? within a name, ** across directories

### $outputs

```php
public readonly array $outputs = [];
```

**Type** `list<string>`

files $run writes; they never trigger this hook again

### $name

```php
public readonly ?string $name = null;
```

**Type** `?string`

for log lines, default: the program's file name or "#N" (the position in the list)

## Methods

### __construct()

```php
public function __construct(
    public readonly array $inputs,
    callable|array $run,
    public readonly array $outputs = [],
    public readonly ?string $name = null,
)
```

**Parameters**

- `$inputs` `list<string>`: globs relative to tenma.dev.php: * and ? within a name, ** across directories
- `$run` `callable|array`
- `$outputs` `list<string>`: files $run writes; they never trigger this hook again
- `$name` `?string`: for log lines, default: the program's file name or "#N" (the position in the list)
