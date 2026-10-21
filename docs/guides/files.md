---
title: File system
description: Read and write files and directories with FileSystem\FileInfo and FileSystem\DirectoryInfo without blocking the worker.
order: 250
---

[`FileInfo`](../reference/FileSystem/FileInfo.md) and [`DirectoryInfo`](../reference/FileSystem/DirectoryInfo.md) read and write files and directories on background threads. Creating an object does no I/O; every method asks the file system then. While the disk works, only the waiting fiber pauses. The worker keeps serving other requests.

`$file` is a `new FileInfo($path)`, `$dir` a `new DirectoryInfo($path)`. Both have `$path`, `name()` and `exists()`.

| Method                                                                                | Does                                                                                          |
| ------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------- |
| [`$file->read()`](../reference/FileSystem/FileInfo.md#read)                           | Returns the whole file as a string.                                                           |
| [`$file->write($data)`](../reference/FileSystem/FileInfo.md#write)                    | Creates or replaces the file.                                                                 |
| [`$file->append($data)`](../reference/FileSystem/FileInfo.md#append)                  | Creates the file or appends to it.                                                            |
| [`$file->exists()`](../reference/FileSystem/FileInfo.md#exists)                       | Returns `true` if something exists at the path, like `file_exists()`.                         |
| [`$file->size()`](../reference/FileSystem/FileInfo.md#size)                           | Returns the size in bytes.                                                                    |
| [`$file->modified()`](../reference/FileSystem/FileInfo.md#modified)                   | Returns the modification time as a Unix timestamp with fractions.                             |
| [`$file->copyTo($target)`](../reference/FileSystem/FileInfo.md#copyto)                | Copies the file and returns the copy as a `FileInfo`.                                         |
| [`$file->moveTo($target)`](../reference/FileSystem/FileInfo.md#moveto)                | Moves the file, also across file systems (then copy and delete), and returns the target.      |
| [`$file->delete()`](../reference/FileSystem/FileInfo.md#delete)                       | Deletes the file.                                                                             |
| [`$dir->create(recursive: false)`](../reference/FileSystem/DirectoryInfo.md#create)   | Creates the directory. Without `recursive`, an existing one is an error.                      |
| [`$dir->files($pattern)`](../reference/FileSystem/DirectoryInfo.md#files)             | Returns the files as `FileInfo` objects, sorted by name; `$pattern` filters like `fnmatch()`. |
| [`$dir->directories($pattern)`](../reference/FileSystem/DirectoryInfo.md#directories) | Returns the subdirectories as `DirectoryInfo` objects, sorted by name.                        |
| [`$dir->delete(recursive: false)`](../reference/FileSystem/DirectoryInfo.md#delete)   | Deletes an empty directory. With `recursive`, also its contents.                              |

## Read and write files

```php
<?php

use FileSystem\{DirectoryInfo, FileInfo};
use Threading\Task;

Task::run(function () {
    $dir = new DirectoryInfo(sys_get_temp_dir() . '/tenma-fs-example');

    if (!$dir->exists()) {
        $dir->create(recursive: true);
    }

    $notes = new FileInfo("{$dir->path}/notes.txt");
    $notes->write("first line\n");
    $notes->append("second line\n");

    echo $notes->read();
    echo $notes->size(), " bytes\n";

    $dir->delete(recursive: true);
});
```

This prints both lines and `23 bytes`. `FileInfo` and `DirectoryInfo` need a fiber, so the script wraps its work in `Task::run()`. Handlers, tasks and timers already run in one.

## Copy, move and list

```php
<?php

use FileSystem\{DirectoryInfo, FileInfo};
use Threading\Task;

Task::run(function () {
    $dir = new DirectoryInfo(sys_get_temp_dir() . '/tenma-fs-list');
    $dir->create();

    $a = new FileInfo("{$dir->path}/a.txt");
    $a->write('a');

    $b = $a->copyTo("{$dir->path}/b.txt");
    $b->moveTo("{$dir->path}/c.txt");

    foreach ($dir->files() as $file) {
        echo $file->name(), "\n";   // a.txt, c.txt
    }

    $dir->delete(recursive: true);
});
```

`copyTo()` and `moveTo()` return a `FileInfo` for the target, so the next step can go on from there.

## Files in a request handler

The handler stores one text file per name and reads it back.

```php
<?php

use FileSystem\{DirectoryInfo, FileInfo};
use Http\{Request, Response};

$dir = new DirectoryInfo(sys_get_temp_dir() . '/tenma-notes');

if (!is_dir($dir->path)) {
    mkdir($dir->path);   // at startup nothing else runs yet: blocking is fine
}

new Http\Server(8080, function (Request $request) use ($dir) {
    // basename() keeps the request inside $dir
    $name = basename($request->path);

    if ($name === '') {
        $names = [];
        foreach ($dir->files('*.txt') as $file) {
            $names[] = $file->name();
        }
        return Response::json($names);
    }

    $file = new FileInfo("{$dir->path}/$name.txt");

    if ($request->method === 'POST') {
        $file->append($request->body . "\n");
        return new Response("saved\n", 201);
    }

    if (!$file->exists()) {
        return new Response("Not found\n", 404);
    }

    return new Response($file->read());
})->start();
```

`curl -d hello localhost:8080/monday` appends a line, `curl localhost:8080/monday` reads the file, and `curl localhost:8080/` lists `monday.txt`.

Never let a request choose a path outside the directory. `basename()` drops every `..` and `/`.

## Handle errors

```php
<?php

use FileSystem\FileInfo;
use Threading\Task;

Task::run(function () {
    try {
        $config = new FileInfo('/etc/example/config.json')->read();
    } catch (RuntimeException $e) {
        echo $e->getMessage(), "\n";   // read(/private/etc/example/config.json): No such file ...
        echo $e->getCode(), "\n";      // 2 (ENOENT)
        $config = '{}';
    }

    echo $config, "\n";
});
```

## Behaviour

- Creating a `FileInfo` or `DirectoryInfo` does no I/O. `name()`, `directory()` and `parent()` only work on the path.
- Only the calling fiber waits. Outside a fiber (at script startup without `Task::run()`), every method that touches the disk throws a `LogicException`.
- PHP's own file functions (`file_get_contents()`, `fopen()`, `file_put_contents()`) block the whole worker. At startup that is fine, because nothing else runs yet.
- Paths behave as with PHP's file functions. They are relative to the working directory (`chdir()`), and `open_basedir` applies. Bytes pass through unchanged, so names need not be valid UTF-8.
- A path with a null byte throws a `ValueError`.
- Errors (missing file, no permission, existing directory) throw a `RuntimeException`. The message names the operation, the path and the reason. The code is `errno`. `exists()` is the way to ask for a file that may be missing.
- `copyTo()` of a file onto itself is an error.
- A running file operation cannot be cancelled. It runs to completion, also when the request is cancelled. Tenma does not exit while one is still running.
- A file larger than what `memory_limit` leaves free becomes an error in the request, not a fatal error.
- Files sent to clients as they are should be [static files](http.md#static-files): that is faster than reading them in PHP. Uploads move with [`UploadedFile::moveTo()`](../reference/Http/UploadedFile.md#moveto).
