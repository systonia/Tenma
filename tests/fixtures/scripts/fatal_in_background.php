<?php
// A fatal error in background work ends the script with 255, even if something else is still waiting
\Threading\Task::run(fn () => \Threading\Task::delay(60));
\Threading\Timer::after(0.05, fn () => str_repeat('x', 512 * 1024 * 1024));
