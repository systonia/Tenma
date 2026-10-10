<?php
// For tests/http.rs: a script like for the PHP CLI
fwrite(STDOUT, "to stdout\n");
fwrite(STDERR, "to stderr\n");
echo 'input: ', trim(fgets(STDIN)), "\n";
