<?php

namespace Tenma\Internal;

/** The handlers of Process\Shutdown::register(), run by stopping(). */
final class Shutdown
{
    /** @var list<\Closure(): mixed> */
    public static array $handlers = [];
}
