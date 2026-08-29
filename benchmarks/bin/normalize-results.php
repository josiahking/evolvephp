<?php

declare(strict_types=1);

use Evolve\Benchmarks\Support\BenchmarkEnvironment;
use Evolve\Benchmarks\Support\ResultNormalizer;

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

$benchmarkRoot = dirname(__DIR__);
$repositoryRoot = dirname($benchmarkRoot);
$input = null;
$environmentPath = null;
$output = null;

for ($i = 1; $i < $argc; ++$i) {
    match ($argv[$i]) {
        '--input' => $input = $argv[++$i] ?? null,
        '--environment' => $environmentPath = $argv[++$i] ?? null,
        '--output' => $output = $argv[++$i] ?? null,
        default => null,
    };
}

if ($input === null) {
    fwrite(STDERR, 'Usage: php bin/normalize-results.php --input <phpbench.xml|raw.json> [--environment environment.json] [--output normalized.json]' . PHP_EOL);
    exit(1);
}

$environment = $environmentPath !== null && is_file($environmentPath)
    ? json_decode((string) file_get_contents($environmentPath), true, flags: JSON_THROW_ON_ERROR)
    : BenchmarkEnvironment::capture($repositoryRoot);

if (!is_array($environment)) {
    fwrite(STDERR, 'Environment file must decode to an object.' . PHP_EOL);
    exit(1);
}

$extension = strtolower(pathinfo($input, PATHINFO_EXTENSION));
$normalized = $extension === 'xml'
    ? ResultNormalizer::fromPhpBenchXml($input, $environment)
    : ResultNormalizer::normalize(json_decode((string) file_get_contents($input), true, flags: JSON_THROW_ON_ERROR));

$json = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

if ($output !== null) {
    $directory = dirname($output);

    if (!is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    file_put_contents($output, $json);
}

echo $json;
