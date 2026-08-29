<?php

declare(strict_types=1);

use Evolve\Benchmarks\Support\BenchmarkEnvironment;

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

$benchmarkRoot = dirname(__DIR__);
$repositoryRoot = dirname($benchmarkRoot);
$output = null;

for ($i = 1; $i < $argc; ++$i) {
    if ($argv[$i] === '--output' && isset($argv[$i + 1])) {
        $output = $argv[++$i];
    }
}

$environment = BenchmarkEnvironment::capture($repositoryRoot);
$json = json_encode($environment, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

if ($output !== null) {
    $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $output);
    $fullPath = str_starts_with($path, $benchmarkRoot) ? $path : $benchmarkRoot . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    $directory = dirname($fullPath);

    if (!is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    file_put_contents($fullPath, $json);
}

echo $json;
