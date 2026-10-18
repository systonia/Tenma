---
title: File system
description: Read and write files and directories with Tenma\Fs without blocking the worker.
order: 250
---

[`Tenma\Fs`](../reference/Tenma/Fs.md) reads and writes files and directories on background threads. While the disk works, only the waiting fiber pauses. The worker keeps serving other requests.

| Function                                                                       | Does                                                                               |
| ------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------- |
| [`Fs::readFile($path)`](../reference/Tenma/Fs.md#readfile)                     | Returns the whole file as a string.                                                |
| [`Fs::writeFile($path, $data)`](../reference/Tenma/Fs.md#writefile)            | Creates or replaces the file.                                                      |
| [`Fs::appendFile($path, $data)`](../reference/Tenma/Fs.md#appendfile)          | Creates the file or appends to it.                                                 |
| [`Fs::exists($path)`](../reference/Tenma/Fs.md#exists)                         | Returns `true` for files and directories.                                          |
| [`Fs::stat($path)`](../reference/Tenma/Fs.md#stat)                             | Returns `['size' => int, 'modified' => float, 'isFile' => bool, 'isDir' => bool]`. |
| [`Fs::readDir($path)`](../reference/Tenma/Fs.md#readdir)                       | Returns the names in a directory, sorted, without `.` and `..`.                    |
| [`Fs::mkdir($path, recursive: false)`](../reference/Tenma/Fs.md#mkdir)         | Creates a directory. Without `recursive`, an existing one is an error.             |
| [`Fs::rename($from, $to)`](../reference/Tenma/Fs.md#rename)                    | Moves a file, also across file systems (then copy and delete).                     |
| [`Fs::copy($from, $to)`](../reference/Tenma/Fs.md#copy)                        | Copies a file and returns the bytes copied.                                        |
| [`Fs::remove($path)`](../reference/Tenma/Fs.md#remove)                         | Deletes a file.                                                                    |
| [`Fs::removeDir($path, recursive: false)`](../reference/Tenma/Fs.md#removedir) | Deletes an empty directory. With `recursive`, also its contents.                   |

## Read and write files

```php
<?php

use Tenma\Fs;

Tenma::async(function () {
    $dir = sys_get_temp_dir() . '/tenma-fs-example';

    if (!Fs::exists($dir)) {
        Fs::mkdir($dir, recursive: true);
    }

    Fs::writeFile("$dir/notes.txt", "first line\n");
    Fs::appendFile("$dir/notes.txt", "second line\n");

    echo Fs::readFile("$dir/notes.txt");

    $stat = Fs::stat("$dir/notes.txt");
    echo "{$stat['size']} bytes\n";

    Fs::removeDir($dir, recursive: true);
});
```

This prints both lines and `23 bytes`. `Fs` needs a fiber, so the script wraps its work in `Tenma::async()`. Handlers, tasks and timers already run in one.

## Copy, move and list

```php
<?php

use Tenma\Fs;

Tenma::async(function () {
    $dir = sys_get_temp_dir() . '/tenma-fs-list';
    Fs::mkdir($dir);

    Fs::writeFile("$dir/a.txt", 'a');
    Fs::copy("$dir/a.txt", "$dir/b.txt");
    Fs::rename("$dir/b.txt", "$dir/c.txt");

    print_r(Fs::readDir($dir));   // a.txt, c.txt

    Fs::removeDir($dir, recursive: true);
});
```

## Files in a request handler

The handler stores one text file per name and reads it back.

```php
<?php

use Tenma\Fs;
use Tenma\Http\{Request, Response};

$dir = sys_get_temp_dir() . '/tenma-notes';

if (!is_dir($dir)) {
    mkdir($dir);   // at startup nothing else runs yet: blocking is fine
}

Tenma::serve(8080, function (Request $request) use ($dir) {
    // basename() keeps the request inside $dir
    $name = basename($request->path);

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

    return new Response(Fs::readFile($file));
});
```

`curl -d hello localhost:8080/monday` appends a line, `curl localhost:8080/monday` reads the file, and `curl localhost:8080/` lists `monday.txt`.

Never let a request choose a path outside the directory. `basename()` drops every `..` and `/`.

## Handle errors

```php
<?php

use Tenma\Fs;

Tenma::async(function () {
    try {
        $config = Fs::readFile('/etc/example/config.json');
    } catch (RuntimeException $e) {
        echo $e->getMessage(), "\n";   // read(/private/etc/example/config.json): No such file ...
        echo $e->getCode(), "\n";      // 2 (ENOENT)
        $config = '{}';
    }

    echo $config, "\n";
});
```

## Behaviour

- Only the calling fiber waits. Outside a fiber (at script startup without `Tenma::async()`), every `Fs` function throws a `LogicException`.
- PHP's own file functions (`file_get_contents()`, `fopen()`, `file_put_contents()`) block the whole worker. At startup that is fine, because nothing else runs yet.
- Paths behave as with PHP's file functions. They are relative to the working directory (`chdir()`), and `open_basedir` applies. Bytes pass through unchanged, so names need not be valid UTF-8.
- A path with a null byte throws a `ValueError`.
- Errors (missing file, no permission, existing directory) throw a `RuntimeException`. The message names the operation, the path and the reason. The code is `errno`.
- `copy()` of a file onto itself is an error.
- A running file operation cannot be cancelled. It runs to completion, also when the request is cancelled. Tenma does not exit while one is still running.
- A file larger than what `memory_limit` leaves free becomes an error in the request, not a fatal error.
- Files sent to clients as they are should be [static files](http.md#static-files): that is faster than reading them in PHP. Uploads move with [`UploadedFile::moveTo()`](../reference/Tenma/Http/UploadedFile.md#moveto).
