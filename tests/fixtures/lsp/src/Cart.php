<?php

namespace Fixture;

/** What the page's model holds. */
final class Cart
{
    public Author $owner;

    /** @var list<Author> */
    public array $buyers = [];

    private int $count = 0;

    /** Typed by the provider's elements(), no PHPDoc */
    public array $lines = [];

    public function first(): Author
    {
        return $this->owner;
    }

    public function extras(): array
    {
        return [];
    }
}
