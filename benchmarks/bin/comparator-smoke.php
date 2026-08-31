<?php

declare(strict_types=1);

use Evolve\Benchmarks\Comparator\ComparatorSmokeVerifier;

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

$matrixPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'comparators' . DIRECTORY_SEPARATOR . 'matrix.json';
$report = ComparatorSmokeVerifier::verifyMatrixFile($matrixPath);

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

exit(($report['status'] ?? 'failed') === 'passed' ? 0 : 1);
