---
title: Debugger and editor setup
description: Debug a running app with --inspect over the Debug Adapter Protocol, from VS Code, Neovim, Zed, Helix or Emacs.
order: 90
---

`tenma --inspect` starts a debugger inside Tenma that speaks the Debug Adapter Protocol (DAP). An editor connects to it and can set breakpoints, step, look at variables and evaluate PHP in the stopped request. Every request, timer and task is shown as a thread of its own.

```bash
tenma --inspect app.php            # debugger on 127.0.0.1:9229, the app starts at once
tenma --inspect-wait app.php       # waits until the editor has set its breakpoints
tenma --inspect-brk app.php        # waits, then stops at the script's first line
tenma --inspect=9230 app.php       # another port; --inspect=0.0.0.0:9229 for another address
tenma --inspect --watch app.php    # keeps the editor connected across reloads
```

A debugger can run any code in the app, so it listens on 127.0.0.1 by default; an address reachable from the network gets a warning. `--inspect` runs one worker and disables opcache (the JIT would skip the statements the debugger stops at).

## VS Code and VSCodium

Install the [Tenma-VSCode](https://github.com/systonia/Tenma-VSCode) extension. It starts the script on F5, or attaches to a running `--inspect`. A `.vscode/launch.json`:

```json
{
  "version": "0.2.0",
  "configurations": [
    { "type": "tenma", "request": "launch", "name": "Tenma", "program": "${workspaceFolder}/app.php", "watch": true },
    { "type": "tenma", "request": "attach", "name": "Attach to tenma --inspect", "port": 9229 }
  ]
}
```

`launch` takes `program`, `args`, `cwd`, `env`, `watch`, `stopOnEntry` and `runtimeExecutable` (the tenma binary, `tenma` on the `PATH` by default). `attach` takes `host`, `port` and `stopOnEntry`. The extension also starts the [language server](language-server.md).

## Other editors

Any DAP client connects to the TCP port as a server adapter. Start `tenma --inspect-wait app.php` in a terminal, then attach.

**Neovim** with [nvim-dap](https://github.com/mfussenegger/nvim-dap):

```lua
local dap = require('dap')
dap.adapters.tenma = { type = 'server', host = '127.0.0.1', port = 9229 }
dap.configurations.php = {
  { type = 'tenma', request = 'attach', name = 'Attach to tenma --inspect' },
}
```

**Zed, Helix, Emacs (dape)** and other DAP clients: configure a debug adapter that connects over TCP to `127.0.0.1:9229` (no adapter process to start) and send an `attach` request. Tenma treats `launch` and `attach` alike: both connect to the running app.

## What it can do

- **Breakpoints** on lines, with a condition (`$user['id'] === 42`), a hit count (`5`, `>= 5`, `% 3`) or as logpoints (`user {$user['name']} logged in`). A breakpoint on a line without its own statement (a method's head, the middle of a multi-line `return [`) moves to the next statement. One on a function's closing brace stops whenever it returns, with the return value among the variables.
- **`Tenma::debugger()`** in the code stops there when an editor is connected and does nothing otherwise.
- **Exceptions** the app does not catch stop where they are thrown, with the frames still alive. The filter "All exceptions" stops at caught ones too; a condition on a filter limits it to classes, e.g. `RuntimeException, App\NotFound` (subclasses included). `Tenma\Cancelled`, `exit()` and a reload ending waiting requests never stop.
- **Stepping**: continue, step over, into and out. A step stays in its request: stepping over `Tenma::sleep()` or a query lets other requests run meanwhile and stops at the next line of the same request.
- **Threads**: every request, timer and `Tenma::async()` task is a thread, named after its method and URI. While stopped, the editor lists them and shows where each waiting one waits.
- **Variables** of every frame, arrays and objects expandable, and editable: a shared array is copied first as PHP would do, and property types and `readonly` apply.
- **Evaluate**: watch expressions, hovers and the debug console evaluate PHP in the selected frame, with its variables and `$this`; the console also runs statements. Evaluated code cannot wait (`Tenma::sleep()` throws a `FiberError` there). A warning or notice is the evaluation's error, e.g. `Warning: Undefined property: Point::$x`. `echo` and the error log appear in the debug console.

A stopped request stops its worker, with its other requests and timers. Tenma's own standard library is never stepped into. With `--watch`, breakpoints keep applying across reloads; a change saved while stopped reloads after you continue. The editor's `stopOnEntry` overrides `--inspect-wait` and `--inspect-brk`.

See also: [`Tenma::debugger()`](../reference/Tenma.md#debugger), [Language server](language-server.md), [Command line](deployment.md).
