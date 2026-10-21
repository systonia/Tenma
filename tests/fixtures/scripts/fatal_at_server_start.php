<?php
// If a server's startup work fails fatally, tenma exits instead of restarting endlessly
\Threading\Task::run(fn () => str_repeat('x', 512 * 1024 * 1024));
(new \Http\Server((int) getenv('PORT'), fn () => "ok\n"))->start();
