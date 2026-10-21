---
title: "ClassicServer"
description: "An HTTP server for classic PHP code, for example a framework with its own front controller."
order: 100
generated: true
---

`Http\ClassicServer` · final class · namespace [`Http`](index.md)

```php
final class ClassicServer
```

An HTTP server for classic PHP code, for example a framework with its own front controller.
Before every call of $app, $_SERVER, $_GET, $_POST, $_FILES, $_COOKIE, $_REQUEST and php://input
are ready as under FPM; header(), http_response_code() and everything $app outputs become the response.
exit() and die() only end the request. $app also gets the raw body as an argument. Requests run one
after another, and the asynchronous functions throw.

## Properties

### $port

```php
public readonly int $port;
```

## Methods

### __construct()

```php
public function __construct(public readonly int $port, private readonly mixed $app)
```

**Parameters**

- `$port` `int`
- `$app` `mixed`

### start()

```php
public function start(): void
```

Registers the server: it listens once the script has finished and keeps the process alive.
