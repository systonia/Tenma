<?php
// exit() in a timer ends a script with its status, even while an interval is still running
\Threading\Timer::every(0.01, fn () => null);
\Threading\Timer::after(0.05, fn () => exit(3));
