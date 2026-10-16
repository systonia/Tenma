---
title: Watch mode and build hooks
description: Reload the app on every change with --watch, run build steps from tenma.dev.php, and run them once with tenma build.
order: 80
---

## --watch

```bash
tenma --watch app.php
```

Tenma watches every file the script loaded, including classes the autoloader loads later and templates read with `include`, and restarts the workers' interpreters when one of them changes. A change means different content, not just a new timestamp, so saving without changes or switching git branches back and forth does not reload.

During a reload:

- the port stays open; requests that arrive wait and are answered by the new code instead of failing,
- running requests get 1 second to finish (instead of 10 at shutdown); open streams and WebSockets are closed after that,
- in-memory state starts over, since the script runs again,
- a script that fails to load after a change answers 503 until the next change fixes it,
- a script whose ports changed restarts Tenma as a whole; a script without a server simply runs again.

`opcache.revalidate_freq` defaults to 0 under `--watch`, so opcache never serves the old code. `--watch` is for development; in production, deploy and restart instead.

`Tenma::reloadedBy()` returns the file whose change caused the current run (null on the first run). A framework can use it to rebuild only what that file affects:

```php
<?php

$changed = Tenma::reloadedBy();
if ($changed !== null) {
    error_log("reloaded because $changed changed");
}
Tenma::serve(8080, fn () => "ok\n");
```

## Build hooks: tenma.dev.php

Build steps such as Tailwind, a JavaScript bundle or a route cache come from a `tenma.dev.php` next to the script. It returns a list of [`Tenma\Hook`](../reference/Tenma/Hook.md). Production code never loads it.

```php
<?php
// tenma.dev.php

use Tenma\Hook;

return [
    // A command: runs as its own process, without a shell, in this directory
    new Hook(
        inputs: ['templates/**/*.php', 'assets/app.css'],
        outputs: ['public/app.css'],
        run: ['vendor/bin/tailwindcss', '-i', 'assets/app.css', '-o', 'public/app.css', '--minify'],
        name: 'css',
    ),
    // PHP: gets the changed paths (empty on the first run) and may use Tenma's async functions
    new Hook(
        inputs: ['src/Routes/**/*.php'],
        outputs: ['var/routes.php'],
        run: function (array $changed): void {
            $routes = [];
            foreach (glob(__DIR__ . '/src/Routes/*.php') as $file) {
                $routes[basename($file, '.php')] = $file;
            }
            Tenma\Fs::writeFile(__DIR__ . '/var/routes.php', '<?php return ' . var_export($routes, true) . ';');
        },
        name: 'routes',
    ),
];
```

How hooks run:

- Each hook runs once at startup, before the first request, and again whenever a file matching its `inputs` changes. The script starts once the first run is over, at the latest after 10 seconds (so a command with its own `--watch` that never ends does not block it).
- Globs are relative to `tenma.dev.php`: `*` and `?` within a name, `**` across directories.
- Tenma orders hooks by their files: a hook that reads another one's `outputs` runs after it, and only if that output really changed. A cycle is rejected at startup.
- A hook's own outputs never trigger it again. One that writes files into its own inputs without declaring them is stopped after five runs in a row.
- A failing hook (an exception, or an exit status other than 0) skips the hooks after it; the server keeps running.
- A command's output is prefixed with its `name` (default: the program's file name, or `#N`). Its processes, including those it started itself, get SIGTERM when the hook ends or is reloaded and SIGKILL 2 seconds later.
- Changing `tenma.dev.php` itself, or a file it or a PHP hook loaded, reloads the hooks. `exit()` in a hook ends Tenma.

### .tenma-ignore

A glob like `**/*.php` would otherwise look at every file in `vendor/`. A `.tenma-ignore` next to `tenma.dev.php` lists what is no input, one entry per line, `#` for comments:

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
tenma build                  # runs the hooks of ./tenma.dev.php once, in order
tenma build path/tenma.dev.php
```

`tenma build` runs every hook once, in dependency order, and exits with status 1 if one fails or the hooks form a cycle. Use it in CI and in a Dockerfile, so the production image contains the built CSS and caches without the hooks ever running there:

```dockerfile
RUN tenma build
```

## Problems in the editor

Under `--watch`, problems the app reports with `Tenma::diagnostics()` appear in the editor next to the code. See [Diagnostics](diagnostics.md).

See also: [`Tenma\Hook`](../reference/Tenma/Hook.md), [`Tenma::reloadedBy()`](../reference/Tenma.md#reloadedby), [Command line and deployment](deployment.md).
