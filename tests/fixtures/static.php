<?php
// For tests/http.rs: static files from public/, everything else to the handler
Tenma::serve((int) getenv('PORT'), fn (Tenma\Http\Request $request) => "php: {$request->path}\n", static: __DIR__ . '/public');
