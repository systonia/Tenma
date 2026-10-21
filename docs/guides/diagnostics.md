---
title: Diagnostics
description: Report problems from the running app to the editor with Development\Problems::report().
order: 340
---

Some problems only show up when the code runs: a template that fails to compile, a route that points to a missing method, an error after a click. [`Development\Problems::report()`](../reference/Development/Problems.md#report) reports them to the editor. The editor shows them at their line like any other error, also in files that are not open.

```bash
tenma --watch app.php
```

| Method                                                      | Does                                                                 |
| ----------------------------------------------------------- | -------------------------------------------------------------------- |
| `Development\Problems::report(string $source, array $list)` | replaces what `$source` reported before with `$list`; `[]` clears it |

Diagnostics need [`--watch`](watch.md) and the [language server](language-server.md) in the editor.

## Reporting problems

Each entry of the list is an array:

| Key        | Holds                                            |
| ---------- | ------------------------------------------------ |
| `file`     | absolute, or relative to the working directory   |
| `line`     | from 1                                           |
| `message`  | the text shown in the editor                     |
| `severity` | optional: `error` (default), `warning` or `info` |
| `column`   | optional: in bytes, from 1                       |

The first argument names the reporter, such as `'views'`, `'routes'` or `'app:requests'`. A call replaces everything that reporter said before. Several reporters keep their own lists.

This script checks its views at startup and flags every empty `{{ }}`:

```php
<?php

use Development\Problems;

function checkViews(string $dir): array
{
    $problems = [];
    foreach (glob("$dir/*.php") as $file) {
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

Problems::report('views', checkViews(__DIR__ . '/views'));

new Http\Server(8080, fn () => "ok\n")->start();
```

## Reporting runtime errors

Errors of real requests can be reported too, so the editor shows where the last request failed. Here `/divide?by=0` fails:

```php
<?php

use Development\Problems;
use Http\{Request, Response};

function handle(Request $request): Response
{
    $by = (int) ($request->query['by'] ?? 0);

    return Response::json(['result' => intdiv(100, $by)]);
}

function report(Request $request, Throwable $e): void
{
    $message = $e::class . ': ' . $e->getMessage();

    Problems::report('requests', [[
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'message' => "$message ({$request->method} {$request->path})",
    ]]);
}

new Http\Server(8080, function (Request $request) {
    try {
        $response = handle($request);
        Problems::report('requests', []);

        return $response;
    } catch (Throwable $e) {
        report($request, $e);

        throw $e;
    }
})->start();
```

## Behaviour

- Without `--watch` the call does nothing, so it is safe to leave in production code. It still checks its arguments.
- A reporter name has letters, digits, `.`, `:`, `-` and `_`. An entry without `file`, `line` or `message` throws an `InvalidArgumentException`.
- The lists go to `.tenma/diagnostics/` in the project directory. A `.gitignore` in `.tenma/` keeps them out of the repository.
- [`tenma lsp`](language-server.md) reads them once a second. It publishes them to the editor next to the problems its providers find.
- New entries also go to stderr as `path:line:column: severity: message`. Problem matchers and Vim's quickfix read this format.
- When `--watch` runs the script again, what the script reported is cleared. What the hooks of `tenma.dev.php` reported is cleared when they reload. A fixed problem therefore disappears without its reporter taking it back.

See also: [`Development\Problems::report()`](../reference/Development/Problems.md#report), [Language server](language-server.md), [Watch mode](watch.md).
