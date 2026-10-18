---
title: Outgoing HTTP
description: Call other HTTP services with Tenma::fetch() without blocking the server.
order: 60
---

[`Tenma::fetch()`](../reference/Tenma.md#fetch) sends an HTTP request and returns a [`FetchResponse`](../reference/Tenma/Http/FetchResponse.md). While it waits, only the calling fiber is suspended.

```php
<?php

use Tenma\Http\Response;

// The public holidays ahead in Germany, by date
Tenma::serve(8080, function () {
    $response = Tenma::fetch('https://date.nager.at/api/v3/NextPublicHolidays/DE', headers: [
        'accept' => 'application/json',
        'user-agent' => 'sonnenkorn-bakery',
    ]);
    if ($response->status !== 200) {
        return new Response("The holiday service answered {$response->status}\n", 502);
    }
    $closed = array_column($response->json(), 'localName', 'date');
    return Response::json(['closed' => $closed]);
});
```

Parameters, all but `$url` optional and best passed by name:

| Parameter | Default |                                                       |
| --------- | ------- | ----------------------------------------------------- |
| `$url`    |         | `http://` or `https://`                               |
| `method`  | `'GET'` | any HTTP method                                       |
| `headers` | `[]`    | `['name' => 'value']`                                 |
| `body`    | `''`    | the raw request body                                  |
| `timeout` | `30.0`  | seconds for the whole request, response body included |

The response has `status`, `headers` (a list of `Name: Value` lines), `body`, `header($name)` (case-insensitive, the first value) and `json()` (decoded to arrays, throws a `JsonException` on invalid JSON).

## Errors

HTTP error statuses are no exceptions: a 404 or 500 comes back as a response with that `status`. Network errors (DNS, refused connection, TLS), a connection that is not established within 10 seconds and a response that is not complete within `timeout` throw a `RuntimeException`. The bakery asks its two web services with a short timeout and carries on without them when they fail:

```php
// from src/Services/Holidays.php of the bakery, upcoming(), shortened
try {
    $response = \Tenma::fetch($url, timeout: $this->env->float('API_TIMEOUT', 2.5));
    if ($response->status !== 200) {
        return null;
    }
    $holidays = [];
    foreach (Row::each($response->json()) as $holiday) {
        $holidays[$holiday->string('date')] = new Holiday($holiday->string('name'), $holiday->string('localName'));
    }
    return $holidays;
} catch (RuntimeException|JsonException $e) {
    // down, too slow, or an answer that is no JSON: the shop carries on without knowing the holidays
    $this->logger->warning('holidays: ' . $e->getMessage());
    return null;
}
```

## Sending JSON

```php
<?php

// A pickup slip for the label printer in the shop
$response = Tenma::fetch('http://printer.sonnenkorn.test/slips', method: 'POST',
    headers: ['content-type' => 'application/json'],
    body: json_encode(['order' => 'a1f00', 'customer' => 'Anna', 'pickup' => '2026-10-19']),
);
```

## Several requests at once

Requests to independent services belong in [`Tenma::all()`](async.md#several-things-at-once): the total time is that of the slowest, not the sum.

```php
<?php

use Tenma\Http\Response;

Tenma::serve(8080, function () {
    $answers = Tenma::all(
        holidays: fn () => Tenma::fetch('https://date.nager.at/api/v3/NextPublicHolidays/DE', timeout: 2.5),
        weather: fn () => Tenma::fetch('https://api.open-meteo.com/v1/forecast?latitude=47.99&longitude=7.85&daily=weather_code', timeout: 2.5),
    );
    return Response::json(array_map(fn ($answer) => [$answer->status, strlen($answer->body)], $answers));
});
```

When a page needs such answers on every request, it can also keep the last ones in memory and refresh them with a timer, as the bakery's product page does: see [background work](async.md#background-work).

If the client disconnects, running fetches are cancelled with the request. Redirects are followed. `fetch()` only works in asynchronous code: handlers, tasks, timers. At the top level of a script, wrap it in `Tenma::async()`. In classic mode it throws; use curl there.

See also: [`Tenma::fetch()`](../reference/Tenma.md#fetch), [`FetchResponse`](../reference/Tenma/Http/FetchResponse.md).
