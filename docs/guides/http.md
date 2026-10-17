---
title: Serving HTTP
description: Start a server with Tenma::serve(), read requests, build responses and serve static files.
order: 10
---

`Tenma::serve()` registers an HTTP server. It does not block: the script runs to its end, and then Tenma opens the port and calls the handler for every request. The handler runs in fiber mode, each request in its own fiber, and returns a response.

```php
<?php

use Tenma\Http\{Request, Response};

Tenma::serve(8080, function (Request $request): Response|string {
    return "Sonnenkorn bakery: {$request->method} {$request->path}\n";
});
```

A handler gets a [`Request`](../reference/Tenma/Http/Request.md) and returns one of:

- a string: status 200, `Content-Type: text/plain; charset=utf-8`
- a [`Response`](../reference/Tenma/Http/Response.md): status, headers and body
- a streamed response from [`Response::stream()` or `Response::events()`](streams.md)
- a WebSocket from [`Response::websocket()`](websockets.md)

An exception that leaves the handler becomes a `500 Internal Server Error`; the message goes to the log on stderr, never to the client, and the server keeps running. `exit()` inside a handler ends only that request.

Everything outside the handler runs once, when the script starts. That is the place for the expensive setup: configuration, routes, templates, database connections. A script can call `Tenma::serve()` for several ports. The bakery's `index.php` builds its whole app once and hands it to Tenma; the app is itself a handler:

```php
// from index.php of the bakery, shortened: app.php builds the Waypoint app with its routes and services
$app = require __DIR__ . '/app.php';
$app->serve();   // Tenma::serve() with the app as handler
```

## Reading the request

```php
<?php

use Tenma\Http\{Request, Response};

Tenma::serve(8080, function (Request $request): Response {
    return Response::json([
        'method' => $request->method,            // GET, POST, …
        'uri' => $request->uri,                  // path with query string: /products?category=bread
        'path' => $request->path,                // /products
        'query' => $request->query,              // ['category' => 'bread'], parsed like $_GET
        'agent' => $request->header('user-agent'),   // case-insensitive, null if missing
        'host' => $request->host(),              // Host header without port
        'client' => $request->remoteAddress,     // IP of the client (or of the proxy in front)
        'cart' => $request->cookie('cart'),      // the cart id, null on the first visit
    ]);
});
```

The body is read completely before the handler runs (up to `--max-body`, default 10 MB, otherwise 413):

- `$request->body`: the raw bytes
- `$request->json()`: the body decoded as JSON (arrays), throws on invalid JSON
- `$request->form()`: fields of `application/x-www-form-urlencoded` and `multipart/form-data`, with the same rules as `$_POST` (`tags[]` appends, `address[city]` nests)
- `$request->files()`: uploads from `multipart/form-data` as [`UploadedFile`](../reference/Tenma/Http/UploadedFile.md) objects, by field name
- `$request->cookies()`: all cookies, parsed like `$_COOKIE`

`$request->headers` holds every header with its name in lowercase. The Host header comes from the client: check it against a list of your hosts before you use it in links or redirects.

## Building responses

```php
<?php

use Tenma\Http\{Request, Response};

Tenma::serve(8080, fn (Request $request) => match ($request->path) {
    '/checkout' => Response::html('<form method="post" action="/orders"><input name="name"><button>Reserve</button></form>'),
    '/orders' => Response::redirect('/orders/thanks', 303)
        ->cookie('customer', $request->form()['name'] ?? 'guest', expires: time() + 3600),
    '/orders/thanks' => Response::html('<p>Thank you, ' . htmlspecialchars($request->cookie('customer') ?? 'guest') . '! Your order is reserved.</p>'),
    '/v1/products' => Response::json([['slug' => 'croissant', 'priceCents' => 180]])
        ->header('cache-control', 'no-store')
        ->header('vary', 'cookie', append: true),
    default => new Response("Not found\n", 404),
});
```

- `Response::json($data, $status)` encodes with `JSON_PRETTY_PRINT` and `JSON_UNESCAPED_UNICODE` and sets `Content-Type: application/json`.
- `Response::html($html, $status)` sets `text/html; charset=utf-8`.
- `Response::redirect($location, $status)` uses 302 by default; 303 after a POST, 301 or 308 for permanent moves.
- `new Response($body, $status, $headers)` for anything else.
- `->header($name, $value)` replaces a header, with `append: true` it adds another value.
- `->cookie($name, $value, ...)` works like `setcookie()`: `expires`, `path`, `domain`, `secure`, `httpOnly` (default `true`), `sameSite` (default `Lax`).

