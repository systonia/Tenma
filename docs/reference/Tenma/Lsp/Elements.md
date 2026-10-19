---
title: "Elements"
description: "A provider that also knows the element types of a class's array members where no PHPDoc names them, such as from attributes: tenma types the variable of a loop over such a member (`foreach ($invoice->lines as $line)`, also over a method's result and along chains) with them."
order: 100
generated: true
---

`Tenma\Lsp\Elements` · interface · namespace [`Tenma\Lsp`](index.md)

```php
interface Elements
```

A provider that also knows the element types of a class's array members where no PHPDoc names
them, such as from attributes: tenma types the variable of a loop over such a member (`foreach
($invoice->lines as $line)`, also over a method's result and along chains) with them. A `@var` or
`@return` PHPDoc on the member wins. tenma asks every provider for every class it needs, whatever
file is open, and keeps the answer until the worker restarts. Optional, next to Provider.

## Methods

### elements()

```php
public function elements(string $class): array
```

**Parameters**

- `$class` `string`

**Returns** `array<string, string>`: A property by its name, a method by its name followed by `()`, => the fully qualified class of its elements
