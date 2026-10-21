---
title: "Subscription"
description: "A subscription from Channel::subscribe(); cancel() ends it."
order: 100
generated: true
---

`Messaging\Subscription` · final class · namespace [`Messaging`](index.md)

```php
final class Subscription
```

A subscription from Channel::subscribe(); cancel() ends it.

## Methods

### cancel()

```php
public function cancel(): void
```

Stops the subscription; callbacks already running run to completion.
