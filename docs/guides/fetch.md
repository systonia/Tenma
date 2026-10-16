---
title: Outgoing HTTP
description: Call other HTTP services with Tenma::fetch() without blocking the server.
order: 60
---

[`Tenma::fetch()`](../reference/Tenma.md#fetch) sends an HTTP request and returns a [`FetchResponse`](../reference/Tenma/Http/FetchResponse.md). While it waits, only the calling fiber is suspended.

```php
<?php

use Tenma\Http\Response;

Tenma::serve(8080, function () {
    $response = Tenma::fetch('https://api.github.com/repos/systonia/Tenma', headers: [
        'accept' => 'application/vnd.github+json',
        'user-agent' => 'tenma-example',
    ]);
    if ($response->status !== 200) {
        return new Response("GitHub answered {$response->status}\n", 502);
    }
    $repo = $response->json();
    return Response::json(['name' => $repo['full_name'], 'stars' => $repo['stargazers_count']]);
});
```

Parameters, all but `$url` optional and best passed by name:

| Parameter | Default | |
|---|---|---|
| `$url` | | `http://` or `https://` |
| `method` | `'GET'` | any HTTP method |
| `headers` | `[]` | `['name' => 'value']` |
| `body` | `''` | the raw request body |
| `timeout` | `30.0` | seconds for the whole request, response body included |

The response has `status`, `headers` (a list of `Name: Value` lines), `body`, `header($name)` (case-insensitive, the first value) and `json()` (decoded to arrays, throws on invalid JSON).

## Errors

HTTP error statuses are no exceptions: a 404 or 500 comes back as a response with that `status`. Network errors (DNS, refused connection, TLS), a connection that is not established within 10 seconds and a response that is not complete within `timeout` throw a `RuntimeException`:

```php
<?php

Tenma::serve(8080, function () {
    try {
        $response = Tenma::fetch('https://example.com/', timeout: 2.0);
        return "upstream: {$response->status}\n";
    } catch (RuntimeException $e) {
        error_log('upstream failed: ' . $e->getMessage());
        return "upstream unavailable\n";
    }
});
```

## Sending JSON

```php
<?php

$response = Tenma::fetch('https://httpbin.org/post', method: 'POST',
    headers: ['content-type' => 'application/json'],
    body: json_encode(['name' => 'Tenma']),
);
```

## Several requests at once

Requests to independent services belong in [`Tenma::all()`](async.md#several-things-at-once): the total time is that of the slowest, not the sum.

```php
<?php

use Tenma\Http\Response;

Tenma::serve(8080, function () {
    $pages = Tenma::all(
        example: fn () => Tenma::fetch('https://example.com/'),
        php: fn () => Tenma::fetch('https://www.php.net/'),
    );
    return Response::json(array_map(fn ($page) => [$page->status, strlen($page->body)], $pages));
});
```

If the client disconnects, running fetches are cancelled with the request. Redirects are followed. `fetch()` only works in asynchronous code — handlers, tasks, timers; at the top level of a script wrap it in `Tenma::async()`. In classic mode it throws; use curl there.

See also: [`Tenma::fetch()`](../reference/Tenma.md#fetch), [`FetchResponse`](../reference/Tenma/Http/FetchResponse.md).
