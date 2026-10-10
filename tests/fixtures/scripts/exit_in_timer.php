<?php
// exit() in a timer ends a script with its status, even while an interval is still running
Tenma::setInterval(fn () => null, 0.01);
Tenma::setTimeout(fn () => exit(3), 0.05);
