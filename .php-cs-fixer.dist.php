<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([
        'packages/contracts/src',
        'packages/contracts/tests',
        'packages/core/src',
        'packages/core/tests',
        'packages/dev-tools/src',
        'packages/dev-tools/tests',
        'packages/http/src',
        'packages/http/tests',
        'packages/module/src',
        'packages/module/tests',
        'packages/plugin/src',
        'packages/plugin/tests',
        'packages/testing/src',
        'packages/testing/tests',
        'skeleton/bootstrap',
        'skeleton/config',
    ]);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS3x0' => true,
        'ordered_imports' => [
            'imports_order' => ['class', 'function', 'const'],
            'sort_algorithm' => 'alpha',
        ],
        'no_unused_imports' => true,
    ])
    ->setRiskyAllowed(false)
    ->setUsingCache(true)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache')
    ->setIndent('    ')
    ->setLineEnding("\n")
    ->setFinder($finder);
