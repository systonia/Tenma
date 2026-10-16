<?php

namespace Fixture\Shop;

/** Where an order stands. */
enum OrderStatus: string
{
    case Open = 'open';
    case Paid = 'paid';

    public const DEFAULT = self::Open;

    public static function label(self $status): string
    {
        return ucfirst($status->value);
    }

    public function isPaid(): bool
    {
        return $this === self::Paid;
    }
}
