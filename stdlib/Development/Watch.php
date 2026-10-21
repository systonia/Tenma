<?php

namespace Development;

/** What `tenma --watch` tells the script about its reloads. */
final class Watch
{
    /**
     * The file whose change made the script run again, null on the first run and without
     * --watch. A framework can tell from it whether only a part of the app changed.
     */
    public static function reloadedBy(): ?string
    {
        return \tenma_reloaded_by();
    }
}
