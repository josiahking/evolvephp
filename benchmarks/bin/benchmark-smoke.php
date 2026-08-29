<?php

declare(strict_types=1);

use Evolve\Benchmarks\Support\BenchmarkEnvironment;
use Evolve\Benchmarks\Support\ResultNormalizer;

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

$benchmarkRoot = dirname(__DIR__);
$repositoryRoot = dirname($benchmarkRoot);
$resultDirectory = $benchmarkRoot . DIRECTORY_SEPARATOR . 'results' . DIRECTORY_SEPARATOR . 'local' . DIRECTORY_SEPARATOR . 'smoke';

if (!is_dir($resultDirectory)) {
    mkdir($resultDirectory, 0777, true);
}

$environment = BenchmarkEnvironment::capture($repositoryRoot);
file_put_contents(
    $resultDirectory . DIRECTORY_SEPARATOR . 'environment.json',
    json_encode($environment, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
);

$xmlPath = $resultDirectory . DIRECTORY_SEPARATOR . 'phpbench.xml';
$command = [
    PHP_BINARY,
    $benchmarkRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpbench',
    'run',
    '--config=' . $benchmarkRoot . DIRECTORY_SEPARATOR . 'phpbench.json',
    '--filter=RouteMatcherBench::benchRouteMatching',
    '--variant=static-10-first',
    '--revs=2',
    '--iterations=2',
    '--warmup=1',
    '--report=aggregate',
    '--dump-file=' . $xmlPath,
    '--no-interaction',
];
$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $benchmarkRoot);

if (!is_resource($process)) {
    fwrite(STDERR, 'Unable to start PHPBench smoke run.' . PHP_EOL);
    exit(1);
}

$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);

if ($exitCode !== 0) {
    fwrite(STDERR, trim((string) $stdout . PHP_EOL . (string) $stderr) . PHP_EOL);
    exit($exitCode);
}

$normalized = ResultNormalizer::fromPhpBenchXml($xmlPath, $environment);

if (($normalized['scenarios'] ?? []) === []) {
    fwrite(STDERR, 'Smoke run produced no normalized scenarios.' . PHP_EOL);
    exit(1);
}

file_put_contents(
    $resultDirectory . DIRECTORY_SEPARATOR . 'normalized-results.json',
    json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
);

echo 'Benchmark smoke OK: ' . count($normalized['scenarios']) . ' normalized scenario(s)' . PHP_EOL;
