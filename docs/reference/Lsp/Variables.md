---
title: "Variables"
description: "A provider that also types variables other than `$this` in a file, such as the model a template renders: tenma completes `$name->`, shows hover and leads F12 on its members, follows chains from it and types loop and assigned variables through it."
order: 100
generated: true
---

`Lsp\Variables` · interface · namespace [`Lsp`](index.md)

```php
interface Variables
```

A provider that also types variables other than `$this` in a file, such as the model a
template renders: tenma completes `$name->`, shows hover and leads F12 on its members, follows
chains from it and types loop and assigned variables through it. Below an assignment or a
loop in the file that introduces the same name, the file's own typing wins. Optional, next
to Provider.

## Methods

### variables()

```php
public function variables(string $file, string $text): array
```

**Parameters**

- `$file` `string`
- `$text` `string`

**Returns** `array<string, string>`: Variable name without `$` => fully qualified class name
