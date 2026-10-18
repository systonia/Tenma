<?php

namespace Tenma\Internal;

/** Is the event loop running yet? What the script schedules at startup waits until then. */
final class Loop
{
    public static bool $running = false;
    /** @var list<\Closure(): void> */
    public static array $pending = [];
}
