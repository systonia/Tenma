<?php

namespace Fixture;

final class Page
{
    public string $title = '';

    private Author $author;

    /** Counts up by one. */
    public function increment(int $by): void
    {
    }

    private function secret(): void
    {
    }

    public static function make(): self
    {
        return new self();
    }
}
