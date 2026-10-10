<?php
// For tests/http.rs: timers and background work in a script without a server, like in Node
$ticks = 0;
$interval = Tenma::setInterval(function () use (&$ticks, &$interval) {
    if (++$ticks === 3) {
        $interval->cancel();
    }
}, 0.02);
Tenma::setTimeout(fn () => print("cancelled must not appear\n"), 0.03)->cancel();
Tenma::async(function () {
    Tenma::sleep(0.05);
    echo "background done\n";
});
Tenma::setTimeout(function () use (&$ticks) {
    echo "ticks $ticks\n";
    Tenma::setTimeout(fn () => print("nested\n"), 0.01);
}, 0.15);
echo "script done\n";
