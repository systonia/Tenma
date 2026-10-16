<?php

namespace Acme\Shop;

/** Something with a price. */
interface Priced
{
    /** The total in cents. */
    public function total(): int;
}
