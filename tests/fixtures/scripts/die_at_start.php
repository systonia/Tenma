<?php
// die() at startup: scheduled timers never start
\Threading\Timer::every(0.01, fn () => print("must not run\n"));
die("Usage: die_at_start.php <file>\n");
