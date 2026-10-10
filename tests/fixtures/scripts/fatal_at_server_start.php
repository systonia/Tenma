<?php
// If a server's startup work fails fatally, tenma exits instead of restarting endlessly
Tenma::async(fn () => str_repeat('x', 512 * 1024 * 1024));
Tenma::serve((int) getenv('PORT'), fn () => "ok\n");
