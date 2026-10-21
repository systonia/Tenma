<?php

namespace Tenma\Internal;

/** The request whose code is running right now; tasks and ops belong to it, exit() ends it. */
final class Current
{
    public static ?int $request = null;
    /** The durable function (Threading\Durable) whose fiber runs right now, by key. */
    public static ?string $durable = null;
    /** A classic request that still has to respond. */
    public static ?int $classic = null;
    /** The classic request ended with an exception. */
    public static bool $failed = false;
}
