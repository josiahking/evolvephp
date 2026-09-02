<?php

declare(strict_types=1);

use Evolve\Benchmarks\Comparator\ComparatorExecutionRunner;

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

$benchmarkRoot = dirname(__DIR__);
$options = getopt('', [
    'matrix::',
    'comparator::',
    'scenario::',
    'output::',
    'samples::',
    'warmups::',
    'request-count::',
]);

$matrixPath = isset($options['matrix']) && is_string($options['matrix'])
    ? $options['matrix']
    : $benchmarkRoot . DIRECTORY_SEPARATOR . 'comparators' . DIRECTORY_SEPARATOR . 'matrix.json';
$outputDir = isset($options['output']) && is_string($options['output'])
    ? $options['output']
    : $benchmarkRoot . DIRECTORY_SEPARATOR . 'results' . DIRECTORY_SEPARATOR . 'local' . DIRECTORY_SEPARATOR . 'comparator-candidate';

try {
    $runner = new ComparatorExecutionRunner(PHP_BINARY);
    $manifest = $runner->run($matrixPath, $outputDir, [
        'comparators' => $options['comparator'] ?? 'all',
        'scenarios' => $options['scenario'] ?? 'all',
        'samples' => (int) ($options['samples'] ?? 100),
        'warmups' => (int) ($options['warmups'] ?? 5),
        'request_count' => (int) ($options['request-count'] ?? 25),
    ]);

    echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

    if (($manifest['status'] ?? 'failed') !== 'completed') {
        exit(1);
    }

    foreach ($manifest['results'] as $result) {
        if (($result['availability'] ?? null) === 'failed' || ($result['exit_code'] ?? 1) !== 0) {
            exit(1);
        }
    }

    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
