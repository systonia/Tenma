<?php

namespace Development;

/** The debugger an editor connects to through `tenma --inspect`. */
final class Debugger
{
    /** Stops here when an editor is connected to tenma --inspect. Does nothing otherwise. */
    public static function break(): void
    {
        \tenma_debugger();
    }
}
