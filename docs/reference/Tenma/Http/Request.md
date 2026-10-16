---
title: "Request"
description: "An HTTP request in fiber mode, the argument of the handler of Tenma::serve()."
order: 100
generated: true
---

`Tenma\Http\Request` · final class · namespace [`Tenma\Http`](index.md)

```php
final class Request
```

An HTTP request in fiber mode, the argument of the handler of Tenma::serve(). Query, headers and body
are read in full before the handler runs; form(), files() and cookies() parse them on demand.

## Properties

### $method

```php
public readonly string $method;
```

### $uri

```php
public readonly string $uri;
```

### $path

```php
public readonly string $path;
```

### $query

```php
public readonly array $query;
```

**Type** `array<string, string|array<mixed>>`

### $headers

```php
public readonly array $headers;
```

**Type** `array<string, string>`

names in lowercase

### $body

```php
public readonly string $body;
```

### $remoteAddress

```php
public readonly string $remoteAddress;
```

IP address and port of the client, or of the proxy in front of it.

### $remotePort

```php
public readonly int $remotePort;
```

### $serverAddress

```php
public readonly string $serverAddress;
```

Address and port the connection arrived on.

### $serverPort

```php
public readonly int $serverPort;
```

## Methods

### __construct()

```php
public function __construct(array $raw)
```

**Parameters**

- `$raw` `array{method: string, uri: string, headers: array<string, string>, body: string, remote_addr: string, remote_port: int, server_addr: string, server_port: int}`

### host()

```php
public function host(): string
```

The host from the Host header, without port; otherwise the address the connection arrived on. The
client controls the Host header: for links in emails or for redirects, only use it checked against
a list of allowed hosts.

**Returns** `string`

### header()

```php
public function header(string $name): ?string
```

**Parameters**

- `$name` `string`

**Returns** `?string`

### json()

```php
public function json(): mixed
```

The body as JSON; throws on invalid JSON.

**Returns** `mixed`

### cookies()

```php
public function cookies(): array
```

The cookies like $_COOKIE under FPM, via PHP's own parser: the first of several with the same name
wins, a[b] becomes an array, dots and spaces in names become underscores.

**Returns** `array<string, mixed>`

### cookie()

```php
public function cookie(string $name): ?string
```

**Parameters**

- `$name` `string`

**Returns** `?string`

### form()

```php
public function form(): array
```

Form fields from application/x-www-form-urlencoded or multipart/form-data, with the same
semantics as $_POST (a[] appends, a[b] nests, duplicates overwrite).

**Returns** `array<string, mixed>`

### files()

```php
public function files(): array
```

Uploaded files from multipart/form-data, by field name; names as with form() (name[] becomes
a list, name[a] nests).

**Returns** `array<string, mixed>`: UploadedFile or arrays of them
