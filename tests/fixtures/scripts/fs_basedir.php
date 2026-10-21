<?php
// For tests/http.rs: open_basedir also applies to FileSystem\FileInfo
\Threading\Task::run(function () {
    try {
        (new FileSystem\FileInfo('/etc/passwd'))->read();
        echo "read\n";
    } catch (RuntimeException $error) {
        echo str_contains($error->getMessage(), 'open_basedir') ? "forbidden\n" : $error->getMessage();
    }
});
