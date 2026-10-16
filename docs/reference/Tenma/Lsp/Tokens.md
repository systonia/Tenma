---
title: "Tokens"
description: "A provider that also colours what the editor's own grammar does not know, such as template syntax: tenma answers the editor's semantic tokens with these."
order: 100
generated: true
---

`Tenma\Lsp\Tokens` · interface · namespace [`Tenma\Lsp`](index.md)

```php
interface Tokens
```

A provider that also colours what the editor's own grammar does not know, such as template
syntax: tenma answers the editor's semantic tokens with these. Optional, next to Provider.

## Methods

### tokens()

```php
public function tokens(string $file, string $text): array
```

**Parameters**

- `$file` `string`
- `$text` `string`

**Returns** `list<array{line: int, column: int, length: int, type: 'keyword'|'comment'|'variable'|'property'|'method'|'function'|'class'|'string'|'number'|'operator'}>` — Lines count from 1, column and length are bytes within the line