## Uploads

```php
<?php

use Tenma\Http\{Request, Response};

// The back office uploads a new picture for a product; they live in public/products
$pictures = __DIR__ . '/public/products';
@mkdir($pictures, recursive: true);

Tenma::serve(8080, function (Request $request) use ($pictures) {
    if ($request->method === 'GET') {
        return Response::html('<form method="post" enctype="multipart/form-data">'
            . '<input type="file" name="picture" accept="image/svg+xml"><button>Upload</button></form>');
    }
    $picture = $request->files()['picture'] ?? null;
    if ($picture === null || $picture->size() === 0) {
        return new Response("No file\n", 422);
    }
    $name = bin2hex(random_bytes(8)) . '.svg';
    $picture->moveTo("$pictures/$name");   // writes without blocking the server
    return Response::json(['stored' => $name, 'type' => $picture->type, 'bytes' => $picture->size()]);
});
```

An `UploadedFile` holds the file in memory: `name` and `type` as the client sent them, `size()`, `contents()` and `moveTo()`. Never trust `name` as a path.

## Static files

Tenma serves files itself, without calling PHP, when `static:` names them:

```php
<?php

Tenma::serve(8080, fn () => "the shop\n", static: [
    '/products' => __DIR__ . '/public/products',
    '/app.css' => __DIR__ . '/public/app.css',
    '/favicon.svg' => __DIR__ . '/public/favicon.svg',
]);
```

- Under a prefix (`/products`), Tenma looks in the directory: `/products/croissant.svg` is `public/products/croissant.svg`. A single file matches only its exact path. The longest prefix wins.
- A request outside every prefix goes to the handler without touching the file system. A file that does not exist under a prefix goes to the handler too, so the app can answer the 404.
- `static: __DIR__ . '/public'` is short for `['/' => __DIR__ . '/public']`. Then every GET is checked against the directory first, which costs PHP routes about a third of their throughput; prefixes are cheaper.
- Files are sent with a `Content-Type` from their extension, `ETag` and `Last-Modified` (304 for a cached copy) and `Range` (206), in chunks. A directory answers with its `index.html`; one without a trailing `/` is redirected.
- Only GET and HEAD. Hidden files and directories (`.env`, `.git`), PHP sources (`.php`, `.phtml`, `.phar`, …) and symlinks that point outside the directory are never served, so a front controller can sit next to the assets.
- The directory is resolved per request: switching a `current` symlink during a deploy takes effect at once. `open_basedir` applies.

## State between requests

The script runs once, so variables, statics and objects live as long as the process. That makes in-memory caches cheap:

```php
<?php

use Tenma\Http\Request;

// Computed once per worker, then answered from memory
$prices = [];

Tenma::serve(8080, function (Request $request) use (&$prices) {
    $loaves = max(1, (int) ($request->query['loaves'] ?? 1));
    // ten percent off every fifth loaf
    return $prices[$loaves] ??= sprintf("%.2f EUR\n", 4.20 * ($loaves - 0.1 * intdiv($loaves, 5)));
});
```

Many requests run at the same time in one process, so never keep the state of *one* request in a global, a static or a singleton, because another request would see it. Pass it along as an argument, or use [`AsyncLocal`](async.md#values-that-follow-a-request) for values that should follow a request into its tasks. The bakery keeps the last weather forecast and the public holidays in memory this way, refreshed by timers: see [background work](async.md#background-work). With `--workers N`, each worker has its own copy of the state: see [Command line and deployment](deployment.md#workers).

## Errors and limits

- An uncaught exception answers 500 and is logged; the server keeps running.
- A fatal error (out of memory, stack overflow) answers 500 and restarts the interpreter of that worker, which runs the script again.
- Headers and body must arrive within `--request-timeout` (default 30 s); slow clients are disconnected.
- A worker accepts at most `--max-connections` connections (default 1024); more get a 503 at once.
- When a client disconnects before the response is complete, the handler gets [`Tenma\Cancelled`](async.md#cancellation) where it waits.

See also: [`Tenma::serve()`](../reference/Tenma.md#serve), [`Request`](../reference/Tenma/Http/Request.md), [`Response`](../reference/Tenma/Http/Response.md), [`UploadedFile`](../reference/Tenma/Http/UploadedFile.md).
