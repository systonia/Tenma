<?php

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/stdlib', __DIR__ . '/demo'])
    ->exclude('vendor');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS2.0' => true,
        // House style: `fn ($x) =>` with a space, as in Waypoint
        'function_declaration' => ['closure_fn_spacing' => 'one'],
    ])
    ->setFinder($finder);
