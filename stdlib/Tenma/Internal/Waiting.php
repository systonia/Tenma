<?php

namespace Tenma\Internal;

/** Fibers waiting for an op, by op ID, with the request they belong to; plus timers. */
final class Waiting
{
    /** @var array<int, \Fiber> */
    public static array $ops = [];
    /** @var array<int, \Tenma\Timer> */
    public static array $timers = [];
    /** @var array<int, \Tenma\Subscription> */
    public static array $subscriptions = [];
}
