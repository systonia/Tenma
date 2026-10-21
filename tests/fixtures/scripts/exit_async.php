<?php
// exit() in background work: the status counts, even if the last op ends with it
\Threading\Task::run(fn () => exit(3));
