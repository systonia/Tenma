---
title: "Watch"
description: "What `tenma --watch` tells the script about its reloads."
order: 100
generated: true
---

`Development\Watch` · final class · namespace [`Development`](index.md)

```php
final class Watch
```

What `tenma --watch` tells the script about its reloads.

## Methods

### reloadedBy()

```php
public static function reloadedBy(): ?string
```

The file whose change made the script run again, null on the first run and without
--watch. A framework can tell from it whether only a part of the app changed.

**Returns** `?string`
