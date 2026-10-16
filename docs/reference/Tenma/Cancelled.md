---
title: "Cancelled"
description: "The task or request was cancelled, for example because a sibling in all() failed or exit() ran."
order: 100
generated: true
---

`Tenma\Cancelled` · class · namespace [`Tenma`](index.md)

```php
class Cancelled extends \Exception
```

Extends [`Exception`](https://www.php.net/manual/en/class.exception.php)

The task or request was cancelled, for example because a sibling in all() failed or exit()
ran. Deliberately not a RuntimeException: a catch for failed ops should not swallow it.
