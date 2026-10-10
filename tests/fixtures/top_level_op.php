<?php
// For tests/http.rs: asynchronous calls at script start are rejected, tenma keeps running
try {
    Tenma::sleep(0.01);
} catch (LogicException) {
    echo "rejected\n";
}
