---
title: "Imports"
description: "A provider that also names the imports of a file, such as a template that sees the names its class file imports: tenma resolves class names there with them (completion after `Name::`, hover, F12, `@var` types, the types of loop and assigned variables)."
order: 100
generated: true
---

`Lsp\Imports` · interface · namespace [`Lsp`](index.md)

```php
interface Imports
```

A provider that also names the imports of a file, such as a template that sees the names its
class file imports: tenma resolves class names there with them (completion after `Name::`,
hover, F12, `@var` types, the types of loop and assigned variables). The file's own `use`
statements win over these for the same alias, its own namespace over the one given here.
Optional, next to Provider.

## Methods

### imports()

```php
public function imports(string $file, string $text): array
```

**Parameters**

- `$file` `string`
- `$text` `string`

**Returns** `array<string, string>`: Alias => fully qualified class name, the namespace under the key ''
