<?php
// die() at startup: scheduled timers never start
Tenma::setInterval(fn () => print("must not run\n"), 0.01);
die("Usage: die_at_start.php <file>\n");
