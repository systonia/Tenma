---
title: HTTP client
description: Send HTTP requests with Tenma::fetch() without blocking the server, and read the FetchResponse.
order: 210
---

`Tenma::fetch()` sends an HTTP request and returns a `FetchResponse`. While it waits, only the calling fiber is suspended. Other requests keep running.

| Function or class                                                                       | Does                                                   |
| --------------------------------------------------------------------------------------- | ------------------------------------------------------ |
| [`Tenma::fetch($url, method:, headers:, body:, timeout:)`](../reference/Tenma.md#fetch) | sends a request and waits for the complete response    |
| [`FetchResponse`](../reference/Tenma/Http/FetchResponse.md)                             | `status`, `headers`, `body`, `header($name)`, `json()` |

## Sending a request

```php
<?php

Tenma::async(function () {
    $response = Tenma::fetch('https://httpbin.org/get', headers: [
        'accept' => 'application/json',
    ]);

    echo $response->status, "\n";
    echo $response->header('content-type'), "\n";
    echo $response->json()['url'], "\n";
});
```

This prints `200`, `application/json` and the URL. At the top level of a script, `fetch()` needs `Tenma::async()` around it. In a handler, a task or a timer it works directly.

Parameters, all but `$url` optional and best passed by name:

| Parameter | Default | Meaning                                               |
| --------- | ------- | ----------------------------------------------------- |
| `$url`    |         | an `http://` or `https://` URL                        |
| `method`  | `'GET'` | any HTTP method                                       |
| `headers` | `[]`    | `['name' => 'value']`                                 |
| `body`    | `''`    | the raw request body                                  |
| `timeout` | `30.0`  | seconds for the whole request, response body included |

## Reading the response

| Member          | Holds                                                       |
| --------------- | ----------------------------------------------------------- |
| `status`        | the HTTP status code                                        |
| `headers`       | a list of `Name: Value` lines                               |
| `body`          | the raw body                                                |
| `header($name)` | the first value of a header, name case-insensitive, or null |
| `json()`        | the body decoded as JSON into arrays                        |

## Sending JSON

```php
<?php

Tenma::async(function () {
    $payload = json_encode(['name' => 'Ada', 'role' => 'admin']);

    $response = Tenma::fetch('https://httpbin.org/post',
        method: 'POST',
        headers: ['content-type' => 'application/json'],
        body: $payload,
    );

    echo $response->json()['json']['name'], "\n";   // Ada
});
```

## Handling errors

HTTP error statuses are no exceptions: a 404 or a 500 comes back as a response with that `status`. Network errors and timeouts throw a `RuntimeException`.

```php
<?php

use Tenma\Http\Response;

Tenma::serve(8080, function () {
    try {
        $response = Tenma::fetch('https://httpbin.org/delay/5', timeout: 1.0);
    } catch (RuntimeException $e) {
        return new Response("upstream failed: {$e->getMessage()}\n", 502);
    }

    if ($response->status !== 200) {
        return new Response("upstream answered {$response->status}\n", 502);
    }

    return $response->body;
});
```

## Several requests at once

Independent requests belong in [`Tenma::all()`](async.md#several-things-at-once). The total time is that of the slowest, not the sum.

```php
<?php

use Tenma\Http\Response;

Tenma::serve(8080, function () {
    $answers = Tenma::all(
        uuid: fn () => Tenma::fetch('https://httpbin.org/uuid', timeout: 5),
        ip: fn () => Tenma::fetch('https://httpbin.org/ip', timeout: 5),
    );

    $statuses = array_map(fn ($answer) => $answer->status, $answers);

    return Response::json($statuses);   // {"uuid": 200, "ip": 200}
});
```

To avoid a request per page view, keep the last answer in memory and refresh it with a timer: see [background work](async.md#background-work).

## Behaviour

- `fetch()` waits until the whole response body is there. Only the calling fiber waits.
- HTTP statuses never throw. Check `status`.
- A `RuntimeException` is thrown for DNS errors, refused connections and TLS errors.
- It is also thrown when the connection is not established within 10 seconds, or the response is not complete within `timeout`.
- `json()` throws a `JsonException` on invalid JSON.
- An invalid header name or value (a line break, a control character) throws a `ValueError`.
- Redirects are followed.
- `fetch()` works only in asynchronous code: handlers, tasks, timers. At the top level of a script and in [classic mode](classic-mode.md) it throws a `LogicException`. Use curl there.
- A fetch belongs to the request that started it. If the client disconnects, the fetch is cancelled: see [Lifetime of a request](request-lifetime.md).

See also: [`Tenma::fetch()`](../reference/Tenma.md#fetch), [`FetchResponse`](../reference/Tenma/Http/FetchResponse.md).
