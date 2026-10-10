<?php
// exit() in background work: the status counts, even if the last op ends with it
Tenma::async(fn () => exit(3));
