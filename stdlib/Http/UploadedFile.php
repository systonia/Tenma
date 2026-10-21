<?php

namespace Http;

/** An uploaded file, entirely in memory (up to --max-body). */
final class UploadedFile
{
    public function __construct(
        /** The file name from the client, without directory; never use it unchecked as a path. */
        public readonly string $name,
        /** The type the client states; not trustworthy. */
        public readonly string $type,
        private readonly string $contents,
    ) {}

    public function size(): int
    {
        return strlen($this->contents);
    }

    public function contents(): string
    {
        return $this->contents;
    }

    /** Writes the file to $path without blocking the server. */
    public function moveTo(string $path): void
    {
        (new \FileSystem\FileInfo($path))->write($this->contents);
    }
}
