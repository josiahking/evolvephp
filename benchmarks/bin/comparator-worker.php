<?php

declare(strict_types=1);

use Evolve\Benchmarks\Comparator\ComparatorScenarioExecutor;

$benchmarkRoot = dirname(__DIR__);

foreach ([
    'ComparatorMatrixException.php',
    'ComparatorMatrix.php',
    'ComparatorFixture.php',
    'PreparedComparatorFixture.php',
    'PreparedScenario.php',
    'PhalconAvailability.php',
    'ComparatorRuntimeIdentity.php',
    'ComparatorScenarioExecutor.php',
] as $file) {
    require_once $benchmarkRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Comparator' . DIRECTORY_SEPARATOR . $file;
}

$options = getopt('', [
    'matrix:',
    'comparator:',
    'scenario:',
    'warmups::',
    'request-count::',
    'sample-index::',
]);

try {
    foreach (['matrix', 'comparator', 'scenario'] as $required) {
        if (!isset($options[$required]) || !is_string($options[$required])) {
            throw new RuntimeException("Missing required --{$required} option.");
        }
    }

    $result = ComparatorScenarioExecutor::run(
        $options['matrix'],
        $options['comparator'],
        $options['scenario'],
        (int) ($options['warmups'] ?? 5),
        (int) ($options['request-count'] ?? 25),
        (int) ($options['sample-index'] ?? 1),
    );

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(($result['availability'] ?? null) === 'failed' ? 1 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
