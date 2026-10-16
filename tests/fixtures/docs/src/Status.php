<?php

namespace Acme\Shop;

/** Where an order stands. */
enum Status: string
{
    /** Not paid yet. */
    case Open = 'open';
    case Paid = 'paid';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
