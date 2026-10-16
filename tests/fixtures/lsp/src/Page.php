<?php // The page class of the fixture, before its use statements

namespace Fixture;

use Fixture\People\Writer as Pen;

final class Page
{
    public string $title = '';

    private Author $author;

    /** @var list<Author> */
    public array $authors = [];

    /** @var array<int, Pen> */
    public array $pens = [];

    /** @var list<string> */
    public array $tags = [];

    /** Counts up by one. */
    public function increment(int $by): void
    {
    }

    private function secret(): void
    {
    }

    /** @return Author[] */
    public function team(): array
    {
        return [];
    }

    public static function make(): self
    {
        return new self();
    }
}
