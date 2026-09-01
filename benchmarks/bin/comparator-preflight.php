<?php

declare(strict_types=1);

use Evolve\Benchmarks\Comparator\ComparatorPreflight;

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

$benchmarkRoot = dirname(__DIR__);
$repositoryRoot = dirname($benchmarkRoot);
$matrixPath = $benchmarkRoot . DIRECTORY_SEPARATOR . 'comparators' . DIRECTORY_SEPARATOR . 'matrix.json';
$preflight = ComparatorPreflight::current($repositoryRoot, $matrixPath);

echo json_encode($preflight, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
exit(($preflight['status'] ?? 'mismatched') === 'matched' ? 0 : 1);
