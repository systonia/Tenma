<?php
// Nonsensical wait times are errors, not a timer that fires at once or every millisecond
foreach ([INF, NAN, -1.0] as $seconds) {
    try {
        \Threading\Timer::after($seconds, fn () => null);
    } catch (ValueError) {
        echo "rejected\n";
    }
}
