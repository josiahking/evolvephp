<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([
        'packages/contracts/src',
        'packages/contracts/tests',
        'packages/bridge-contracts/src',
        'packages/bridge-contracts/tests',
        'packages/bridge-psr/src',
        'packages/bridge-psr/tests',
        'packages/bridge-laravel/src',
        'packages/bridge-laravel/tests',
        'packages/bridge-symfony/src',
        'packages/bridge-symfony/tests',
        'packages/bridge-remote/src',
        'packages/bridge-remote/tests',
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
        'compat/legacy-http-client/src',
        'compat/legacy-http-client/tests',
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
        'trailing_comma_in_multiline' => [
            'elements' => ['arguments', 'arrays', 'match'],
        ],
        'no_unused_imports' => true,
    ])
    ->setRiskyAllowed(false)
    ->setUsingCache(true)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache')
    ->setIndent('    ')
    ->setLineEnding("\n")
    ->setFinder($finder);
