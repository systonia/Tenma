---
title: Diagnostics
description: Report problems from the running app to the editor with Tenma::diagnostics().
order: 110
---

Some problems only show up when the code runs: a template that fails to compile, a route that points to a missing method, an error after a click. [`Tenma::diagnostics()`](../reference/Tenma.md#diagnostics) reports them to the editor, which shows them at their line like any other error, also in files that are not open.

```php
<?php

// The shop's views: views/Shop/Product.php, views/Backoffice/Board.php, …
$views = __DIR__ . '/views';

function checkViews(string $dir): array
{
    $problems = [];
    foreach (glob("$dir/*/*.php") as $file) {
        foreach (file($file) as $index => $line) {
            if (preg_match('/\{\{\s*\}\}/', $line, $match, PREG_OFFSET_CAPTURE)) {
                $problems[] = [
                    'file' => $file,
                    'line' => $index + 1,
                    'column' => $match[0][1] + 1,
                    'message' => 'empty {{ }} expression',
                    'severity' => 'warning',
                ];
            }
        }
    }
    return $problems;
}

Tenma::diagnostics('views', checkViews($views));

Tenma::serve(8080, fn () => "ok\n");
```

Each entry has:

| Key        |                                                  |
| ---------- | ------------------------------------------------ |
| `file`     | absolute, or relative to the working directory   |
| `line`     | from 1                                           |
| `message`  | the text shown in the editor                     |
| `severity` | optional: `error` (default), `warning` or `info` |
| `column`   | optional: in bytes, from 1                       |

The first argument names the reporter (`'views'`, `'waypoint-live'`, `'bakery:routes'`). A call replaces everything that reporter said before; an empty list clears it. Several reporters keep their own lists.

## How it reaches the editor

Diagnostics only work under [`--watch`](watch.md); without it the call does nothing, so it is safe to leave in production code.

- The lists go to `.tenma/diagnostics/` in the project directory. A `.gitignore` inside keeps them out of the repository.
- [`tenma lsp`](language-server.md) reads them once a second and publishes them to the editor, next to the problems its providers find.
- New entries also go to stderr as `path:line:column: severity: message`, the format problem matchers and Vim's quickfix read.
- When `--watch` runs the script again, what the script reported is cleared (and what the hooks of `tenma.dev.php` reported, when they are reloaded). A fixed problem therefore disappears without its reporter having to take it back.

## Reporting runtime errors

Errors of real requests can be reported too, so the editor shows where the last request failed:

```php
<?php

use Tenma\Http\{Request, Response};

// The price of one slice of the plum cake: /slice?slices=0 fails
function handle(Request $request): Response
{
    return Response::json(['sliceCents' => intdiv(1680, (int) ($request->query['slices'] ?? 0))]);
}

Tenma::serve(8080, function (Request $request) {
    try {
        $response = handle($request);
        Tenma::diagnostics('requests', []);
        return $response;
    } catch (Throwable $e) {
        Tenma::diagnostics('requests', [[
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'message' => get_class($e) . ': ' . $e->getMessage() . " ({$request->method} {$request->path})",
        ]]);
        throw $e;
    }
});
```

See also: [`Tenma::diagnostics()`](../reference/Tenma.md#diagnostics), [Language server](language-server.md), [Watch mode](watch.md).
