<?php

namespace FileSystem;

/**
 * A file by its path, without blocking the server: the work runs on background threads, only the
 * Fiber waits. Creating the object does no I/O; every method asks the file system then. Paths as
 * with PHP's file functions (relative to chdir(), open_basedir applies). Errors (missing, no
 * permission) throw RuntimeException with op, path and reason, errno as the code; exists() is the
 * way to ask for a file that may be missing. A running file op cannot be cancelled; it runs to
 * completion. Only in asynchronous code (handlers, tasks, timers); file_get_contents() & co. block
 * the worker.
 */
final class FileInfo
{
    public function __construct(public readonly string $path) {}

    /** The name without the directory. */
    public function name(): string
    {
        return basename($this->path);
    }

    public function directory(): DirectoryInfo
    {
        return new DirectoryInfo(dirname($this->path));
    }

    /** Like file_exists(): a missing permission means false, not an exception. */
    public function exists(): bool
    {
        return \Tenma\Internal\fs('exists', $this->path);
    }

    public function read(): string
    {
        return \Tenma\Internal\fs('read', $this->path);
    }

    /** Writes $data, replacing the file; the directory must exist. */
    public function write(string $data): void
    {
        \Tenma\Internal\fs('write', $this->path, data: $data);
    }

    /** Appends $data in one write, creating the file if it is missing. */
    public function append(string $data): void
    {
        \Tenma\Internal\fs('append', $this->path, data: $data);
    }

    public function size(): int
    {
        return \Tenma\Internal\fs('stat', $this->path)['size'];
    }

    /** The modification time as a Unix timestamp with fractions of a second. */
    public function modified(): float
    {
        return \Tenma\Internal\fs('stat', $this->path)['modified'];
    }

    /** Copies the file to $target and returns it; copying a file onto itself is an error. */
    public function copyTo(string $target): self
    {
        \Tenma\Internal\fs('copy', $this->path, $target);
        return new self($target);
    }

    /** Moves the file to $target, also across file system boundaries, and returns it. */
    public function moveTo(string $target): self
    {
        \Tenma\Internal\fs('rename', $this->path, $target);
        return new self($target);
    }

    public function delete(): void
    {
        \Tenma\Internal\fs('remove', $this->path);
    }
}
