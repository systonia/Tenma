<?php

namespace FileSystem;

/**
 * A directory by its path, without blocking the server: the work runs on background threads, only
 * the Fiber waits. Creating the object does no I/O; every method asks the file system then. Errors
 * throw RuntimeException with op, path and reason, errno as the code. Only in asynchronous code
 * (handlers, tasks, timers).
 */
final class DirectoryInfo
{
    public function __construct(public readonly string $path) {}

    /** The name without the parent directory. */
    public function name(): string
    {
        return basename($this->path);
    }

    public function parent(): self
    {
        return new self(dirname($this->path));
    }

    /** Like file_exists(): a missing permission means false, not an exception. */
    public function exists(): bool
    {
        return \Tenma\Internal\fs('exists', $this->path);
    }

    /** Creates the directory and, with $recursive, the missing ones above it; an error if it already exists. */
    public function create(bool $recursive = false): void
    {
        \Tenma\Internal\fs('mkdir', $this->path, flag: $recursive);
    }

    /** The modification time as a Unix timestamp with fractions of a second. */
    public function modified(): float
    {
        return \Tenma\Internal\fs('stat', $this->path)['modified'];
    }

    /**
     * The files in the directory, sorted by name, those matching $pattern (as fnmatch(): * and ?
     * within a name). Symbolic links count as what they point to.
     *
     * @return list<self|FileInfo>
     */
    public function files(string $pattern = '*'): array
    {
        $files = [];
        foreach ($this->entries() as [$kind, $name]) {
            if ($kind === 'f' && fnmatch($pattern, $name)) {
                $files[] = new FileInfo($this->path . '/' . $name);
            }
        }
        return $files;
    }

    /**
     * The directories in the directory, sorted by name, those matching $pattern.
     *
     * @return list<self>
     */
    public function directories(string $pattern = '*'): array
    {
        $directories = [];
        foreach ($this->entries() as [$kind, $name]) {
            if ($kind === 'd' && fnmatch($pattern, $name)) {
                $directories[] = new self($this->path . '/' . $name);
            }
        }
        return $directories;
    }

    /** Moves the directory to $target, on the same file system, and returns it. */
    public function moveTo(string $target): self
    {
        \Tenma\Internal\fs('rename', $this->path, $target);
        return new self($target);
    }

    /** Deletes an empty directory; with $recursive, also with its contents. */
    public function delete(bool $recursive = false): void
    {
        \Tenma\Internal\fs('removeDir', $this->path, flag: $recursive);
    }

    /** @return list<array{string, string}> Kind (f, d or o for other) and name, sorted by name. */
    private function entries(): array
    {
        $entries = [];
        foreach (\Tenma\Internal\fs('readDir', $this->path) as $entry) {
            $entries[] = [$entry[0], substr($entry, 1)];
        }
        return $entries;
    }
}
