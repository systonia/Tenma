<?php

namespace Acme\Shop;

/** One product line in a cart. */
readonly class Item
{
    public function __construct(public string $name, public int $price) {}

    /** For subclasses: the price with tax. */
    protected function gross(): int
    {
        return $this->price;
    }
}
