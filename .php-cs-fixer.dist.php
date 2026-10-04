<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([
        'packages/contracts/src',
        'packages/contracts/tests',
        'packages/database-contracts/src',
        'packages/database-contracts/tests',
        'packages/database-pdo/src',
        'packages/database-pdo/tests',
        'packages/cache-memory/src',
        'packages/cache-memory/tests',
        'packages/session-contracts/src',
        'packages/session-contracts/tests',
        'packages/lock-contracts/src',
        'packages/lock-contracts/tests',
        'packages/queue-contracts/src',
        'packages/queue-contracts/tests',
        'packages/queue-memory/src',
        'packages/queue-memory/tests',
        'packages/storage-contracts/src',
        'packages/storage-contracts/tests',
        'packages/storage-local/src',
        'packages/storage-local/tests',
        'packages/secret-contracts/src',
        'packages/secret-contracts/tests',
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
        'packages/job/src',
        'packages/job/tests',
        'packages/scheduler/src',
        'packages/scheduler/tests',
        'packages/migration/src',
        'packages/migration/tests',
        'packages/view/src',
        'packages/view/tests',
        'packages/view-twig/src',
        'packages/view-twig/tests',
        'packages/view-blade/src',
        'packages/view-blade/tests',
        'packages/i18n/src',
        'packages/i18n/tests',
        'packages/insight/src',
        'packages/insight/tests',
        'packages/observe/src',
        'packages/observe/tests',
        'packages/dev-tools/src',
        'packages/dev-tools/tests',
        'packages/http/src',
        'packages/http/tests',
        'packages/http-client/src',
        'packages/http-client/tests',
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
