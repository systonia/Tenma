<?php

namespace Tenma\Internal;

/** Fibers waiting for an op, by op ID, with the request they belong to; plus timers. */
final class Waiting
{
    /** @var array<int, \Fiber> */
    public static array $ops = [];
    /** @var array<int, \Threading\Timer> */
    public static array $timers = [];
    /** @var array<int, \Messaging\Subscription> */
    public static array $subscriptions = [];
}
