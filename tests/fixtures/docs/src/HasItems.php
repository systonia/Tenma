<?php

namespace Acme\Shop;

/** Keeps a list of items. */
trait HasItems
{
    /** @var list<Item> */
    protected array $items = [];

    /** @return list<Item> */
    public function items(): array
    {
        return $this->items;
    }
}
