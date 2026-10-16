---
title: Files
description: Read and write files with Tenma\Fs without blocking the worker.
order: 70
---

PHP's file functions (`file_get_contents()`, `fopen()`, `file_put_contents()`) block: while the disk works, the whole worker waits. [`Tenma\Fs`](../reference/Tenma/Fs.md) does the same work on background threads, and only the fiber that asked waits.

```php
<?php

use Tenma\Fs;
use Tenma\Http\{Request, Response};

$dir = sys_get_temp_dir() . '/tenma-notes';

Tenma::async(function () use ($dir) {
    if (!Fs::exists($dir)) {
        Fs::mkdir($dir, recursive: true);
    }
});

Tenma::serve(8080, function (Request $request) use ($dir) {
    $name = basename($request->path);   // never let a request choose a path outside $dir
    if ($name === '') {
        return Response::json(Fs::readDir($dir));
    }
    $file = "$dir/$name.txt";
    if ($request->method === 'POST') {
        Fs::appendFile($file, $request->body . "\n");
        return new Response("saved\n", 201);
    }
    if (!Fs::exists($file)) {
        return new Response("Not found\n", 404);
    }
    $stat = Fs::stat($file);
    return Response::json(['size' => $stat['size'], 'modified' => date(DATE_ATOM, (int) $stat['modified']), 'text' => Fs::readFile($file)]);
});
```

| Function | |
|---|---|
| `readFile($path)` | the whole file as a string |
| `writeFile($path, $data)` | creates or replaces |
| `appendFile($path, $data)` | creates or appends |
| `exists($path)` | `true` for files and directories |
| `stat($path)` | `['size' => …, 'modified' => float, 'isFile' => …, 'isDir' => …]` |
| `readDir($path)` | names in the directory, sorted, without `.` and `..` |
| `mkdir($path, recursive: false)` | without `recursive`, an error if it exists |
| `rename($from, $to)` | also across file systems (then copy and delete) |
| `copy($from, $to)` | returns the bytes copied; onto itself is an error |
| `remove($path)` | a file |
| `removeDir($path, recursive: false)` | an empty directory, with `recursive` also its contents |

Paths behave as with PHP's file functions: relative to the working directory (`chdir()`), `open_basedir` applies, bytes are passed through unchanged, and null bytes are rejected. Errors (missing file, no permission) throw a `RuntimeException` with the operation, the path and the reason in the message and `errno` as the code:

```php
try {
    $config = Fs::readFile('/etc/app/config.json');
} catch (RuntimeException $e) {
    $config = '{}';   // $e->getCode() === 2 (ENOENT) for a missing file
}
```

A running file operation cannot be cancelled; it runs to completion even when the request is cancelled. A file larger than what `memory_limit` leaves free becomes an error in the request, not a fatal error.

`Tenma\Fs` works in asynchronous code (handlers, tasks, timers). At script startup, plain `file_get_contents()` is fine (nothing else is running yet), or wrap the work in `Tenma::async()`. For files sent to clients as they are, [static files](http.md#static-files) are faster than reading them in PHP.

See also: [`Tenma\Fs`](../reference/Tenma/Fs.md), [`UploadedFile::moveTo()`](../reference/Tenma/Http/UploadedFile.md#moveto).
