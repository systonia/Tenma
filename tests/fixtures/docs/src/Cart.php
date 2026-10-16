<?php

namespace Acme\Shop;

use Acme\Shop\Internal\Ledger;
use InvalidArgumentException as Invalid;

/**
 * A shopping cart. It holds `Item`s and knows their total.
 *
 * ```php
 * $cart = new Cart('EUR');
 * $cart->add(new Item('book', 1200));
 * ```
 *
 * @template TItem of Item
 */
final class Cart implements Priced, \Countable
{
    use HasItems;

    /** Most items a cart may hold. */
    public const int LIMIT = 100;

    /** @internal */
    public const SECRET = 'x';

    /** When the cart was last changed, a Unix timestamp. */
    public int $changed = 0;

    public string $label {
        get => "Cart in $this->currency";
    }

    /**
     * @param string $currency ISO 4217 code, like EUR
     */
    public function __construct(public readonly string $currency = 'EUR') {}

    /**
     * Adds an item.
     *
     * @param TItem $item the item to add
     * @return $this
     * @throws Invalid when the cart is full
     */
    public function add(Item $item, int $quantity = 1): static
    {
        return $this;
    }

    public function total(): int
    {
        return 0;
    }

    public function count(): int
    {
        return 0;
    }

    /** @internal */
    public function ledger(): Ledger
    {
        return new Ledger();
    }

    private function secret(): void {}
}
