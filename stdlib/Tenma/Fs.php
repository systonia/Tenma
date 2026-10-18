<?php

namespace Tenma;

/** Asynchronous file system functions: only the Fiber waits while the work runs on a background thread. */
final class Fs
{
    /**
     * Files and directories, without blocking the server: the work runs on background threads,
     * only the Fiber waits. Paths as with PHP's file functions (relative to chdir(), open_basedir applies).
     * Errors (missing, no permission) throw RuntimeException with op, path and reason, errno as code.
     * A running file op cannot be cancelled; it runs to completion.
     * Only in asynchronous code (handlers, tasks, timers); file_get_contents & co. block the worker.
     */
    public static function readFile(string $path): string
    {
        return self::call('read', $path);
    }

    public static function writeFile(string $path, string $data): void
    {
        self::call('write', $path, data: $data);
    }

    public static function appendFile(string $path, string $data): void
    {
        self::call('append', $path, data: $data);
    }

    public static function exists(string $path): bool
    {
        return self::call('exists', $path);
    }

    /** @return array{size: int, modified: float, isFile: bool, isDir: bool} */
    public static function stat(string $path): array
    {
        return self::call('stat', $path);
    }

    /** @return list<string> Names in the directory, sorted, without . and .. */
    public static function readDir(string $path): array
    {
        return self::call('readDir', $path);
    }

    /** Like mkdir() in PHP: without $recursive, an error if the directory already exists. */
    public static function mkdir(string $path, bool $recursive = false): void
    {
        self::call('mkdir', $path, flag: $recursive);
    }

    /** Also across file system boundaries, then like PHP rename() as copy and delete. */
    public static function rename(string $from, string $to): void
    {
        self::call('rename', $from, $to);
    }

    /** @return int bytes copied; copying a file onto itself is an error */
    public static function copy(string $from, string $to): int
    {
        return self::call('copy', $from, $to);
    }

    public static function remove(string $path): void
    {
        self::call('remove', $path);
    }

    /** An empty directory; with $recursive, also with its contents. */
    public static function removeDir(string $path, bool $recursive = false): void
    {
        self::call('removeDir', $path, flag: $recursive);
    }

    private static function call(string $op, string $path, string $second = '', bool $flag = false, string $data = ''): mixed
    {
        \Tenma\Internal\requireFiber();
        $result = \Tenma\Internal\await(\tenma_op_fs($op, $path, $second, $flag, $data));
        return match ($op) {
            'read' => $result,
            // Names as bytes, separated by null bytes: file names need not be valid UTF-8
            'readDir' => $result === '' ? [] : explode("\0", $result),
            'exists', 'stat', 'copy' => json_decode($result, true, flags: JSON_THROW_ON_ERROR),
            default => null,
        };
    }
}
