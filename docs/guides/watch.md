---
title: Watch mode and build hooks
description: Reload the app on every change with --watch, run build steps from tenma.dev.php, and run them once with tenma build.
order: 310
---

`tenma --watch` reloads the app whenever a file it loaded changes. Build steps such as a stylesheet or a cache come from a `tenma.dev.php` next to the script; watch mode runs them on every change, and `tenma build` runs them once.

```bash
tenma --watch app.php    # serve and reload on changes
tenma build              # run the hooks of ./tenma.dev.php once
```

| Feature                                                   | Does                                                                      |
| --------------------------------------------------------- | ------------------------------------------------------------------------- |
| `--watch`                                                 | reloads the workers when a loaded file changes                            |
| [`Tenma::reloadedBy()`](../reference/Tenma.md#reloadedby) | the file whose change caused the current run, or `null` on the first run  |
| `tenma.dev.php`                                           | returns a list of [`Tenma\Hook`](../reference/Tenma/Hook.md): build steps |
| `.tenma-ignore`                                           | paths the hooks never look at                                             |
| `tenma build [tenma.dev.php]`                             | runs every hook once, for CI and Docker                                   |
| [`Tenma::diagnostics()`](diagnostics.md)                  | under `--watch`, problems the app reports appear in the editor            |

`--watch` is for development. In production, deploy and restart instead.

## Reloading

Tenma watches every file the script loaded. That includes classes the autoloader loads later and templates read with `include`. When one of them changes, the workers' interpreters restart and run the script again.

A change means different content, not just a new timestamp. Saving without changes does not reload, and neither does switching git branches back and forth.

`Tenma::reloadedBy()` returns the file whose change caused the current run. A framework can use it to rebuild only what that file affects:

```php
<?php

$changed = Tenma::reloadedBy();

if ($changed !== null) {
    error_log("reloaded because $changed changed");
}

Tenma::serve(8080, fn () => "ok\n");
```

## Build hooks: tenma.dev.php

A `tenma.dev.php` next to the script returns a list of [`Tenma\Hook`](../reference/Tenma/Hook.md). Production code never loads it.

| Parameter | Does                                                                                  |
| --------- | ------------------------------------------------------------------------------------- |
| `inputs`  | globs relative to `tenma.dev.php`: `*` and `?` within a name, `**` across directories |
| `run`     | a command as a list of strings, or a PHP callable that gets the changed paths         |
| `outputs` | the files `run` writes; they never trigger this hook again                            |
| `name`    | the prefix of its log lines; default: the program's file name, or `#N` (its position) |

A command hook runs the program as its own process, without a shell, in the directory of `tenma.dev.php`. This one rebuilds a stylesheet from every template that uses its classes:

```php
<?php
// tenma.dev.php

return [
    new Tenma\Hook(
        inputs: ['assets/app.css', 'views/**/*.php', 'src/**/*.php'],
        outputs: ['public/app.css'],
        run: ['tailwindcss', '-i', 'assets/app.css', '-o', 'public/app.css', '--minify'],
        name: 'css',
    ),
];
```

A PHP hook gets the changed paths, an empty list on the first run. It may use Tenma's async functions. This one writes a list of icons for the app to `include`:

```php
<?php
// tenma.dev.php

use Tenma\Hook;

function listIcons(array $changed): void
{
    $icons = [];
    foreach (glob(__DIR__ . '/public/icons/*.svg') as $file) {
        $icons[basename($file, '.svg')] = '/icons/' . basename($file);
    }

    $code = '<?php return ' . var_export($icons, true) . ';';
    Tenma\Fs::writeFile(__DIR__ . '/var/icons.php', $code);
}

return [
    new Hook(
        inputs: ['public/icons/*.svg'],
        outputs: ['var/icons.php'],
        run: listIcons(...),
        name: 'icons',
    ),
];
```

## .tenma-ignore

A glob like `**/*.php` would otherwise look at every file in `vendor/`. A `.tenma-ignore` next to `tenma.dev.php` lists what is no input. One entry per line, `#` starts a comment:

```text
# a path in the project (has a slash)
storage/cache
# any file or directory with that name (no slash)
node_modules
*.log
```

Without the file, `.git`, `node_modules` and `vendor` are left out. Changing `.tenma-ignore` reloads the hooks.

## tenma build

```bash
tenma build                    # the hooks of ./tenma.dev.php
tenma build path/tenma.dev.php
```

`tenma build` runs every hook once, in dependency order, then exits. Use it in CI and in a Dockerfile, so the production image contains the built files without the hooks ever running there:

```dockerfile
RUN tenma build
```

## Problems in the editor

Under `--watch`, problems the app reports with `Tenma::diagnostics()` appear in the editor next to the code. See [Diagnostics](diagnostics.md).

## Behaviour

During a reload:

- The port stays open. Requests that arrive wait and get answered by the new code.
- Running requests get 1 second to finish (10 at shutdown). Open streams and WebSockets close after that.
- In-memory state starts over, since the script runs again.
- A script that fails to load answers 503 until a change fixes it.
- A script whose ports changed restarts Tenma as a whole. A script without a server simply runs again.
- `opcache.revalidate_freq` defaults to 0, so opcache never serves the old code.

Hooks:

- Each hook runs once at startup, before the first request. It runs again whenever a file matching its `inputs` changes.
- The script starts once the first run of the hooks is over, at the latest after 10 seconds. A command with its own watch mode, which never ends, therefore does not block it.
- Tenma orders hooks by their files. A hook that reads another one's `outputs` runs after it, and only if that output really changed. A cycle is rejected at startup.
- A hook that writes files into its own inputs without declaring them as `outputs` is stopped after five runs in a row.
- A failing hook (an exception, or an exit status other than 0) skips the hooks after it. The server keeps running.
- A command's processes, including those it started itself, get SIGTERM when the hook ends or reloads, and SIGKILL 2 seconds later.
- Changing `tenma.dev.php`, or a file it or a PHP hook loaded, reloads the hooks.
- `exit()` in a hook ends Tenma.
- `tenma build` exits with status 1 if a hook fails or the hooks form a cycle.

See also: [`Tenma\Hook`](../reference/Tenma/Hook.md), [`Tenma::reloadedBy()`](../reference/Tenma.md#reloadedby), [Command line](command-line.md), [Debugger](debugger.md).
