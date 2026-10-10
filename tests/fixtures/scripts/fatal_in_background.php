<?php
// A fatal error in background work ends the script with 255, even if something else is still waiting
Tenma::async(fn () => Tenma::sleep(60));
Tenma::setTimeout(fn () => str_repeat('x', 512 * 1024 * 1024), 0.05);
