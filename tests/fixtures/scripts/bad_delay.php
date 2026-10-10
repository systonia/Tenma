<?php
// Nonsensical wait times are errors, not a timer that fires at once or every millisecond
foreach ([INF, NAN, -1.0] as $seconds) {
    try {
        Tenma::setTimeout(fn () => null, $seconds);
    } catch (ValueError) {
        echo "rejected\n";
    }
}
