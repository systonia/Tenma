---
title: "Response"
description: "A complete response with status, headers and body."
order: 100
generated: true
---

`Tenma\Http\Response` · final class · namespace [`Tenma\Http`](index.md)

```php
final class Response
```

A complete response with status, headers and body. A handler may also return a string, which becomes a
200 with Content-Type text/plain. The static constructors build the usual kinds, header() and cookie()
add to it.

## Properties

### $body

```php
public string $body = '';
```

### $status

```php
public int $status = 200;
```

### $headers

```php
public array $headers = ['content-type' => 'text/plain; charset=utf-8'];
```

**Type** `array<string, string|list<string>>`

several values as a list, for example for Set-Cookie

## Methods

### __construct()

```php
public function __construct(
    public string $body = '',
    public int $status = 200,
    public array $headers = ['content-type' => 'text/plain; charset=utf-8'],
)
```

**Parameters**

- `$body` `string`
- `$status` `int`
- `$headers` `array<string, string|list<string>>` — several values as a list, for example for Set-Cookie

### json()

```php
public static function json(mixed $data, int $status = 200): \Tenma\Http\Response
```

**Parameters**

- `$data` `mixed`
- `$status` `int`

**Returns** [`Response`](Response.md)

### html()

```php
public static function html(string $html, int $status = 200): \Tenma\Http\Response
```

**Parameters**

- `$html` `string`
- `$status` `int`

**Returns** [`Response`](Response.md)

### redirect()

```php
public static function redirect(string $location, int $status = 302): \Tenma\Http\Response
```

Redirect; 302 (default), 303 after a POST, 301/308 for permanent ones.

**Parameters**

- `$location` `string`
- `$status` `int`

**Returns** [`Response`](Response.md)

### header()

```php
public function header(string $name, string $value, bool $append = false): \Tenma\Http\Response
```

Sets a header; with $append another value is added instead of replacing the existing one.

**Parameters**

- `$name` `string`
- `$value` `string`
- `$append` `bool`

**Returns** [`Response`](Response.md)

### cookie()

```php
public function cookie(
    string $name,
    string $value,
    ?int $expires = null,
    string $path = '/',
    ?string $domain = null,
    bool $secure = false,
    bool $httpOnly = true,
    string $sameSite = 'Lax',
): \Tenma\Http\Response
```

Sets a cookie like setcookie(): the value is encoded; without $expires (or with 0) it lasts until
the browser session ends. Defaults as is common today: HttpOnly and SameSite=Lax.

**Parameters**

- `$name` `string`
- `$value` `string`
- `$expires` `?int`
- `$path` `string`
- `$domain` `?string`
- `$secure` `bool`
- `$httpOnly` `bool`
- `$sameSite` `string`

**Returns** [`Response`](Response.md)

### stream()

```php
public static function stream(
    callable $writer,
    int $status = 200,
    array $headers = ['content-type' => 'application/octet-stream'],
): \Tenma\Http\StreamedResponse
```

A response whose body is produced bit by bit: the head goes out right away, every write() right
after it. The Fiber waits when the client cannot keep up.

**Parameters**

- `$writer` `callable(`[`Stream`](Stream.md)`): void`
- `$status` `int`
- `$headers` `array<string, string|list<string>>` — several values as a list, for example for Set-Cookie

**Returns** [`StreamedResponse`](StreamedResponse.md)

### events()

```php
public static function events(callable $writer): \Tenma\Http\StreamedResponse
```

Server-Sent Events: the browser reads along with `new EventSource(url)`.

**Parameters**

- `$writer` `callable(`[`EventStream`](EventStream.md)`): void`

**Returns** [`StreamedResponse`](StreamedResponse.md)

### websocket()

```php
public static function websocket(callable $handler): \Tenma\Http\WebSocketResponse
```

Turns the connection into a WebSocket if the client asks for it; otherwise 426.

**Parameters**

- `$handler` `callable(`[`WebSocket`](WebSocket.md)`): void`

**Returns** [`WebSocketResponse`](WebSocketResponse.md)
